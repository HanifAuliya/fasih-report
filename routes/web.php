<?php

use App\Http\Controllers\DeployController;
use App\Http\Controllers\GithubWebhookController;
use App\Http\Controllers\RawScriptController;
use App\Http\Controllers\ReportFileController;
use App\Http\Controllers\TargetExportController;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Projects\Index as ProjectIndex;
use App\Livewire\Projects\KecamatanData;
use App\Livewire\Projects\Show as ProjectShow;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// URL publik untuk script (dipakai Tampermonkey @updateURL / @downloadURL)
Route::get('/raw/{project}/{filename}', RawScriptController::class)
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('scripts.raw');

// Dipanggil GitHub Actions setelah upload FTP: migrate + refresh cache (butuh header X-Deploy-Token)
Route::post('/_deploy', DeployController::class)
    ->middleware('throttle:5,1')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('deploy');

// Webhook push GitHub: script yang terhubung ke repo langsung disinkronkan (diverifikasi dengan secret)
Route::post('/webhooks/github', GithubWebhookController::class)
    ->middleware('throttle:30,1')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('webhooks.github');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
});

// Halaman publik (hanya lihat). Semua aksi ubah data dicek di komponen dengan Gate "manage".
Route::livewire('/', Dashboard::class)->name('dashboard');
Route::livewire('/data', ProjectIndex::class)->name('projects.index');
Route::livewire('/data/{project}', ProjectShow::class)->name('projects.show');
Route::livewire('/data/{project}/kecamatan/{kode}', KecamatanData::class)->name('projects.kecamatan');
Route::get('/data/{project}/kecamatan/{kode}/excel', TargetExportController::class)->name('projects.kecamatan.export');

Route::get('/files/{file}/download', [ReportFileController::class, 'download'])->name('files.download');
Route::get('/files/{file}/view', [ReportFileController::class, 'view'])->name('files.view');

Route::middleware('auth')->group(function () {
    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard');
    })->name('logout');
});
