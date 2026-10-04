<?php

namespace App\Http\Controllers;

use App\Models\Kecamatan;
use App\Models\Project;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Support\StatusSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Download rekap semua baris (seluruh unit dalam satu pekerjaan) yang statusnya diubah oleh laporan JSON.
 * Sheet "Ringkasan" berisi jumlah per unit & status, sheet "Baris berubah" berisi detail barisnya.
 */
class ProjectChangesExportController extends Controller
{
    public function __invoke(Project $project): BinaryFileResponse
    {
        $statuses = $project->config()->statuses();
        $unitLabel = $project->config()->unitLabel();

        $changedRows = fn () => TargetRow::query()
            ->where('project_id', $project->id)
            ->whereNotNull('status_file_id');

        $units = $project->kecamatans()->orderBy('kode')->get()->keyBy('id');
        $sheets = TargetSheet::query()
            ->whereIn('id', $changedRows()->select('target_sheet_id'))
            ->get()
            ->keyBy('id');
        $headers = $this->mergedHeaders($sheets);

        $path = tempnam(sys_get_temp_dir(), 'rekap').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Ringkasan');
        $this->writeSummary($writer, $changedRows(), $units, $statuses, $unitLabel);

        $writer->addNewSheetAndMakeItCurrent()->setName('Baris berubah');
        $writer->addRow(Row::fromValues([
            "Kode {$unitLabel}", $unitLabel, 'Sheet', 'Baris',
            ...$headers,
            'Status', 'Keterangan', 'Waktu status', 'File JSON',
        ]));

        $changedRows()
            ->with('statusFile:id,original_name')
            ->orderBy('kecamatan_id')
            ->orderBy('target_sheet_id')
            ->orderBy('row_number')
            ->lazy(500)
            ->each(function (TargetRow $row) use ($writer, $units, $sheets, $headers, $statuses) {
                $unit = $units->get($row->kecamatan_id);
                $sheet = $sheets->get($row->target_sheet_id);
                $cells = $sheet ? array_combine(
                    array_slice($sheet->headers, 0, count($row->cells)),
                    array_slice($row->cells, 0, count($sheet->headers)),
                ) : [];

                $writer->addRow(Row::fromValues([
                    $unit?->kode ?? '',
                    $unit?->nama ?? '',
                    $sheet?->name ?? '',
                    $row->row_number,
                    ...array_map(fn (string $header) => $cells[$header] ?? '', $headers),
                    $statuses->label($row->status),
                    $row->reason ?? '',
                    $row->status_at?->format('Y-m-d H:i') ?? '',
                    $row->statusFile?->original_name ?? '',
                ]));
            });

        $writer->close();

        $filename = sprintf('%s_rekap_perubahan_json_%s.xlsx', str($project->name)->slug('_'), now()->format('Ymd-Hi'));

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * Gabungan header dari semua sheet (urut sesuai kemunculan), supaya file dengan kolom berbeda tetap muat.
     *
     * @param  Collection<int, TargetSheet>  $sheets
     * @return list<string>
     */
    private function mergedHeaders(Collection $sheets): array
    {
        return $sheets
            ->flatMap(fn (TargetSheet $sheet) => $sheet->headers)
            ->map(fn ($header) => (string) $header)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Builder<TargetRow>  $changedRows
     * @param  Collection<int, Kecamatan>  $units
     */
    private function writeSummary(Writer $writer, Builder $changedRows, Collection $units, StatusSet $statuses, string $unitLabel): void
    {
        $counts = $changedRows
            ->selectRaw('kecamatan_id, status, count(*) as total')
            ->groupBy('kecamatan_id', 'status')
            ->get()
            ->groupBy('kecamatan_id')
            ->map(fn ($rows) => $rows->pluck('total', 'status'));

        $statusCodes = $statuses->all()->pluck('code')->values()->all();

        $writer->addRow(Row::fromValues([
            "Kode {$unitLabel}", $unitLabel, 'Baris berubah',
            ...array_map(fn (string $code) => $statuses->label($code), $statusCodes),
        ]));

        $totals = array_fill_keys($statusCodes, 0);

        foreach ($units as $unit) {
            $unitCounts = $counts->get($unit->id, collect());

            if ($unitCounts->isEmpty()) {
                continue;
            }

            foreach ($statusCodes as $code) {
                $totals[$code] += (int) ($unitCounts[$code] ?? 0);
            }

            $writer->addRow(Row::fromValues([
                $unit->kode,
                $unit->nama,
                (int) $unitCounts->sum(),
                ...array_map(fn (string $code) => (int) ($unitCounts[$code] ?? 0), $statusCodes),
            ]));
        }

        $writer->addRow(Row::fromValues(['', 'Total', array_sum($totals), ...array_values($totals)]));
    }
}
