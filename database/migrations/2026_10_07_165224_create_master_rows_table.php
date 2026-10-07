<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris file induk (Excel utuh, bisa ~90 ribu baris) per pekerjaan: cukup nomor baris, kunci, dan penanda
 * "perlu dikerjakan". Status diambil dari baris unit (Bagian) yang kuncinya sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('row_key', 64)->nullable();
            $table->boolean('is_task')->default(true);

            $table->index(['project_id', 'row_key']);
        });

        // Nomor baris judul kolom di file induk (untuk menulis kolom status saat diunduh)
        Schema::table('report_files', function (Blueprint $table) {
            $table->unsignedInteger('header_row')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_rows');

        Schema::table('report_files', function (Blueprint $table) {
            $table->dropColumn('header_row');
        });
    }
};
