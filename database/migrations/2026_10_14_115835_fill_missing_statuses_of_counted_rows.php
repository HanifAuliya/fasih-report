<?php

use App\Models\Kecamatan;
use App\Models\TargetRow;
use App\Services\TargetImporter;
use App\Services\UnitReportService;
use Illuminate\Database\Migrations\Migration;

/**
 * Perbaikan data: baris yang dulu kembar lalu unit pemiliknya dihapus kembali dihitung tanpa status.
 * Unit yang punya baris seperti itu diberi status awal lalu laporannya diterapkan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        $unitIds = TargetRow::query()->tracked()->whereNull('status')->distinct()->pluck('kecamatan_id');

        foreach (Kecamatan::with('project')->whereKey($unitIds)->get() as $unit) {
            app(TargetImporter::class)->rekey($unit);
            app(UnitReportService::class)->reapply($unit);
        }
    }

    public function down(): void
    {
        // Data saja, tidak ada yang dikembalikan
    }
};
