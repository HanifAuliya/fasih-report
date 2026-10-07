<?php

namespace App\Services;

use App\Models\Kecamatan;
use App\Models\Project;
use App\Models\ReportFile;
use App\Support\ProjectSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Simpan file yang diupload, lalu proses otomatis:
 * Excel target -> isi tabel target kecamatan, laporan JSON/CSV -> update status baris.
 */
class ReportFileProcessor
{
    public function __construct(
        private TargetImporter $targetImporter,
        private UnitReportService $unitReports,
    ) {}

    /**
     * @param  string  $category  kategori atau "auto"
     * @param  string  $kecamatan  id kecamatan, "auto" (tebak dari nama file) atau "" (tanpa)
     */
    public function store(Project $project, UploadedFile $upload, string $category, string $kecamatan, ?string $notes, ?int $userId): ReportFile
    {
        $original = $upload->getClientOriginalName();
        $extension = strtolower($upload->getClientOriginalExtension());
        $stored = Str::random(8).'_'.Str::slug(pathinfo($original, PATHINFO_FILENAME)).'.'.$extension;
        $kecamatans = $project->kecamatans()->get();

        return $project->files()->create([
            'kecamatan_id' => match ($kecamatan) {
                'auto' => $this->guessUnit($project, $original, $extension, $kecamatans)?->id,
                '' => null,
                default => $kecamatans->firstWhere('id', (int) $kecamatan)?->id,
            },
            'category' => $category === 'auto' ? $this->guessCategory($original, $extension) : $category,
            'original_name' => $original,
            'path' => $upload->storeAs("reports/{$project->id}", $stored, 'local'),
            'extension' => $extension,
            'size' => $upload->getSize(),
            'notes' => $notes ?: null,
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Proses file sesuai jenisnya. Hasilnya disimpan di kolom summary & dikembalikan.
     */
    public function process(ReportFile $file): ?string
    {
        try {
            $summary = match (true) {
                $file->category === 'induk' && $file->extension === 'xlsx' => app(MasterWorkbook::class)->import($file),
                $this->isTargetWorkbook($file) => $this->importTarget($file),
                $this->isStatusReport($file) => $this->unitReports->activate($file),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);
            $summary = 'Gagal diproses: '.$e->getMessage();
        }

        if ($summary !== null) {
            $file->update(['summary' => $summary]);
        }

        return $summary;
    }

    public function isTargetWorkbook(ReportFile $file): bool
    {
        return in_array($file->category, ['target', 'bagian'], true) && $file->extension === 'xlsx' && $file->kecamatan_id !== null;
    }

    /**
     * Laporan hanya diproses jika sudah terhubung ke satu unit (diupload dari halaman unit).
     */
    public function isStatusReport(ReportFile $file): bool
    {
        return $file->isStatusReport() && $file->kecamatan_id !== null;
    }

    private function importTarget(ReportFile $file): string
    {
        $result = $this->targetImporter->import($file, $file->kecamatan);

        return "{$file->kecamatan->nama}: {$result['sheets']} sheet, {$result['rows']} baris ({$result['tracked']} diproses script)"
            .($result['kept'] ? ", {$result['kept']} status lama dipertahankan" : '');
    }

    public function guessCategory(string $filename, string $extension): string
    {
        $name = strtolower($filename);

        return match (true) {
            in_array($extension, ['json', 'csv'], true) => 'report',
            str_contains($name, 'bagian') => 'bagian',
            str_contains($name, 'target') || in_array($extension, ['xlsx', 'xls'], true) => 'target',
            default => 'lainnya',
        };
    }

    /**
     * Tentukan unit dari nama file, sesuai pengaturan data:
     * - unit kecamatan: cocokkan ke daftar kecamatan ("target_OSS_010_HARUYAN.xlsx");
     * - setiap file jadi unit: "Bagian 01 (…).xlsx" -> BAGIAN 01, selain itu nama file (mis. "PERUBAHAN 27A").
     *   Unit baru hanya dibuat dari Excel; laporan JSON/CSV dicocokkan ke unit yang sudah ada.
     *
     * @param  Collection<int, Kecamatan>  $kecamatans
     */
    public function guessUnit(Project $project, string $filename, string $extension, Collection $kecamatans): ?Kecamatan
    {
        if ($project->config()->unitSource() === ProjectSettings::UNIT_KECAMATAN) {
            return $this->guessKecamatan($filename, $kecamatans)
                ?? ($extension === 'xlsx' ? $this->createDefaultKecamatan($project, $filename) : null);
        }

        $nextKode = fn () => str_pad((string) ((int) $kecamatans->max(fn (Kecamatan $unit) => (int) $unit->kode) + 1), 2, '0', STR_PAD_LEFT);

        if (preg_match('/bagian\s*0*(\d+)\s*(\(([^)]*)\))?/i', $filename, $match)) {
            $kode = str_pad($match[1], 2, '0', STR_PAD_LEFT);
            $nama = 'BAGIAN '.$kode;

            // Cocokkan lewat nama unit, bukan kode: kode bisa sudah dipakai unit lain
            // (mis. unit dari file "Rekap ….xlsx" yang mendapat kode berikutnya)
            $unit = $kecamatans->first(fn (Kecamatan $unit) => str_starts_with(mb_strtoupper(trim($unit->nama)), $nama));

            // Laporan boleh jatuh ke kode (unit salah akan ditolak karena tidak ada baris cocok);
            // Excel tidak, supaya tidak menimpa isi unit lain
            if ($unit || $extension !== 'xlsx') {
                return $unit ?? $kecamatans->firstWhere('kode', $kode);
            }

            $kode = $kecamatans->contains('kode', $kode) ? $nextKode() : $kode;
            $attributes = ['nama' => $nama, 'catatan' => $match[3] ?? null];
        } elseif ($extension === 'xlsx') {
            $name = mb_strtoupper(mb_substr(trim(preg_replace('/[\s_]+/', ' ', pathinfo($filename, PATHINFO_FILENAME))), 0, 100));

            if ($unit = $kecamatans->firstWhere('nama', $name)) {
                return $unit;
            }

            $kode = $nextKode();
            $attributes = ['nama' => $name, 'catatan' => null];
        } else {
            return null;
        }

        return $project->kecamatans()->create(['kode' => $kode, ...$attributes]);
    }

    /**
     * Tebak kecamatan dari nama file, cth. "target_OSS_010_HARUYAN.xlsx".
     *
     * @param  Collection<int, Kecamatan>  $kecamatans
     */
    public function guessKecamatan(string $filename, Collection $kecamatans): ?Kecamatan
    {
        $normalized = strtoupper(str_replace(['_', '-', '.'], ' ', $filename));

        // Nama terpanjang dulu supaya "BATANG ALAI SELATAN" tidak tertangkap sebagai "BATANG ALAI"
        foreach ($kecamatans->sortByDesc(fn (Kecamatan $kecamatan) => strlen($kecamatan->nama)) as $kecamatan) {
            if (str_contains($normalized, strtoupper($kecamatan->nama))) {
                return $kecamatan;
            }
        }

        foreach ($kecamatans as $kecamatan) {
            if (preg_match('/(^|\D)'.preg_quote($kecamatan->kode, '/').'(\D|$)/', $normalized)) {
                return $kecamatan;
            }
        }

        return null;
    }

    /**
     * Excel untuk kecamatan yang belum ada di pekerjaan ini: buat dari daftar kecamatan default
     * (config fasih.kecamatans) bila nama/kodenya dikenali dari nama file.
     */
    private function createDefaultKecamatan(Project $project, string $filename): ?Kecamatan
    {
        $defaults = collect(config('fasih.kecamatans'))
            ->map(fn (string $nama, string|int $kode) => new Kecamatan(['kode' => (string) $kode, 'nama' => $nama]))
            ->values();

        $known = $this->guessKecamatan($filename, $defaults);

        return $known
            ? $project->kecamatans()->firstOrCreate(['kode' => $known->kode], ['nama' => $known->nama])
            : null;
    }
}
