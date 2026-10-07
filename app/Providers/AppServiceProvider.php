<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
