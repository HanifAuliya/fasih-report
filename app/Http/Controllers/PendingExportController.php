<?php

namespace App\Http\Controllers;

use App\Models\Kecamatan;
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
 * Download baris yang BELUM selesai (satu unit atau semua unit) untuk dimasukkan lagi ke userscript.
 * Kolom asli tetap di posisi yang sama seperti file aslinya; kolom tambahan diletakkan paling kanan.
 * Yang ikut: baris target (berkunci, bukan kembar) yang statusnya bukan status selesai.
 */
class PendingExportController extends Controller
{
    private const EXTRA_HEADERS = ['status_web', 'keterangan_web', 'unit_web', 'baris_asli'];

    public function __invoke(Project $project, PreparedExport $exports, ?string $kode = null): Response
    {
        // Satu unit selalu kecil: langsung dibuat. Semua unit: disiapkan di latar belakang bila besar.
        if ($kode === null) {
            return $exports->serve($project, 'belum-selesai', fn () => $this->build($project, null));
        }

        ['path' => $path, 'filename' => $filename] = $this->build($project, $kode);

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * @return array{path: string, filename: string}
     */
    private function build(Project $project, ?string $kode): array
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
                $positions = $single ? null : $this->positions($sheet, $headers);

                TargetRow::where('target_sheet_id', $sheet->id)
                    ->tracked()
                    ->where(fn ($query) => $query->whereNull('status')->orWhereNotIn('status', $statuses->doneCodes()))
                    ->orderBy('row_number')
                    ->lazy(1000)
                    ->each(function (TargetRow $row) use ($writer, $unit, $headers, $positions, $statuses, &$written) {
                        $values = [
                            ...($positions === null ? array_pad($row->cells ?? [], count($headers), null) : $this->cellsAt($row, $positions, count($headers))),
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

        $scope = $kode !== null ? str($units->first()->nama)->slug('_') : 'semua';

        return ['path' => $path, 'filename' => sprintf('%s_%s_belum_selesai_%s.xlsx', str($project->name)->slug('_'), $scope, now()->format('Ymd-Hi'))];
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
     * Isi baris disusun mengikuti header gabungan; isi baris di-decode sekali.
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
