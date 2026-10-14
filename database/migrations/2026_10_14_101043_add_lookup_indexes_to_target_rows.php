<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index untuk query yang paling sering jalan: tabel data per sheet (urut nomor baris) dan
 * pencarian baris unit lewat kuncinya (laporan JSON, hitung ulang status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('target_rows', function (Blueprint $table) {
            $table->index(['target_sheet_id', 'row_number']);
            $table->index(['kecamatan_id', 'row_key']);
        });
    }

    public function down(): void
    {
        Schema::table('target_rows', function (Blueprint $table) {
            $table->dropIndex(['target_sheet_id', 'row_number']);
            $table->dropIndex(['kecamatan_id', 'row_key']);
        });
    }
};
