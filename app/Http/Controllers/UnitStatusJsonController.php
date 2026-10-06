<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TargetRow;
use Illuminate\Http\Response;

/**
 * Download JSON status terkini satu unit (hasil laporan + perubahan manual di web),
 * dengan format yang sama seperti laporan script sehingga bisa diupload ulang.
 */
class UnitStatusJsonController extends Controller
{
    public function __invoke(Project $project, string $kode): Response
    {
        $unit = $project->kecamatans()->where('kode', $kode)->firstOrFail();
        $statuses = $project->config()->statuses();

        $queue = TargetRow::where('kecamatan_id', $unit->id)
            ->tracked()
            ->with('sheet:id,name')
            ->orderBy('target_sheet_id')
            ->orderBy('row_number')
            ->get()
            ->map(fn (TargetRow $row) => [
                'id' => $row->row_key,
                'sheet' => $row->sheet?->name,
                'row' => $row->row_number,
                'status' => $row->status,
                'status_label' => $statuses->label($row->status),
                'reason' => $row->reason,
                'doneAt' => $row->status_at?->toIso8601String(),
            ]);

        $json = json_encode([
            'v' => 1,
            'project' => $project->name,
            'unit' => $unit->nama,
            'generated_at' => now()->toIso8601String(),
            'queue' => $queue,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $filename = sprintf('%s_%s_%s_status_%s.json', str($project->name)->slug('_'), $unit->kode, str($unit->nama)->slug('_'), now()->format('Ymd-Hi'));

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
