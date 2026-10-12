<?php

use App\Models\Kecamatan;
use App\Services\TargetImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isi kolom rekap (mis. Kecamatan) disimpan per baris saat impor, supaya rekap per kecamatan cukup satu
 * query GROUP BY, tidak membaca isi semua baris setiap kali halaman pekerjaan dibuka.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Aman diulang bila pengisian di bawah sempat terputus
        if (! Schema::hasColumn('target_rows', 'recap')) {
            Schema::table('target_rows', function (Blueprint $table) {
                $table->string('recap', 100)->nullable()->after('not_ready');
            });
        }

        $importer = app(TargetImporter::class);
        Kecamatan::with('project')->whereHas('targetRows')->each(fn (Kecamatan $unit) => $importer->refreshRecap($unit));
    }

    public function down(): void
    {
        Schema::table('target_rows', function (Blueprint $table) {
            $table->dropColumn('recap');
        });
    }
};
