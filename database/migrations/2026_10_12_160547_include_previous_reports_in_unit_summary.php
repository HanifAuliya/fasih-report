<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Status unit kini = rangkuman semua laporan yang dipakai (bukan satu laporan aktif). Laporan lama di riwayat
 * yang berhasil diproses ikut dipakai; statusnya diterapkan saat "Proses ulang semua file" / upload berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('report_files')
            ->where('category', 'report')
            ->whereIn('extension', ['json', 'csv'])
            ->whereNotNull('kecamatan_id')
            ->where('is_active', false)
            ->where(fn ($query) => $query->whereNull('summary')->orWhere('summary', 'not like', 'Gagal%'))
            ->update(['is_active' => true]);
    }

    public function down(): void
    {
        // Tidak dikembalikan: dulu hanya satu laporan aktif per unit, mana yang aktif tidak lagi diketahui
    }
};
