<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

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

        // Cache konfigurasi lama dibuang dulu supaya isi .env terbaru (mis. akun admin) terbaca
        Artisan::call('optimize:clear');
        Artisan::call('migrate', ['--force' => true]);
        $output[] = Artisan::output();

        if (User::query()->doesntExist()) {
            Artisan::call('db:seed', ['--force' => true]);
            $output[] = Artisan::output();
        }

        $output[] = $this->syncAdmin();

        Artisan::call('optimize');
        $output[] = 'Cache diperbarui: '.now()->toDateTimeString();

        return response(implode("\n", $output), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Akun admin mengikuti ADMIN_EMAIL / ADMIN_PASSWORD di .env server (tanpa SSH: ganti .env lalu deploy).
     * ADMIN_EMAIL boleh berupa username, mis. "admin".
     */
    private function syncAdmin(): string
    {
        $login = trim((string) config('fasih.admin_email'));
        $password = (string) config('fasih.admin_password');

        if ($login === '' || $password === '') {
            return 'Akun admin: tidak diubah (ADMIN_EMAIL/ADMIN_PASSWORD kosong).';
        }

        $user = User::firstOrNew(['email' => $login]);

        if ($user->exists && Hash::check($password, $user->password)) {
            return "Akun admin: {$login} (tidak berubah).";
        }

        $user->fill(['name' => $user->name ?: 'Admin', 'password' => $password])->save();

        return "Akun admin: {$login} diperbarui.";
    }
}
