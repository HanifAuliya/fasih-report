<?php

namespace App\Providers;

use App\Models\User;
use App\Support\RetryingMySqlConnector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Database hosting bersama kadang penuh sesaat: tunggu & coba lagi daripada langsung error 500
        $this->app->bind('db.connector.mysql', fn () => new RetryingMySqlConnector);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Semua halaman bisa dilihat publik; hanya admin (user yang login) boleh mengubah data.
        Gate::define('manage', fn (User $user): bool => true);

        // Buang indentasi di awal baris template saat dikompilasi: HTML (halaman & update Livewire)
        // jauh lebih kecil tanpa mengubah tampilan. Spasi di tengah baris tidak disentuh.
        Blade::precompiler(fn (string $template): string => preg_replace('/^[ \t]+/m', '', $template));

        // Halaman "Menyiapkan file" memuat ulang tiap 4 detik (15/menit), jadi 30/menit masih longgar untuk manusia
        RateLimiter::for('downloads', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
    }
}
