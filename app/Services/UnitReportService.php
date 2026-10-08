<?php

namespace App\Services;

use App\Models\Kecamatan;
use App\Models\ReportFile;
use App\Support\ProjectSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Laporan JSON/CSV per unit (kecamatan / Bagian / file).
 *
 * Tiap unit punya riwayat laporan dan tepat satu laporan "aktif". Status baris unit selalu
 * = status awal dari Excel + laporan aktif, jadi mengganti atau menghapus laporan aktif
 * akan menghitung ulang status unit itu saja.
 */
class UnitReportService
{
    public function __construct(
        private TargetImporter $targetImporter,
        private StatusReportImporter $statusImporter,
    ) {}

    /**
     * Simpan laporan baru untuk unit lalu jadikan aktif.
     */
    public function upload(Kecamatan $unit, UploadedFile $upload, ?int $userId): ReportFile
    {
        $original = $upload->getClientOriginalName();
        $extension = strtolower($upload->getClientOriginalExtension());
        $stored = Str::random(8).'_'.Str::slug(pathinfo($original, PATHINFO_FILENAME)).'.'.$extension;

        $file = $unit->project->files()->create([
            'kecamatan_id' => $unit->id,
            'category' => 'report',
            'original_name' => $original,
            'path' => $upload->storeAs("reports/{$unit->project_id}", $stored, 'local'),
            'extension' => $extension,
            'size' => $upload->getSize(),
            'uploaded_by' => $userId,
        ]);

        $this->activate($file);

        return $file->refresh();
    }

    /**
     * Jadikan laporan ini aktif: status unit di-reset ke status awal Excel, lalu laporan diterapkan.
     */
    public function activate(ReportFile $file): string
    {
        $unit = $file->kecamatan;

        try {
            $summary = DB::transaction(function () use ($file, $unit) {
                $unit->reports()->where('id', '!=', $file->id)->update(['is_active' => false]);
                $this->targetImporter->resetStatuses($unit);
                $result = $this->statusImporter->import($file, $unit);

                // Kunci baris mungkin masih dari pengaturan lama: hitung ulang lalu coba sekali lagi
                if ($result['matched'] === 0 && $result['records'] > 0 && $this->targetImporter->rekey($unit) > 0) {
                    $this->targetImporter->resetStatuses($unit);
                    $result = $this->statusImporter->import($file, $unit);
                }

                // Laporan unit lain / format salah: batalkan, laporan aktif sebelumnya tetap dipakai
                if ($result['matched'] === 0 && $result['forwarded'] === []) {
                    throw new RuntimeException($this->noMatchReason($result, $unit));
                }

                // Hasil laporan unit lain untuk baris milik unit ini (diteruskan) dipasang lagi setelah reset
                $unit->project->kecamatans()->whereKeyNot($unit->id)->get()->each(function (Kecamatan $other) use ($unit) {
                    if ($report = $other->reports()->where('is_active', true)->first()) {
                        $this->statusImporter->applyTo($report, $unit);
                    }
                });

                return $this->describe($result, $unit);
            });

            $file->update(['is_active' => true, 'summary' => $summary]);
        } catch (Throwable $e) {
            report($e);
            $summary = 'Gagal diproses: '.$e->getMessage();
            $file->update(['is_active' => false, 'summary' => $summary]);
        }

        return $summary;
    }

    /**
     * Hapus laporan. Jika laporan aktif yang dihapus, laporan sebelumnya otomatis jadi aktif
     * (atau status kembali ke awal jika tidak ada laporan lain).
     */
    public function delete(ReportFile $file): void
    {
        $unit = $file->kecamatan;
        $wasActive = $file->is_active;

        $file->delete();

        if ($unit && $wasActive) {
            $this->restoreActive($unit);
        }
    }

    /**
     * Terapkan ulang laporan aktif unit (dipakai setelah Excel/pengaturan diproses ulang).
     */
    public function reapply(Kecamatan $unit): ?string
    {
        $active = $unit->reports()->where('is_active', true)->first();

        if ($active) {
            return $this->activate($active);
        }

        $this->targetImporter->resetStatuses($unit);

        return null;
    }

    private function restoreActive(Kecamatan $unit): void
    {
        $previous = $unit->reports()
            ->where(fn ($query) => $query->whereNull('summary')->orWhere('summary', 'not like', 'Gagal%'))
            ->first();

        if ($previous) {
            $this->activate($previous);

            return;
        }

        $this->targetImporter->resetStatuses($unit);
    }

    /**
     * @param  array{records: int, matched: int, updated: int, skipped: int, unmatched: int, statuses: array<string, int>}  $result
     */
    private function describe(array $result, Kecamatan $unit): string
    {
        $statusSet = $unit->project->config()->statuses();

        $statuses = collect($result['statuses'])
            ->map(fn (int $count, string $status) => $statusSet->label($status)." {$count}")
            ->implode(', ');

        return "{$result['records']} baris, {$result['matched']} cocok, {$result['updated']} diperbarui"
            .($result['skipped'] ? ", {$result['skipped']} dilewati" : '')
            .($result['unmatched'] ? ", {$result['unmatched']} tidak ditemukan di {$unit->nama}" : '')
            .($result['forwarded'] ? ', diteruskan ke '.collect($result['forwarded'])->map(fn (int $count, string $name) => "{$name} {$count}")->implode(', ') : '')
            .($statuses ? " · {$statuses}" : '')
            .($result['parse']['unknown_statuses'] ? ' · Diabaikan karena status belum dikenal: '.$this->unknownStatuses($result) : '');
    }

    /**
     * Penjelasan spesifik kenapa tidak ada satu baris pun yang diterapkan.
     *
     * @param  array{records: int, unit: string, parse: array{items: int, without_key: int, unknown_statuses: array<string, int>, sample_keys: list<string>}}  $result
     */
    private function noMatchReason(array $result, Kecamatan $unit): string
    {
        $parse = $result['parse'];
        $settings = $unit->project->config();

        if ($parse['items'] === 0) {
            return 'laporan kosong atau formatnya tidak dikenali (butuh JSON berisi daftar baris, mis. "queue", atau CSV).';
        }

        if ($result['records'] === 0 && $parse['unknown_statuses'] !== []) {
            return 'status di laporan belum dikenal: '.$this->unknownStatuses($result)
                .'. Tambahkan sebagai kode atau alias status di tab Pengaturan, lalu upload ulang.';
        }

        if ($result['records'] === 0) {
            $fields = $settings->keyMode() === ProjectSettings::KEY_COLUMN
                ? implode(', ', $settings->reportKeyFields())
                : 'row / baris_excel';

            return "kolom kunci ({$fields}) tidak ditemukan di laporan. Sesuaikan \"Kolom kunci di laporan\" di tab Pengaturan.";
        }

        return "tidak ada baris yang cocok dengan {$unit->nama} ({$result['records']} baris laporan, contoh kunci: "
            .implode(', ', $parse['sample_keys'])."). Pastikan laporan ini memang untuk {$unit->nama}.";
    }

    /**
     * @param  array{parse: array{unknown_statuses: array<string, int>}}  $result
     */
    private function unknownStatuses(array $result): string
    {
        return collect($result['parse']['unknown_statuses'])
            ->map(fn (int $count, string $status) => "{$status} ({$count})")
            ->implode(', ');
    }
}
