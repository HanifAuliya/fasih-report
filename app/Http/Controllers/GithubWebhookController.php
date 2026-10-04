<?php

namespace App\Http\Controllers;

use App\Models\Script;
use App\Services\GithubScriptSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Webhook "push" dari GitHub: script yang terhubung ke repo & branch itu langsung disinkronkan.
 * Pasang di GitHub: Settings → Webhooks → Payload URL https://domainmu/webhooks/github,
 * Content type application/json, Secret = GITHUB_WEBHOOK_SECRET.
 */
class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, GithubScriptSync $sync): JsonResponse
    {
        $secret = (string) config('fasih.github_webhook_secret');
        $signature = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        abort_if($secret === '' || ! hash_equals($signature, (string) $request->header('X-Hub-Signature-256')), 403);

        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['ok' => true, 'message' => 'pong']);
        }

        $repo = (string) $request->input('repository.full_name');
        $branch = preg_replace('~^refs/heads/~', '', (string) $request->input('ref'));

        $changedPaths = collect($request->input('commits', []))
            ->flatMap(fn (array $commit) => [...($commit['added'] ?? []), ...($commit['modified'] ?? [])])
            ->unique();

        $scripts = Script::where('github_repo', $repo)->where('github_branch', $branch)->get()
            ->filter(fn (Script $script) => $changedPaths->isEmpty() || $changedPaths->contains($script->github_path));

        $results = $scripts->mapWithKeys(function (Script $script) use ($sync) {
            try {
                return [$script->filename => $sync->sync($script) ? 'updated' : 'unchanged'];
            } catch (Throwable $e) {
                return [$script->filename => 'error: '.$e->getMessage()];
            }
        });

        return response()->json(['ok' => true, 'scripts' => $results]);
    }
}
