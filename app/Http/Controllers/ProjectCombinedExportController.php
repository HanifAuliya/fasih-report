<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Services\PreparedExport;
use App\Support\XlsxStyleBook;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download satu Excel berisi data semua unit dalam satu pekerjaan (status terbaru).
 * Sheet yang namanya sama di tiap unit digabung jadi satu sheet; kolom disatukan lewat nama header.
 * Baris selesai diberi proses=0 & status_awal=dipindah seperti download per unit (untuk userscript OSS).
 */
class ProjectCombinedExportController extends Controller
{
    private const STATUS_HEADERS = ['status_web', 'keterangan_web', 'waktu_status_web'];

    public function __invoke(Project $project, PreparedExport $exports): Response
    {
        return $exports->serve($project, 'gabungan', fn () => $this->build($project));
    }

    /**
     * @return array{path: string, filename: string}
     */
    private function build(Project $project): array
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
                $positions = $this->positions($sheet, $headers);

                TargetRow::where('target_sheet_id', $sheet->id)
                    ->with('duplicateOf:id,nama')
                    ->orderBy('row_number')
                    ->lazy(1000)
                    ->each(function (TargetRow $row) use ($writer, $unit, $headers, $positions, $tracked, $statuses, $processColumn, $initialStatusColumn, &$written) {
                        $cells = $this->cellsAt($row, $positions, count($headers));

                        if ($tracked && $processColumn !== false && $statuses->isDone($row->status)) {
                            $cells[$processColumn] = '0';
                        }

                        if ($tracked && $initialStatusColumn !== false && $statuses->hasAlias($row->status, 'dipindah')) {
                            $cells[$initialStatusColumn] = 'dipindah';
                        }

                        $values = [$unit->kode, $unit->nama, $row->row_number, ...$cells];

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

        return ['path' => $path, 'filename' => sprintf('%s_gabungan_%s.xlsx', str($project->name)->slug('_'), now()->format('Ymd-Hi'))];
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
     * Posisi tiap kolom sheet di header gabungan (judul kembar: yang pertama), dihitung sekali per sheet.
     *
     * @param  list<string>  $headers
     * @return array<int, int> index kolom sheet => posisi di $headers
     */
    private function positions(TargetSheet $sheet, array $headers): array
    {
        $lookup = array_flip($headers);
        $positions = [];

        foreach ($sheet->headers as $index => $header) {
            $position = $lookup[(string) $header] ?? null;

            if ($position !== null && ! in_array($position, $positions, true)) {
                $positions[$index] = $position;
            }
        }

        return $positions;
    }

    /**
     * Isi baris disusun mengikuti header gabungan; isi baris di-decode sekali (cast array Eloquent
     * men-decode JSON di setiap akses).
     *
     * @param  array<int, int>  $positions
     * @return list<mixed>
     */
    private function cellsAt(TargetRow $row, array $positions, int $width): array
    {
        $cells = $row->cells ?? [];
        $values = array_fill(0, $width, '');

        foreach ($positions as $index => $position) {
            $values[$position] = $cells[$index] ?? '';
        }

        return $values;
    }
}
