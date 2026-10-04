<?php

namespace App\Services;

use App\Models\Script;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Ambil kode script dari file di repo GitHub (publik, atau privat dengan token).
 * Versi lama otomatis masuk riwayat setiap kali isi file di GitHub berubah.
 */
class GithubScriptSync
{
    /**
     * Baca link GitHub: halaman file (github.com/o/r/blob/branch/path) atau raw
     * (raw.githubusercontent.com/o/r/branch/path, termasuk refs/heads/branch).
     *
     * @return array{repo: string, branch: string, path: string}|null
     */
    public static function parseUrl(string $url): ?array
    {
        $url = trim($url);

        if (preg_match('~^https?://github\.com/([^/\s]+/[^/\s]+)/(?:blob|raw)/([^/\s]+)/(.+)$~i', $url, $m)) {
            return ['repo' => $m[1], 'branch' => $m[2], 'path' => self::cleanPath($m[3])];
        }

        if (preg_match('~^https?://raw\.githubusercontent\.com/([^/\s]+/[^/\s]+)/(?:refs/heads/)?([^/\s]+)/(.+)$~i', $url, $m)) {
            return ['repo' => $m[1], 'branch' => $m[2], 'path' => self::cleanPath($m[3])];
        }

        return null;
    }

    private static function cleanPath(string $path): string
    {
        return rawurldecode(strtok($path, '?#'));
    }

    /**
     * Sinkronkan satu script. Mengembalikan true jika kode berubah.
     *
     * @throws RuntimeException jika GitHub tidak bisa diakses / file tidak ditemukan
     */
    public function sync(Script $script, bool $force = false): bool
    {
        if (! $script->isFromGithub()) {
            throw new RuntimeException('Script ini tidak terhubung ke GitHub.');
        }

        try {
            $file = $this->request()->get(
                "https://api.github.com/repos/{$script->github_repo}/contents/".implode('/', array_map('rawurlencode', explode('/', $script->github_path))),
                ['ref' => $script->github_branch],
            );

            if ($file->status() === 404) {
                throw new RuntimeException("File {$script->github_path} tidak ditemukan di {$script->github_repo} ({$script->github_branch}). Untuk repo privat, isi GITHUB_TOKEN.");
            }

            $file->throw();

            $sha = $file->json('sha');

            if (! $force && $sha === $script->github_sha) {
                $script->update(['synced_at' => now(), 'sync_error' => null]);

                return false;
            }

            $code = base64_decode((string) $file->json('content'));
            $commit = $this->latestCommit($script);
            $changed = $code !== $script->code;

            if ($changed && $script->exists && filled($script->code)) {
                $script->versions()->create([
                    'version' => $script->version,
                    'code' => $script->code,
                    'notes' => 'Sebelum sinkron GitHub'.($commit ? ' '.substr($commit['sha'], 0, 7).': '.$commit['message'] : ''),
                ]);
            }

            $script->update([
                'code' => $code,
                'version' => self::versionOf($code) ?? $script->version,
                'github_sha' => $sha,
                'github_commit' => $commit['sha'] ?? $script->github_commit,
                'github_commit_message' => $commit['message'] ?? $script->github_commit_message,
                'synced_at' => now(),
                'sync_error' => null,
            ]);

            return $changed;
        } catch (Throwable $e) {
            $script->update(['sync_error' => mb_substr($e->getMessage(), 0, 500)]);

            throw $e instanceof RuntimeException ? $e : new RuntimeException('Gagal mengambil dari GitHub: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Sinkronkan jika terakhir sinkron sudah lewat beberapa menit (dipanggil saat script dibuka).
     */
    public function syncIfStale(Script $script): void
    {
        $minutes = (int) config('fasih.github_sync_minutes', 10);

        if (! $script->isFromGithub() || ($script->synced_at && $script->synced_at->gt(now()->subMinutes($minutes)))) {
            return;
        }

        rescue(fn () => $this->sync($script), report: false);
    }

    /**
     * @return array{sha: string, message: string}|null
     */
    private function latestCommit(Script $script): ?array
    {
        $commits = rescue(fn () => $this->request()->get("https://api.github.com/repos/{$script->github_repo}/commits", [
            'path' => $script->github_path,
            'sha' => $script->github_branch,
            'per_page' => 1,
        ])->throw()->json(), null, false);

        if (empty($commits[0]['sha'])) {
            return null;
        }

        return [
            'sha' => $commits[0]['sha'],
            'message' => mb_substr(strtok((string) ($commits[0]['commit']['message'] ?? ''), "\n"), 0, 200),
        ];
    }

    public static function versionOf(string $code): ?string
    {
        return preg_match('/^\s*\/\/\s*@version\s+(\S+)/m', $code, $m) ? $m[1] : null;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => config('app.name')])
            ->when(config('fasih.github_token'), fn (PendingRequest $request, string $token) => $request->withToken($token))
            ->timeout(10)
            ->retry(2, 300, throw: false);
    }
}
