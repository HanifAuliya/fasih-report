<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris yang ditandai perlu dikerjakan tapi kolom wajibnya masih kosong (mis. "KBLI Baru"):
 * tampil sebagai "Belum siap", tidak dihitung sampai Excel diupload ulang dengan isian terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('target_rows', function (Blueprint $table) {
            $table->boolean('not_ready')->default(false)->after('duplicate_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('target_rows', function (Blueprint $table) {
            $table->dropColumn('not_ready');
        });
    }
};
