<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pekerjaan OSS: status "OSS ganda" dipisah dari "OSS tutup". Laporan userscript menulis status
 * "closed" dengan linkResult "ganda"; baris lama seperti itu dipindah ke status baru (sama-sama selesai,
 * jadi progress tidak berubah).
 */
return new class extends Migration
{
    private const GANDA = ['code' => 'ganda', 'label' => 'OSS ganda', 'color' => 'fuchsia', 'done' => true, 'aliases' => ['oss ganda', 'selesai: oss ganda']];

    public function up(): void
    {
        DB::table('projects')->where('type', 'oss')->get(['id', 'settings'])->each(function (object $project) {
            $settings = json_decode((string) $project->settings, true);
            $statuses = $settings['statuses'] ?? null;

            if (! is_array($statuses) || collect($statuses)->contains('code', 'ganda')) {
                return;
            }

            $closed = collect($statuses)->search(fn (array $status) => ($status['code'] ?? null) === 'closed');
            array_splice($statuses, $closed === false ? count($statuses) : $closed + 1, 0, [self::GANDA]);
            $settings['statuses'] = $statuses;

            DB::table('projects')->where('id', $project->id)->update(['settings' => json_encode($settings)]);

            DB::table('target_rows')
                ->where('project_id', $project->id)
                ->where('status', 'closed')
                ->where('result', 'like', '%"linkResult":"ganda"%')
                ->update(['status' => 'ganda']);
        });
    }

    public function down(): void
    {
        DB::table('target_rows')->where('status', 'ganda')->update(['status' => 'closed']);
    }
};
