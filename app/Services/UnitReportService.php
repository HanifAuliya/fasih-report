<?php

namespace App\Services;

use App\Models\Kecamatan;
use App\Models\ReportFile;
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

                // Laporan unit lain / format salah: batalkan, laporan aktif sebelumnya tetap dipakai
                if ($result['matched'] === 0) {
                    throw new RuntimeException("tidak ada baris yang cocok dengan {$unit->nama}. Pastikan laporan ini memang untuk {$unit->nama}.");
                }

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
            .($statuses ? " · {$statuses}" : '');
    }
}
