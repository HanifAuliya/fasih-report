<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TargetRow;
use Illuminate\Http\Response;

/**
 * Download JSON status terkini satu unit (hasil laporan + perubahan manual di web),
 * dengan format yang sama seperti laporan script sehingga bisa diupload ulang.
 */
class UnitStatusJsonController extends Controller
{
    public function __invoke(Project $project, ?string $kode = null): Response
    {
        // Tanpa kode: gabungan semua unit pekerjaan ini
        $unit = $kode !== null ? $project->kecamatans()->where('kode', $kode)->firstOrFail() : null;
        $statuses = $project->config()->statuses();

        $queue = TargetRow::query()
            ->when($unit, fn ($query) => $query->where('kecamatan_id', $unit->id), fn ($query) => $query->where('project_id', $project->id))
            ->tracked()
            ->with(['sheet:id,name,headers', 'kecamatan:id,kode,nama'])
            ->orderBy('kecamatan_id')
            ->orderBy('target_sheet_id')
            ->orderBy('row_number')
            ->get()
            ->map(function (TargetRow $row) use ($statuses) {
                $status = [
                    'id' => $row->row_key,
                    'unit' => $row->kecamatan?->nama,
                    'sheet' => $row->sheet?->name,
                    'row' => $row->row_number,
                    'status' => $row->status,
                    'status_label' => $statuses->label($row->status),
                    'reason' => $row->reason,
                    'doneAt' => $row->status_at?->toIso8601String(),
                ];

                return [
                    ...$status,
                    // Field asli dari laporan script (mis. namaUsaha, kelAnggota, linkOss)
                    ...array_diff_key($row->result ?? [], $status),
                    // Isi baris Excel lengkap dengan nama kolomnya
                    'excel' => $this->excelRow($row),
                ];
            });

        $json = json_encode([
            'v' => 1,
            'project' => $project->name,
            'unit' => $unit?->nama ?? 'Semua',
            'generated_at' => now()->toIso8601String(),
            'queue' => $queue,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $scope = $unit ? $unit->kode.'_'.str($unit->nama)->slug('_') : 'gabungan';
        $filename = sprintf('%s_%s_status_%s.json', str($project->name)->slug('_'), $scope, now()->format('Ymd-Hi'));

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array<string, mixed> nama kolom Excel => isi sel
     */
    private function excelRow(TargetRow $row): array
    {
        $values = [];

        foreach ($row->sheet?->headers ?? [] as $index => $header) {
            $values[(string) $header] ??= $row->cells[$index] ?? null;
        }

        return $values;
    }
}
