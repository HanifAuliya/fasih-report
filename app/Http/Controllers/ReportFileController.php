<?php

namespace App\Http\Controllers;

use App\Models\ReportFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * File dikirim langsung dari disk dengan Content-Type berdasarkan ekstensi, tanpa deteksi MIME
 * Flysystem (butuh ekstensi PHP fileinfo yang tidak selalu ada di shared hosting).
 */
class ReportFileController extends Controller
{
    private const CONTENT_TYPES = [
        'json' => 'application/json',
        'csv' => 'text/csv; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel',
    ];

    public function download(ReportFile $file): BinaryFileResponse
    {
        return response()->download($this->localPath($file), $file->original_name, [
            'Content-Type' => $this->contentType($file),
        ]);
    }

    /**
     * Kirim file apa adanya (inline) untuk preview di browser.
     */
    public function view(ReportFile $file): BinaryFileResponse
    {
        return response()->file($this->localPath($file), [
            'Content-Type' => $this->contentType($file),
            'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
        ]);
    }

    private function localPath(ReportFile $file): string
    {
        abort_unless(Storage::disk('local')->exists($file->path), 404, 'File tidak ditemukan di server.');

        return Storage::disk('local')->path($file->path);
    }

    private function contentType(ReportFile $file): string
    {
        return self::CONTENT_TYPES[strtolower((string) $file->extension)] ?? 'application/octet-stream';
    }
}
