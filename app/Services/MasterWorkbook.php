<?php

namespace App\Services;

use App\Models\MasterRow;
use App\Models\Project;
use App\Models\ReportFile;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Support\StatusSet;
use App\Support\XlsxPackage;
use App\Support\XlsxSheetEditor;
use App\Support\XlsxStyleBook;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * File induk pekerjaan: Excel utuh yang bisa sangat besar (mis. 90 ribu baris, sheet XML 75 MB).
 * Tidak diimpor seperti Excel unit; cukup disimpan nomor baris, kunci, dan penanda "perlu dikerjakan"
 * lewat pembacaan XML langsung (cepat & hemat memori). Status setiap baris diambil dari baris unit
 * (mis. Bagian) yang kuncinya sama, lalu bisa ditulis ke salinan file induk asli untuk diunduh.
 */
class MasterWorkbook
{
    private const UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    private const STATUS_HEADERS = ['status_web', 'keterangan_web', 'waktu_status_web'];

    /**
     * Simpan baris file induk. Baris lama pekerjaan ini diganti.
     */
    public function import(ReportFile $file): string
    {
        $project = $file->project;
        $settings = $project->config();
        $keyHeader = $settings->keyColumn() !== null ? TargetSheet::normalizeHeader($settings->keyColumn()) : null;
        $taskHeader = $settings->taskColumn() !== null ? TargetSheet::normalizeHeader($settings->taskColumn()) : null;

        if ($settings->keyMode() !== 'column' || $keyHeader === null) {
            throw new RuntimeException('file induk butuh "Kolom kunci" (mis. link) di tab Pengaturan.');
        }

        $path = Storage::disk('local')->path($file->path);
        [$sheetPath, $links] = $this->firstSheet($path);
        $strings = $this->sharedStrings($path);

        $headerRow = null;
        $keyColumn = null;
        $taskColumn = null;
        $batch = [];
        $counts = ['rows' => 0, 'tasks' => 0, 'keyed' => 0];

        DB::transaction(function () use ($project, $file, $path, $sheetPath, $links, $strings, $keyHeader, $taskHeader, $settings, &$headerRow, &$keyColumn, &$taskColumn, &$batch, &$counts) {
            MasterRow::where('project_id', $project->id)->delete();

            foreach ($this->rows($path, $sheetPath, $strings) as $rowNumber => $cells) {
                if ($headerRow === null) {
                    $headers = array_map(fn ($value) => TargetSheet::normalizeHeader((string) $value), $cells);
                    $found = array_search($keyHeader, $headers, true);

                    if ($found !== false) {
                        $headerRow = $rowNumber;
                        $keyColumn = $found;
                        $taskColumn = $taskHeader !== null ? array_search($taskHeader, $headers, true) : false;
                    } elseif ($rowNumber > 20) {
                        throw new RuntimeException("kolom kunci \"{$settings->keyColumn()}\" tidak ditemukan di 20 baris pertama.");
                    }

                    continue;
                }

                $value = $links[$keyColumn.':'.$rowNumber] ?? ($cells[$keyColumn] ?? null);
                $key = TargetImporter::normalizeKey($value);
                $isTask = $taskColumn === false || $taskColumn === null || $settings->isTaskValue($cells[$taskColumn] ?? null);

                $counts['rows']++;
                $counts['keyed'] += $key !== null ? 1 : 0;
                $counts['tasks'] += $isTask ? 1 : 0;
                $batch[] = ['project_id' => $project->id, 'report_file_id' => $file->id, 'row_number' => $rowNumber, 'row_key' => $key, 'is_task' => $isTask];

                if (count($batch) >= 1000) {
                    MasterRow::insert($batch);
                    $batch = [];
                }
            }

            if ($batch) {
                MasterRow::insert($batch);
            }

            if ($headerRow === null) {
                throw new RuntimeException("kolom kunci \"{$settings->keyColumn()}\" tidak ditemukan.");
            }
        });

        $file->update(['header_row' => $headerRow]);
        $this->forgetExport($project);

        return sprintf(
            'File induk: %s baris, %s perlu dikerjakan%s',
            number_format($counts['rows'], 0, ',', '.'),
            number_format($counts['tasks'], 0, ',', '.'),
            $taskHeader !== null ? " (kolom {$settings->taskColumn()})" : '',
        );
    }

    /**
     * Ringkasan file induk: total, perlu dikerjakan, sudah ada di unit, dan selesai.
     *
     * @return array{rows: int, tasks: int, linked: int, done: int}
     */
    public function stats(Project $project): array
    {
        $doneCodes = $project->config()->statuses()->doneCodes();

        $totals = MasterRow::where('project_id', $project->id)
            ->selectRaw('count(*) as total_rows, sum(case when is_task then 1 else 0 end) as task_rows')
            ->first();

        $linked = DB::table('master_rows as m')
            ->join('target_rows as t', fn ($join) => $join->on('t.project_id', '=', 'm.project_id')->on('t.row_key', '=', 'm.row_key'))
            ->where('m.project_id', $project->id)
            ->where('m.is_task', true)
            ->selectRaw('count(distinct m.id) as linked_rows')
            ->selectRaw($doneCodes === [] ? '0 as done_rows' : 'count(distinct case when t.status in ('.implode(',', array_fill(0, count($doneCodes), '?')).') then m.id end) as done_rows', $doneCodes)
            ->first();

        return [
            'rows' => (int) $totals->total_rows,
            'tasks' => (int) $totals->task_rows,
            'linked' => (int) $linked->linked_rows,
            'done' => (int) $linked->done_rows,
        ];
    }

    /**
     * Tulis status terbaru ke salinan file induk asli. Hasilnya disimpan di storage untuk diunduh.
     *
     * @return string path file hasil (disk local)
     */
    public function export(Project $project): string
    {
        $file = $this->masterFile($project) ?? throw new RuntimeException('Belum ada file induk.');
        $source = Storage::disk('local')->path($file->path);
        $statuses = $project->config()->statuses();

        // kunci => status terbaru dari baris unit
        $results = TargetRow::where('project_id', $project->id)
            ->tracked()
            ->whereNotNull('status')
            ->orderBy('status_at')
            ->get(['row_key', 'status', 'reason', 'status_at'])
            ->keyBy('row_key');
        // Hanya baris yang ditandai dikerjakan: satu link bisa muncul di beberapa baris induk
        $keys = MasterRow::where('project_id', $project->id)->where('is_task', true)->whereNotNull('row_key')->pluck('row_key', 'row_number');

        [$sheetPath] = $this->firstSheet($source);
        $styleBook = new XlsxStyleBook((string) file_get_contents('zip://'.$source.'#xl/styles.xml'));
        $headerRow = (int) $file->header_row;
        $firstStatusColumn = null;
        $lastRow = $headerRow;

        // Baca XML sheet per potongan, proses per <row> utuh: memori tetap kecil walau sheet puluhan MB.
        // Hasil: kepala (sebelum <sheetData>) + baris (file sementara) + ekor (setelah baris terakhir).
        $in = fopen('zip://'.$source.'#'.$sheetPath, 'r');
        $rowsFile = tempnam(sys_get_temp_dir(), 'induk-rows');
        $out = fopen($rowsFile, 'w');
        $buffer = '';
        $head = null;

        while (! feof($in)) {
            $buffer .= fread($in, 1 << 20);

            if ($head === null) {
                $start = strpos($buffer, '<sheetData');

                if ($start === false) {
                    continue;
                }

                $head = substr($buffer, 0, $start);
                $buffer = substr($buffer, $start);
            }

            $cut = strrpos($buffer, '</row>');

            if ($cut === false) {
                continue;
            }

            fwrite($out, $this->writeRows(substr($buffer, 0, $cut + 6), $headerRow, $keys, $results, $statuses, $styleBook, $firstStatusColumn, $lastRow));
            $buffer = substr($buffer, $cut + 6);
        }

        fclose($in);
        fclose($out);

        if ($head === null || $firstStatusColumn === null) {
            @unlink($rowsFile);

            throw new RuntimeException('baris judul file induk tidak ditemukan.');
        }

        $lastColumn = $firstStatusColumn + count(self::STATUS_HEADERS) - 1;
        $range = 'A'.$headerRow.':'.XlsxPackage::columnLetter($lastColumn).$lastRow;

        $sheetXml = tempnam(sys_get_temp_dir(), 'induk-sheet');
        $sheet = fopen($sheetXml, 'w');
        fwrite($sheet, XlsxSheetEditor::widenDimension($head, $lastColumn));
        $rows = fopen($rowsFile, 'r');
        stream_copy_to_stream($rows, $sheet);
        fclose($rows);
        fwrite($sheet, XlsxSheetEditor::withAutoFilter($buffer, $range));
        fclose($sheet);
        @unlink($rowsFile);

        $target = 'exports/project-'.$project->id.'-induk.xlsx';
        $targetPath = Storage::disk('local')->path($target);
        Storage::disk('local')->makeDirectory('exports');
        copy($source, $targetPath);

        $zip = new ZipArchive;
        $zip->open($targetPath);
        $zip->addFile($sheetXml, $sheetPath);
        $zip->addFromString('xl/styles.xml', $styleBook->toXml());
        $sheetName = array_search($sheetPath, XlsxPackage::sheetPaths($zip), true);
        $zip->addFromString('xl/workbook.xml', XlsxSheetEditor::filterDefinedNames((string) $zip->getFromName('xl/workbook.xml'), [$sheetName => $range]));
        $zip->close();
        @unlink($sheetXml);

        return $target;
    }

    /**
     * Jalankan export dan catat statusnya (dipakai di latar belakang; tampilan memantau lewat exportState()).
     */
    public function runExport(Project $project): void
    {
        @set_time_limit(900);
        $this->markExportRunning($project);

        try {
            $path = $this->export($project);
            Cache::put($this->exportCacheKey($project), ['status' => 'ready', 'path' => $path, 'at' => now()->toIso8601String()], now()->addDays(30));
        } catch (Throwable $e) {
            report($e);
            Cache::put($this->exportCacheKey($project), ['status' => 'failed', 'message' => $e->getMessage(), 'at' => now()->toIso8601String()], now()->addDay());
        }
    }

    /**
     * Impor di latar belakang dengan catatan status di summary file.
     */
    public function runImport(ReportFile $file): void
    {
        @set_time_limit(900);

        try {
            $summary = $this->import($file);
        } catch (Throwable $e) {
            report($e);
            $summary = 'Gagal diproses: '.$e->getMessage();
        }

        $file->update(['summary' => $summary]);
    }

    public function markExportRunning(Project $project): void
    {
        Cache::put($this->exportCacheKey($project), ['status' => 'running', 'at' => now()->toIso8601String()], now()->addHours(2));
    }

    /**
     * Hapus hasil export lama (mis. saat file induk diganti/dihapus).
     */
    public function forgetExport(Project $project): void
    {
        Storage::disk('local')->delete('exports/project-'.$project->id.'-induk.xlsx');
        Cache::forget($this->exportCacheKey($project));
    }

    /**
     * @return array{status: string, at?: string, path?: string, message?: string}|null
     */
    public function exportState(Project $project): ?array
    {
        return Cache::get($this->exportCacheKey($project));
    }

    public function masterFile(Project $project): ?ReportFile
    {
        return $project->files()->reorder()->where('category', 'induk')->latest('id')->first();
    }

    private function exportCacheKey(Project $project): string
    {
        return 'master-export:'.$project->id;
    }

    /**
     * Proses potongan XML berisi <row> utuh: judul dapat kolom status, baris berkunci dapat statusnya.
     *
     * @param  Collection<int, string>  $keys
     * @param  Collection<string, TargetRow>  $results
     */
    private function writeRows(string $chunk, int $headerRow, Collection $keys, Collection $results, StatusSet $statuses, XlsxStyleBook $styleBook, ?int &$firstStatusColumn, int &$lastRow): string
    {
        return preg_replace_callback('/<row\b([^>]*?)(?:\/>|>(.*?)<\/row>)/s', function (array $match) use ($headerRow, $keys, $results, $statuses, $styleBook, &$firstStatusColumn, &$lastRow) {
            if (! preg_match('/\br="(\d+)"/', $match[1], $number)) {
                return $match[0];
            }

            $rowNumber = (int) $number[1];
            $inner = $match[2] ?? '';
            $lastRow = max($lastRow, $rowNumber);
            $values = [];
            $statusColor = null;

            if ($rowNumber === $headerRow) {
                // Kolom status mulai setelah sel terakhir di baris judul
                preg_match_all('/<c\b[^>]*?\br="([A-Z]+)\d+"/', $inner, $refs);
                $firstStatusColumn = $refs[1] ? max(array_map(XlsxPackage::columnIndex(...), $refs[1])) + 1 : 0;

                foreach (self::STATUS_HEADERS as $offset => $header) {
                    $values[$firstStatusColumn + $offset] = $header;
                }
            } elseif ($firstStatusColumn !== null && ($result = $results->get($keys->get($rowNumber)))) {
                $statusColor = $statuses->all()[$result->status]['color'] ?? 'slate';
                $values = [
                    $firstStatusColumn => $statuses->label($result->status),
                    $firstStatusColumn + 1 => (string) $result->reason,
                    $firstStatusColumn + 2 => $result->status_at?->format('Y-m-d H:i') ?? '',
                ];
            }

            if ($values === []) {
                return $match[0];
            }

            $first = $firstStatusColumn;
            $cells = XlsxSheetEditor::setCells($inner, $rowNumber, $values, $first, function (?int $baseXf) use ($first, $statusColor, $styleBook) {
                $styles = array_fill_keys(range($first, $first + count(self::STATUS_HEADERS) - 1), $baseXf);

                if ($statusColor) {
                    $styles[$first] = $styleBook->statusStyle($baseXf, $statusColor) ?? $baseXf;
                }

                return $styles;
            });

            return $cells === null ? $match[0] : '<row'.preg_replace('/\sspans="[^"]*"/', '', $match[1]).'>'.$cells.'</row>';
        }, $chunk) ?? throw new RuntimeException('Gagal memproses baris: '.preg_last_error_msg());
    }

    /**
     * Path XML sheet pertama & hyperlink-nya (referensi sel => URL).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function firstSheet(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('bukan file .xlsx yang valid.');
        }

        $sheetPath = array_values(XlsxPackage::sheetPaths($zip))[0] ?? throw new RuntimeException('file tidak berisi sheet.');
        $links = XlsxPackage::hyperlinks($zip, $sheetPath);
        $zip->close();

        // hyperlink pakai referensi "U5": kunci jadi "index kolom:nomor baris"
        $byCell = [];

        foreach ($links as $ref => $url) {
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $cell);
            $byCell[XlsxPackage::columnIndex($cell[1]).':'.$cell[2]] = $url;
        }

        return [$sheetPath, $byCell];
    }

    /**
     * Teks sharedStrings yang dibutuhkan saja: pendek (judul, penanda "1"/"Ya") atau berisi UUID (link).
     *
     * @return array<int, string>
     */
    private function sharedStrings(string $path): array
    {
        $strings = [];
        $reader = new XMLReader;

        if (! @$reader->open('zip://'.$path.'#xl/sharedStrings.xml')) {
            return [];
        }

        $index = -1;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                $index++;
                $text = $reader->readString();

                if (mb_strlen($text) <= 100 || preg_match(self::UUID_PATTERN, $text)) {
                    $strings[$index] = $text;
                }
            }
        }

        $reader->close();

        return $strings;
    }

    /**
     * Baris sheet satu per satu: nomor baris => [index kolom => nilai].
     *
     * @param  array<int, string>  $strings
     * @return Generator<int, array<int, string|null>>
     */
    private function rows(string $path, string $sheetPath, array $strings): Generator
    {
        $reader = new XMLReader;
        $reader->open('zip://'.$path.'#'.$sheetPath);

        // Lompat ke <row> pertama, lalu pindah antar-<row> bersaudara (read() + next() melewatkan baris)
        while ($reader->read() && ! ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row')) {
            // cari baris pertama
        }

        while ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
            $rowNumber = (int) $reader->getAttribute('r');
            preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $reader->readOuterXml(), $cells, PREG_SET_ORDER);
            $values = [];

            foreach ($cells as $cell) {
                if (! preg_match('/\br="([A-Z]+)\d+"/', $cell[1], $ref)) {
                    continue;
                }

                $inner = $cell[2] ?? '';
                $type = preg_match('/\bt="([^"]+)"/', $cell[1], $t) ? $t[1] : null;
                $raw = preg_match('/<v>(.*?)<\/v>/s', $inner, $v) ? $v[1] : (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $is) ? $is[1] : null);
                $value = $type === 's' ? ($strings[(int) $raw] ?? null) : ($raw !== null ? html_entity_decode($raw, ENT_XML1 | ENT_QUOTES, 'UTF-8') : null);
                $values[XlsxPackage::columnIndex($ref[1])] = $value;
            }

            yield $rowNumber => $values;

            if (! $reader->next('row')) {
                break;
            }
        }

        $reader->close();
    }
}
