<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Dipanggil GitHub Actions setelah upload FTP (hosting tanpa SSH, mis. Hostinger):
 * jalankan migrasi, seed awal jika belum ada user, lalu refresh cache.
 */
class DeployController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = (string) config('fasih.deploy_token');

        abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Deploy-Token')), 404);

        $output = [];

        Artisan::call('migrate', ['--force' => true]);
        $output[] = Artisan::output();

        if (User::query()->doesntExist()) {
            Artisan::call('db:seed', ['--force' => true]);
            $output[] = Artisan::output();
        }

        Artisan::call('optimize:clear');
        Artisan::call('optimize');
        $output[] = 'Cache diperbarui: '.now()->toDateTimeString();

        return response(implode("\n", $output), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
