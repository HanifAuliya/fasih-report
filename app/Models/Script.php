<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'name', 'filename', 'language', 'version', 'code', 'notes', 'is_public', 'github_repo', 'github_branch', 'github_path', 'github_sha', 'github_commit', 'github_commit_message', 'synced_at', 'sync_error'])]
class Script extends Model
{
    public const LANGUAGES = [
        'javascript' => 'JavaScript',
        'php' => 'PHP',
        'python' => 'Python',
        'sql' => 'SQL',
        'json' => 'JSON',
        'bash' => 'Shell',
        'plaintext' => 'Teks',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Script yang kodenya diambil dari file di repo GitHub.
     */
    public function isFromGithub(): bool
    {
        return filled($this->github_repo) && filled($this->github_path);
    }

    public function githubUrl(): ?string
    {
        return $this->isFromGithub()
            ? "https://github.com/{$this->github_repo}/blob/{$this->github_branch}/{$this->github_path}"
            : null;
    }

    public function githubCommitUrl(): ?string
    {
        return $this->isFromGithub() && $this->github_commit
            ? "https://github.com/{$this->github_repo}/commit/{$this->github_commit}"
            : null;
    }

    /**
     * Kode yang disajikan ke Tampermonkey: @updateURL & @downloadURL diarahkan ke URL web ini,
     * supaya semua yang memasang script ikut update otomatis.
     */
    public function servedCode(): string
    {
        if (! $this->isUserscript() || ! preg_match('~//\s*==UserScript==.*?//\s*==/UserScript==~s', $this->code, $header)) {
            return $this->code;
        }

        $url = $this->rawUrl();
        $block = preg_replace('~^[ \t]*//\s*@(updateURL|downloadURL)\b.*\R?~mi', '', $header[0]);
        $block = preg_replace(
            '~(//\s*==/UserScript==)~',
            '// @updateURL    '.$url."\n".'// @downloadURL  '.$url."\n".'$1',
            $block,
            1,
        );

        return str_replace($header[0], $block, $this->code);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ScriptVersion::class)->latest('id');
    }

    public function rawUrl(): string
    {
        return route('scripts.raw', [$this->project, $this->filename]);
    }

    public function isUserscript(): bool
    {
        return str_ends_with($this->filename, '.user.js');
    }

    /**
     * Potongan awal kode untuk ditampilkan; sisanya dimuat di browser hanya bila diminta
     * (script ribuan baris yang langsung di-highlight membuat halaman berat).
     *
     * @return array{text: string, lines: int, truncated: bool}
     */
    public static function preview(?string $code, int $maxLines = 300): array
    {
        $code = (string) $code;
        $lines = substr_count($code, "\n") + 1;

        if ($lines <= $maxLines) {
            return ['text' => $code, 'lines' => $lines, 'truncated' => false];
        }

        return [
            'text' => implode("\n", array_slice(explode("\n", $code, $maxLines + 1), 0, $maxLines)),
            'lines' => $lines,
            'truncated' => true,
        ];
    }
}
