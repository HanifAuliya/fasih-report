<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris yang kuncinya sudah dimiliki unit lain (mis. Bagian 2 berisi baris yang sudah ada di Bagian 1):
 * tetap tersimpan & tampil, tapi tidak dihitung sebagai target unit ini. Bila unit pemiliknya dihapus,
 * penanda hilang dan baris kembali dihitung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('target_rows', function (Blueprint $table) {
            $table->foreignId('duplicate_of_id')->nullable()->after('row_key')->constrained('kecamatans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('target_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_id');
        });
    }
};
