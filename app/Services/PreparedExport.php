<?php

namespace App\Services;

use App\Models\Project;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Download Excel satu pekerjaan. Pekerjaan kecil dibuat langsung; pekerjaan besar disiapkan di latar belakang
 * (halaman "Menyiapkan file…" memuat ulang sendiri sampai siap), lalu file disimpan dan dipakai lagi
 * selama data pekerjaan belum berubah. Dengan begitu unduhan besar tidak terputus batas waktu gateway (504).
 */
class PreparedExport
{
    /** Proses yang "berjalan" lebih lama dari ini dianggap macet dan dimulai ulang. */
    private const STALE_MINUTES = 15;

    /**
     * @param  Closure(): array{path: string, filename: string}  $build  membuat file sementara
     */
    public function serve(Project $project, string $name, Closure $build): Response
    {
        $data = $this->dataVersion($project);

        if ($data['rows'] <= config('fasih.sync_export_rows')) {
            ['path' => $path, 'filename' => $filename] = $build();

            return response()->download($path, $filename)->deleteFileAfterSend();
        }

        $key = "export:{$project->id}:{$name}";
        $state = Cache::get($key);

        if (($state['status'] ?? null) === 'ready' && $state['version'] === $data['version'] && File::exists($state['path'])) {
            return response()->download($state['path'], $state['filename']);
        }

        $running = ($state['status'] ?? null) === 'running'
            && $state['version'] === $data['version']
            && (int) ($state['at'] ?? 0) > now()->subMinutes(self::STALE_MINUTES)->getTimestamp();

        if (! $running) {
            $this->start($key, $project, $name, $data['version'], $build);
        }

        return response()->view('exports.preparing', [
            'project' => $project,
            'failed' => ($state['status'] ?? null) === 'failed' && ! $running ? $state['message'] : null,
        ], 202, ['Refresh' => '3', 'Cache-Control' => 'no-store']);
    }

    /**
     * @param  Closure(): array{path: string, filename: string}  $build
     */
    private function start(string $key, Project $project, string $name, string $version, Closure $build): void
    {
        Cache::put($key, ['status' => 'running', 'version' => $version, 'at' => now()->getTimestamp()], now()->addHours(2));

        defer(function () use ($key, $project, $name, $version, $build) {
            set_time_limit(0);
            ignore_user_abort(true);

            try {
                ['path' => $temp, 'filename' => $filename] = $build();

                $path = Storage::disk('local')->path("exports/{$project->id}-{$name}.xlsx");
                File::ensureDirectoryExists(dirname($path));
                File::move($temp, $path);

                Cache::put($key, ['status' => 'ready', 'version' => $version, 'path' => $path, 'filename' => $filename, 'at' => now()->getTimestamp()], now()->addDays(7));
            } catch (Throwable $e) {
                report($e);
                Cache::put($key, ['status' => 'failed', 'version' => $version, 'message' => $e->getMessage(), 'at' => now()->getTimestamp()], now()->addDay());
            }
        });
    }

    /**
     * Jumlah baris & sidik data pekerjaan: berubah setiap ada baris/status/laporan/pengaturan yang berubah.
     *
     * @return array{rows: int, version: string}
     */
    private function dataVersion(Project $project): array
    {
        $rows = DB::table('target_rows')
            ->where('project_id', $project->id)
            ->selectRaw('count(*) as total, max(id) as last_id, max(updated_at) as changed_at')
            ->first();

        return [
            'rows' => (int) $rows->total,
            'version' => md5(json_encode([
                $rows,
                DB::table('report_files')->where('project_id', $project->id)->max('updated_at'),
                DB::table('kecamatans')->where('project_id', $project->id)->selectRaw('count(*) as total, max(updated_at) as changed_at')->first(),
                $project->updated_at,
            ])),
        ];
    }
}
