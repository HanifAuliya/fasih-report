<?php

namespace App\Console\Commands;

use App\Models\Script;
use App\Services\GithubScriptSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('scripts:sync-github {--force : Ambil ulang walau isi file tidak berubah}')]
#[Description('Sinkronkan semua script yang terhubung ke GitHub')]
class SyncGithubScripts extends Command
{
    public function handle(GithubScriptSync $sync): int
    {
        $scripts = Script::whereNotNull('github_repo')->whereNotNull('github_path')->get();

        foreach ($scripts as $script) {
            try {
                $changed = $sync->sync($script, (bool) $this->option('force'));
                $this->line(($changed ? '✔ diperbarui ' : '· sama ').$script->filename.' v'.$script->version);
            } catch (Throwable $e) {
                $this->error('✘ '.$script->filename.': '.$e->getMessage());
            }
        }

        $this->info($scripts->count().' script diperiksa.');

        return self::SUCCESS;
    }
}
