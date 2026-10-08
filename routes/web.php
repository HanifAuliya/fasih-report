<?php

use App\Http\Controllers\DeployController;
use App\Http\Controllers\DeployPackageController;
use App\Http\Controllers\DeployStatusController;
use App\Http\Controllers\GithubWebhookController;
use App\Http\Controllers\MasterExportController;
use App\Http\Controllers\PendingExportController;
use App\Http\Controllers\ProjectChangesExportController;
use App\Http\Controllers\ProjectCombinedExportController;
use App\Http\Controllers\RawScriptController;
use App\Http\Controllers\ReportFileController;
use App\Http\Controllers\TargetExportController;
use App\Http\Controllers\UnitStatusJsonController;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Projects\Index as ProjectIndex;
use App\Livewire\Projects\KecamatanData;
use App\Livewire\Projects\Show as ProjectShow;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// URL publik untuk script (dipakai Tampermonkey @updateURL / @downloadURL)
Route::get('/raw/{project}/{filename}', RawScriptController::class)
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('scripts.raw');

// Dipanggil GitHub Actions: commit yang terpasang di server (butuh header X-Deploy-Token)
Route::post('/_deploy/status', DeployStatusController::class)
    ->middleware('throttle:10,1')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('deploy.status');

// Dipanggil GitHub Actions: kirim zip hasil build lalu diekstrak ke folder aplikasi (butuh header X-Deploy-Token)
Route::post('/_deploy/package', DeployPackageController::class)
    ->middleware('throttle:5,1')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('deploy.package');

// Dipanggil GitHub Actions setelah kode diperbarui: migrate + refresh cache (butuh header X-Deploy-Token)
Route::post('/_deploy', DeployController::class)
    ->middleware('throttle:5,1')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('deploy');

// Webhook push GitHub: script yang terhubung ke repo langsung disinkronkan (diverifikasi dengan secret)
Route::post('/webhooks/github', GithubWebhookController::class)
    ->middleware('throttle:30,1')
    ->withoutMiddleware(PreventRequestForgery::class)
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
Route::get('/data/{project}/kecamatan/{kode}/json', UnitStatusJsonController::class)->name('projects.kecamatan.json');
Route::get('/data/{project}/rekap-perubahan', ProjectChangesExportController::class)->name('projects.changes.export');
Route::get('/data/{project}/excel-gabungan', ProjectCombinedExportController::class)->name('projects.combined.export');
Route::get('/data/{project}/excel-induk', MasterExportController::class)->name('projects.master.export');
Route::get('/data/{project}/belum-selesai', PendingExportController::class)->name('projects.pending.export');
Route::get('/data/{project}/json', UnitStatusJsonController::class)->name('projects.json');
Route::get('/data/{project}/kecamatan/{kode}/belum-selesai', PendingExportController::class)->name('projects.kecamatan.pending');

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
