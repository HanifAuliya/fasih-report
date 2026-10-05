<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Script koreksi (mis. gaji) melaporkan status "already" (sudah sesuai) dan "manual" (perlu dicek).
 * Tambahkan sebagai alias pada pekerjaan "Umum" yang sudah ada; preset baru sudah memuatnya.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const ALIASES = ['unchanged' => 'already', 'yellow' => 'manual'];

    public function up(): void
    {
        DB::table('projects')->where('type', 'kolom_kunci')->get(['id', 'settings'])->each(function (object $project) {
            $settings = json_decode((string) $project->settings, true);

            if (! is_array($settings['statuses'] ?? null)) {
                return;
            }

            foreach ($settings['statuses'] as &$status) {
                $alias = self::ALIASES[$status['code'] ?? ''] ?? null;
                $aliases = $status['aliases'] ?? [];
                $aliases = is_string($aliases) ? array_map('trim', explode(',', $aliases)) : (array) $aliases;

                if ($alias !== null && ! in_array($alias, $aliases, true)) {
                    $status['aliases'] = [...$aliases, $alias];
                }
            }

            DB::table('projects')->where('id', $project->id)->update(['settings' => json_encode($settings)]);
        });
    }

    public function down(): void
    {
        //
    }
};
