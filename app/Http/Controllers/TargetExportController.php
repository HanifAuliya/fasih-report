<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TargetRow;
use App\Services\OriginalWorkbookExporter;
use App\Support\StatusSet;
use App\Support\XlsxStyleBook;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Download Excel target kecamatan dengan status terbaru: memakai file Excel asli (lihat OriginalWorkbookExporter),
 * atau dibangun ulang dari data tersimpan bila file asli tidak ada.
 * Baris yang sudah selesai diberi proses=0 dan yang sudah dipindah diberi status_awal=dipindah,
 * jadi file ini bisa langsung dimuat ulang ke userscript untuk melanjutkan sisa pekerjaan.
 */
class TargetExportController extends Controller
{
    public function __invoke(Project $project, string $kode, OriginalWorkbookExporter $originalExporter): BinaryFileResponse
    {
        $kecamatan = $project->kecamatans()->where('kode', $kode)->firstOrFail();
        $sheets = $kecamatan->targetSheets()->get();
        $statuses = $project->config()->statuses();

        abort_if($sheets->isEmpty(), 404);

        $filename = sprintf('%s_%s_%s_%s.xlsx', str($project->name)->slug('_'), $kecamatan->kode, str($kecamatan->nama)->slug('_'), now()->format('Ymd-Hi'));

        // Utamakan file Excel asli (format tetap utuh); bila tidak tersedia, bangun ulang dari data tersimpan
        if ($path = $originalExporter->export($kecamatan)) {
            return response()->download($path, $filename)->deleteFileAfterSend();
        }

        $path = tempnam(sys_get_temp_dir(), 'target').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($sheets as $index => $sheet) {
            $writerSheet = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $writerSheet->setName(mb_substr($sheet->name, 0, 31));

            $headers = $sheet->headers;
            $lowerHeaders = array_map('strtolower', $headers);
            $tracked = $sheet->isTracked();
            $processColumn = array_search('proses', $lowerHeaders, true);
            $initialStatusColumn = array_search('status_awal', $lowerHeaders, true);

            $writer->addRow(Row::fromValues($tracked ? [...$headers, 'status_web', 'keterangan_web', 'waktu_status_web'] : $headers));

            $sheet->rows()->with('duplicateOf:id,nama')->chunk(500, function ($rows) use ($writer, $statuses, $tracked, $processColumn, $initialStatusColumn) {
                foreach ($rows as $row) {
                    $values = $this->rowValues($row, $statuses, $tracked, $processColumn, $initialStatusColumn);
                    $style = $tracked && $row->status ? XlsxStyleBook::openSpoutStyle($statuses->all()[$row->status]['color'] ?? null) : null;

                    // Sel status_web berwarna sesuai status
                    $writer->addRow($style
                        ? Row::fromValuesWithStyles($values, [count($values) - 3 => $style])
                        : Row::fromValues($values));
                }
            });

            // Tombol filter Excel di baris judul (termasuk kolom status)
            if ($rowCount = $sheet->rows()->count()) {
                $writerSheet->setAutoFilter(new AutoFilter(0, 1, count($headers) + ($tracked ? 2 : -1), $rowCount + 1));
            }
        }

        $writer->close();

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * @return list<mixed>
     */
    private function rowValues(TargetRow $row, StatusSet $statuses, bool $tracked, int|false $processColumn, int|false $initialStatusColumn): array
    {
        $cells = $row->cells;

        if (! $tracked) {
            return $cells;
        }

        if ($processColumn !== false && $statuses->isDone($row->status)) {
            $cells[$processColumn] = '0';
        }

        if ($initialStatusColumn !== false && $statuses->hasAlias($row->status, 'dipindah')) {
            $cells[$initialStatusColumn] = 'dipindah';
        }

        return [
            ...$cells,
            match (true) {
                $row->duplicate_of_id !== null => 'Sudah di '.($row->duplicateOf?->nama ?? 'unit lain'),
                $row->status !== null => $statuses->label($row->status),
                default => '',
            },
            $row->reason ?? '',
            $row->status_at?->format('Y-m-d H:i') ?? '',
        ];
    }
}
