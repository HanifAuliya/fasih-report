<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unit lain yang barisnya ikut diubah sebuah laporan (diteruskan). Saat status satu unit dihitung ulang,
 * hanya laporan unit lain yang memang menyentuh unit itu yang dibaca. Null = belum diketahui (dihitung lagi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_files', function (Blueprint $table) {
            $table->json('forwarded_units')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('report_files', function (Blueprint $table) {
            $table->dropColumn('forwarded_units');
        });
    }
};
