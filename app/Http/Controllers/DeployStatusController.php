<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Dipanggil GitHub Actions sebelum deploy: commit yang sedang terpasang di server,
 * supaya paket hanya berisi file yang berubah sejak commit itu (kosong = kirim semua).
 */
class DeployStatusController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = (string) config('fasih.deploy_token');

        abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Deploy-Token')), 404);

        $path = DeployPackageController::shaPath();
        $sha = is_file($path) ? trim((string) file_get_contents($path)) : '';

        return response($sha, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
