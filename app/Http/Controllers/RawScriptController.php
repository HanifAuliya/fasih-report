<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\GithubScriptSync;
use Illuminate\Http\Response;

use function Illuminate\Support\defer;

class RawScriptController extends Controller
{
    public function __invoke(Project $project, string $filename, GithubScriptSync $sync): Response
    {
        $script = $project->scripts()->where('filename', $filename)->firstOrFail();

        abort_unless($script->is_public || auth()->check(), 404);

        // Tampermonkey mengecek URL ini untuk update: ambil kode GitHub terbaru setelah respons terkirim,
        // supaya pengecekan tidak ikut menunggu GitHub (cek berikutnya mendapat kode barunya)
        defer(fn () => $sync->syncIfStale($script));

        $type = match ($script->language) {
            'javascript' => 'text/javascript',
            'json' => 'application/json',
            default => 'text/plain',
        };

        return response($script->servedCode(), 200, [
            'Content-Type' => $type.'; charset=utf-8',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Last-Modified' => $script->updated_at->toRfc7231String(),
        ]);
    }
}
