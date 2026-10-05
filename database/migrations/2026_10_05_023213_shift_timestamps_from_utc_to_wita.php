<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zona waktu aplikasi pindah dari UTC ke WITA (Asia/Makassar, UTC+8).
 * Waktu yang sudah tersimpan masih dalam jam UTC, jadi digeser +8 jam supaya tetap benar.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'users' => ['email_verified_at', 'created_at', 'updated_at'],
        'projects' => ['created_at', 'updated_at'],
        'kecamatans' => ['created_at', 'updated_at'],
        'scripts' => ['synced_at', 'created_at', 'updated_at'],
        'script_versions' => ['created_at', 'updated_at'],
        'report_files' => ['created_at', 'updated_at'],
        'target_sheets' => ['created_at', 'updated_at'],
        'target_rows' => ['status_at', 'created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shift(8);
    }

    public function down(): void
    {
        $this->shift(-8);
    }

    private function shift(int $hours): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            DB::table($table)->update(array_combine($columns, array_map(
                fn (string $column) => DB::raw("DATE_ADD(`{$column}`, INTERVAL {$hours} HOUR)"),
                $columns,
            )));
        }
    }
};
