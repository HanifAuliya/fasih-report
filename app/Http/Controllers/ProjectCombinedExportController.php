<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Support\XlsxStyleBook;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Download satu Excel berisi data semua unit dalam satu pekerjaan (status terbaru).
 * Sheet yang namanya sama di tiap unit digabung jadi satu sheet; kolom disatukan lewat nama header.
 * Baris selesai diberi proses=0 & status_awal=dipindah seperti download per unit (untuk userscript OSS).
 */
class ProjectCombinedExportController extends Controller
{
    private const STATUS_HEADERS = ['status_web', 'keterangan_web', 'waktu_status_web'];

    public function __invoke(Project $project): BinaryFileResponse
    {
        $config = $project->config();
        $statuses = $config->statuses();
        $units = $project->kecamatans()->get()->keyBy('id');

        $groups = TargetSheet::query()
            ->whereIn('kecamatan_id', $units->keys())
            ->orderBy('position')
            ->get()
            ->sortBy(fn (TargetSheet $sheet) => [$sheet->position, $units[$sheet->kecamatan_id]->kode])
            ->groupBy('name');

        abort_if($groups->isEmpty(), 404);

        $path = tempnam(sys_get_temp_dir(), 'gabungan').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;

        foreach ($groups as $name => $sheets) {
            $writerSheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $writerSheet->setName(mb_substr((string) $name, 0, 31));
            $first = false;

            $headers = $this->mergedHeaders($sheets);
            $tracked = $sheets->contains(fn (TargetSheet $sheet) => $sheet->isTracked());
            $lower = array_map('strtolower', $headers);
            $processColumn = array_search('proses', $lower, true);
            $initialStatusColumn = array_search('status_awal', $lower, true);

            $writer->addRow(Row::fromValues([
                'Kode '.$config->unitLabel(), $config->unitLabel(), 'Baris',
                ...$headers,
                ...($tracked ? self::STATUS_HEADERS : []),
            ]));

            $written = 0;

            foreach ($sheets as $sheet) {
                $unit = $units[$sheet->kecamatan_id];

                TargetRow::where('target_sheet_id', $sheet->id)
                    ->with('duplicateOf:id,nama')
                    ->orderBy('row_number')
                    ->lazy(500)
                    ->each(function (TargetRow $row) use ($writer, $sheet, $unit, $headers, $tracked, $statuses, $processColumn, $initialStatusColumn, &$written) {
                        $cells = $this->cellsByHeader($sheet, $row);

                        if ($tracked && $processColumn !== false && $statuses->isDone($row->status)) {
                            $cells[$headers[$processColumn]] = '0';
                        }

                        if ($tracked && $initialStatusColumn !== false && $statuses->hasAlias($row->status, 'dipindah')) {
                            $cells[$headers[$initialStatusColumn]] = 'dipindah';
                        }

                        $values = [
                            $unit->kode,
                            $unit->nama,
                            $row->row_number,
                            ...array_map(fn (string $header) => $cells[$header] ?? '', $headers),
                        ];

                        if (! $tracked) {
                            $writer->addRow(Row::fromValues($values));
                            $written++;

                            return;
                        }

                        $statusColumn = count($values);
                        $values = [
                            ...$values,
                            match (true) {
                                $row->duplicate_of_id !== null => 'Sudah di '.($row->duplicateOf?->nama ?? 'unit lain'),
                                $row->status !== null => $statuses->label($row->status),
                                default => '',
                            },
                            $row->reason ?? '',
                            $row->status_at?->format('Y-m-d H:i') ?? '',
                        ];
                        $style = $row->status ? XlsxStyleBook::openSpoutStyle($statuses->all()[$row->status]['color'] ?? null) : null;

                        $writer->addRow($style ? Row::fromValuesWithStyles($values, [$statusColumn => $style]) : Row::fromValues($values));
                        $written++;
                    });
            }

            // Tombol filter Excel di baris judul (termasuk kolom unit & status)
            if ($written > 0) {
                $writerSheet->setAutoFilter(new AutoFilter(0, 1, 3 + count($headers) + ($tracked ? 3 : 0) - 1, $written + 1));
            }
        }

        $writer->close();

        $filename = sprintf('%s_gabungan_%s.xlsx', str($project->name)->slug('_'), now()->format('Ymd-Hi'));

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * Gabungan header dari semua sheet bernama sama (urut kemunculan).
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
     * @return array<string, mixed> nama header => isi sel
     */
    private function cellsByHeader(TargetSheet $sheet, TargetRow $row): array
    {
        $cells = [];

        foreach ($sheet->headers as $index => $header) {
            $cells[(string) $header] ??= $row->cells[$index] ?? null;
        }

        return $cells;
    }
}
