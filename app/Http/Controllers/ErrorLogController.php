<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ringkasan error terakhir dari storage/logs/laravel.log untuk admin (hosting tanpa SSH).
 * Hanya baris judul tiap entri; angka panjang (mis. NIK) disamarkan.
 */
class ErrorLogController extends Controller
{
    /** Bagian akhir log yang dibaca (byte), supaya file log besar tidak dibaca seluruhnya. */
    private const TAIL_BYTES = 2_000_000;

    private const MAX_ENTRIES = 80;

    public function __invoke(Request $request): Response
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return $this->text('Belum ada log.');
        }

        $size = filesize($path);
        $handle = fopen($path, 'rb');
        fseek($handle, max(0, $size - self::TAIL_BYTES));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        preg_match_all('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(\w+): (.*)$/m', $tail, $matches, PREG_SET_ORDER);

        $entries = array_slice(array_filter($matches, fn (array $match) => $match[2] !== 'DEBUG' && $match[2] !== 'INFO'), -self::MAX_ENTRIES);

        $lines = array_map(fn (array $match) => $match[1].' '.$match[2].': '.$this->clean($match[3]), array_reverse($entries));

        $counts = [];
        foreach ($entries as $match) {
            $key = mb_substr($this->clean($match[3]), 0, 160);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);

        $summary = array_map(fn (string $message, int $count) => str_pad((string) $count, 4, ' ', STR_PAD_LEFT).'x  '.$message, array_keys($counts), $counts);

        return $this->text(implode("\n", [
            'Log: '.number_format($size / 1024).' KB, server '.now()->toDateTimeString().', memory_limit '.ini_get('memory_limit').', max_execution_time '.ini_get('max_execution_time'),
            '',
            '== Paling sering ==',
            ...$summary,
            '',
            '== Terbaru ==',
            ...$lines,
        ]));
    }

    private function clean(string $message): string
    {
        $message = preg_replace('/\d{10,}/', '[angka]', $message);
        $message = str_replace(base_path(), '', $message);

        return mb_substr($message, 0, 600);
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
    }
}
