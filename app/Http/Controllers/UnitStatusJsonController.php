<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Support\StatusSet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download JSON status terkini satu unit (hasil laporan + perubahan manual di web),
 * dengan format yang sama seperti laporan script sehingga bisa diupload ulang.
 * Dikirim bertahap (streaming): puluhan ribu baris tidak perlu dirakit dulu di memori,
 * dan unduhan langsung mulai sehingga tidak terputus batas waktu gateway.
 */
class UnitStatusJsonController extends Controller
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __invoke(Project $project, ?string $kode = null): StreamedResponse
    {
        // Tanpa kode: gabungan semua unit pekerjaan ini
        $unit = $kode !== null ? $project->kecamatans()->where('kode', $kode)->firstOrFail() : null;
        $statuses = $project->config()->statuses();
        $units = $project->kecamatans()->pluck('nama', 'id');

        $scope = $unit ? str($unit->nama)->slug('_') : 'gabungan';
        $filename = sprintf('%s_%s_status_%s.json', str($project->name)->slug('_'), $scope, now()->format('Ymd-Hi'));

        return response()->streamDownload(function () use ($project, $unit, $statuses, $units) {
            $header = json_encode([
                'v' => 1,
                'project' => $project->name,
                'unit' => $unit?->nama ?? 'Semua',
                'generated_at' => now()->toIso8601String(),
            ], self::JSON_FLAGS);

            echo substr($header, 0, -1).',"queue":[';

            $sheets = [];
            $first = true;

            TargetRow::query()
                ->when($unit, fn ($query) => $query->where('kecamatan_id', $unit->id), fn ($query) => $query->where('project_id', $project->id))
                ->tracked()
                ->orderBy('kecamatan_id')
                ->orderBy('target_sheet_id')
                ->orderBy('row_number')
                ->lazy(1000)
                ->each(function (TargetRow $row) use ($statuses, $units, &$sheets, &$first) {
                    $sheet = $sheets[$row->target_sheet_id] ??= $this->sheetInfo($row->target_sheet_id);

                    echo ($first ? '' : ','), json_encode($this->item($row, $sheet, $statuses, $units[$row->kecamatan_id] ?? null), self::JSON_FLAGS);
                    $first = false;
                });

            echo ']}';
        }, $filename, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * @param  array{name: ?string, headers: list<string>}  $sheet
     * @return array<string, mixed>
     */
    private function item(TargetRow $row, array $sheet, StatusSet $statuses, ?string $unitName): array
    {
        $status = [
            'id' => $row->row_key,
            'unit' => $unitName,
            'sheet' => $sheet['name'],
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
            'excel' => $this->excelRow($row, $sheet['headers']),
        ];
    }

    /**
     * @return array{name: ?string, headers: list<string>}
     */
    private function sheetInfo(int $sheetId): array
    {
        $sheet = TargetSheet::find($sheetId, ['id', 'name', 'headers']);

        return ['name' => $sheet?->name, 'headers' => array_map('strval', $sheet?->headers ?? [])];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, mixed> nama kolom Excel => isi sel
     */
    private function excelRow(TargetRow $row, array $headers): array
    {
        // Isi baris di-decode sekali (cast array Eloquent men-decode JSON di setiap akses)
        $cells = $row->cells ?? [];
        $values = [];

        foreach ($headers as $index => $header) {
            $values[$header] ??= $cells[$index] ?? null;
        }

        return $values;
    }
}
