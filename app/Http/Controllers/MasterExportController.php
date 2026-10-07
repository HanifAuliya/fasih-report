<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\MasterWorkbook;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unduh Excel induk + status yang sudah disiapkan (lihat MasterWorkbook::runExport).
 */
class MasterExportController extends Controller
{
    public function __invoke(Project $project, MasterWorkbook $master): BinaryFileResponse
    {
        $state = $master->exportState($project);

        abort_unless(($state['status'] ?? null) === 'ready' && Storage::disk('local')->exists($state['path']), 404, 'Excel induk belum disiapkan.');

        $filename = sprintf('%s_induk_status_%s.xlsx', str($project->name)->slug('_'), now()->parse($state['at'])->format('Ymd-Hi'));

        // Kirim langsung dari disk (tanpa deteksi MIME Flysystem yang bergantung pada ekstensi fileinfo)
        return response()->download(Storage::disk('local')->path($state['path']), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
