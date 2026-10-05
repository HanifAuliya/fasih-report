<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ZipArchive;

/**
 * Pengganti upload FTP: GitHub Actions mengirim satu file zip hasil build lewat HTTPS,
 * lalu isinya diekstrak menimpa kode aplikasi. Migrasi dijalankan terpisah lewat POST /_deploy
 * (request baru) supaya memakai kode yang sudah diperbarui.
 */
class DeployPackageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = (string) config('fasih.deploy_token');

        abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Deploy-Token')), 404);

        $request->validate([
            'package' => ['required', 'file'],
            'sha' => ['nullable', 'string', 'regex:/^[0-9a-f]{7,40}$/'],
        ]);

        // Ekstrak ribuan file bisa melewati batas waktu default hosting
        @set_time_limit(300);

        $zip = new ZipArchive;

        abort_unless($zip->open($request->file('package')->getRealPath()) === true, 422, 'File zip tidak valid.');

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));

            if ($this->isUnsafeEntry($name)) {
                $zip->close();
                abort(422, "Isi zip tidak diizinkan: {$name}");
            }
        }

        $target = config('fasih.deploy_target') ?: base_path();
        $count = $zip->numFiles;
        $extracted = $zip->extractTo($target);
        $zip->close();

        abort_unless($extracted, 500, 'Gagal mengekstrak zip.');

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // Commit yang terpasang: deploy berikutnya cukup mengirim file yang berubah sejak commit ini
        if ($request->filled('sha')) {
            file_put_contents(self::shaPath(), $request->input('sha'));
        }

        return response("{$count} file diperbarui.", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * File berisi commit yang terakhir dipasang (dibaca DeployStatusController).
     */
    public static function shaPath(): string
    {
        return storage_path('app/deployed-sha.txt');
    }

    /**
     * Tolak path yang keluar dari folder aplikasi atau menimpa .env server.
     */
    private function isUnsafeEntry(string $name): bool
    {
        return str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || in_array('..', explode('/', $name), true)
            || preg_match('#^\.env($|\.)#', $name) === 1;
    }
}
