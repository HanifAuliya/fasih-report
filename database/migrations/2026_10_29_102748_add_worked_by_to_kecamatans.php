<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siapa / komputer mana yang sedang mengerjakan unit ini (mis. "Hanif", "PC Kantor 2"), supaya
 * pembagian kerja antar komputer terlihat di daftar unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kecamatans', function (Blueprint $table) {
            $table->string('worked_by', 60)->nullable()->after('catatan');
            $table->timestamp('worked_by_at')->nullable()->after('worked_by');
        });
    }

    public function down(): void
    {
        Schema::table('kecamatans', function (Blueprint $table) {
            $table->dropColumn(['worked_by', 'worked_by_at']);
        });
    }
};
