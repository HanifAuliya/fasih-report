<?php

namespace App\Http\Controllers;

use App\Models\Kecamatan;
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
 * Download baris yang BELUM selesai (satu unit atau semua unit) untuk dimasukkan lagi ke userscript.
 * Kolom asli tetap di posisi yang sama seperti file aslinya; kolom tambahan diletakkan paling kanan.
 * Yang ikut: baris target (berkunci, bukan kembar) yang statusnya bukan status selesai.
 */
class PendingExportController extends Controller
{
    private const EXTRA_HEADERS = ['status_web', 'keterangan_web', 'unit_web', 'baris_asli'];

    public function __invoke(Project $project, ?string $kode = null): BinaryFileResponse
    {
        $statuses = $project->config()->statuses();
        $units = $project->kecamatans()
            ->when($kode !== null, fn ($query) => $query->where('kode', $kode))
            ->get()
            ->keyBy('id');

        abort_if($kode !== null && $units->isEmpty(), 404);

        $groups = TargetSheet::query()
            ->whereIn('kecamatan_id', $units->keys())
            ->where('tracked', true)
            ->get()
            ->sortBy(fn (TargetSheet $sheet) => [$sheet->position, $units[$sheet->kecamatan_id]->kode])
            ->groupBy('name');

        abort_if($groups->isEmpty(), 404);

        $path = tempnam(sys_get_temp_dir(), 'belum').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;

        foreach ($groups as $name => $sheets) {
            $writerSheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $writerSheet->setName(mb_substr((string) $name, 0, 31));
            $first = false;

            // Satu sheet: kolom persis seperti aslinya (posisi sama, judul kembar tetap ada);
            // beberapa sheet bernama sama: disatukan lewat nama kolom
            $single = $sheets->count() === 1;
            $headers = $single ? array_map('strval', $sheets->first()->headers) : $this->mergedHeaders($sheets);
            $writer->addRow(Row::fromValues([...$headers, ...self::EXTRA_HEADERS]));
            $written = 0;

            foreach ($sheets as $sheet) {
                /** @var Kecamatan $unit */
                $unit = $units[$sheet->kecamatan_id];

                TargetRow::where('target_sheet_id', $sheet->id)
                    ->tracked()
                    ->where(fn ($query) => $query->whereNull('status')->orWhereNotIn('status', $statuses->doneCodes()))
                    ->orderBy('row_number')
                    ->lazy(500)
                    ->each(function (TargetRow $row) use ($writer, $sheet, $unit, $headers, $single, $statuses, &$written) {
                        $values = [
                            ...($single ? array_pad($row->cells, count($headers), null) : $this->cellsByHeader($sheet, $row, $headers)),
                            $row->status ? $statuses->label($row->status) : '',
                            $row->reason ?? '',
                            $unit->nama,
                            $row->row_number,
                        ];
                        $style = $row->status ? XlsxStyleBook::openSpoutStyle($statuses->all()[$row->status]['color'] ?? null) : null;

                        $writer->addRow($style ? Row::fromValuesWithStyles($values, [count($headers) => $style]) : Row::fromValues($values));
                        $written++;
                    });
            }

            if ($written > 0) {
                $writerSheet->setAutoFilter(new AutoFilter(0, 1, count($headers) + count(self::EXTRA_HEADERS) - 1, $written + 1));
            }
        }

        $writer->close();

        $scope = $kode !== null ? $units->first()->kode.'_'.str($units->first()->nama)->slug('_') : 'semua';
        $filename = sprintf('%s_%s_belum_selesai_%s.xlsx', str($project->name)->slug('_'), $scope, now()->format('Ymd-Hi'));

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * @param  list<string>  $headers
     * @return list<mixed>
     */
    private function cellsByHeader(TargetSheet $sheet, TargetRow $row, array $headers): array
    {
        $cells = [];

        foreach ($sheet->headers as $index => $header) {
            $cells[(string) $header] ??= $row->cells[$index] ?? null;
        }

        return array_map(fn (string $header) => $cells[$header] ?? '', $headers);
    }

    /**
     * Header gabungan semua sheet bernama sama (urut kemunculan); untuk satu unit = header aslinya.
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
}
