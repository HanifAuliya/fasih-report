<?php

namespace App\Livewire\Projects;

use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\TargetSheet;
use App\Services\UnitReportService;
use App\Support\ProjectSettings;
use App\Support\StatusSet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Pengaturan cara membaca Excel & laporan per manajemen data, supaya bisa disesuaikan tiap kasus.
 */
class ProjectSettingsForm extends Component
{
    public Project $project;

    public string $unitLabel = '';

    public string $unitSource = ProjectSettings::UNIT_KECAMATAN;

    public string $keyMode = ProjectSettings::KEY_COLUMN;

    public string $keyColumn = '';

    public string $keyDetailColumn = '';

    public string $reportKeyFields = '';

    public string $recapColumn = '';

    public string $initialStatusColumn = '';

    public string $taskColumn = '';

    public string $taskValues = '';

    public string $taskConditions = '';

    public string $requiredColumns = '';

    public string $ignoredSheets = '';

    public string $displayColumns = '';

    /** @var list<array{code: string, label: string, color: string, done: bool, aliases: string}> */
    public array $statuses = [];

    /** Proses ulang sedang berjalan saat render terakhir (untuk memberi tahu komponen lain saat selesai). */
    public bool $reprocessRunning = false;

    public function mount(): void
    {
        $this->authorize('manage');

        $this->fillFrom($this->project->config()->toArray());
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function fillFrom(array $settings): void
    {
        $config = new ProjectSettings($settings);

        $this->unitLabel = $config->unitLabel();
        $this->unitSource = $config->unitSource();
        $this->keyMode = $config->keyMode();
        $this->keyColumn = (string) $config->keyColumn();
        $this->keyDetailColumn = (string) $config->keyDetailColumn();
        $this->reportKeyFields = implode(', ', $config->reportKeyFields());
        $this->recapColumn = (string) $config->recapColumn();
        $this->initialStatusColumn = (string) $config->initialStatusColumn();
        $this->taskColumn = (string) $config->taskColumn();
        $this->taskValues = implode(', ', $config->taskValues());
        $this->taskConditions = implode('; ', array_map(fn (array $condition) => "{$condition['column']} {$condition['operator']} {$condition['value']}", $config->taskConditions()));
        $this->requiredColumns = implode(', ', $config->requiredColumns());
        $this->ignoredSheets = implode(', ', $config->ignoredSheets());
        $this->displayColumns = implode(', ', $config->displayColumns());
        $this->statuses = $config->statuses()->all()
            ->map(fn (array $status) => [...$status, 'aliases' => implode(', ', $status['aliases'])])
            ->values()
            ->all();
    }

    public function applyPreset(string $preset): void
    {
        $this->authorize('manage');

        $this->fillFrom(ProjectType::from($preset)->settings());
        $this->resetValidation();
        $this->dispatch('toast', message: 'Preset diterapkan, klik Simpan untuk menyimpan');
    }

    public function addStatus(): void
    {
        $this->statuses[] = ['code' => '', 'label' => '', 'color' => 'slate', 'done' => false, 'aliases' => ''];
    }

    public function removeStatus(int $index): void
    {
        unset($this->statuses[$index]);
        $this->statuses = array_values($this->statuses);
    }

    public function moveStatus(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (isset($this->statuses[$index], $this->statuses[$target])) {
            [$this->statuses[$index], $this->statuses[$target]] = [$this->statuses[$target], $this->statuses[$index]];
        }
    }

    public function save(): void
    {
        $this->authorize('manage');

        $this->validate([
            'unitLabel' => ['required', 'string', 'max:30'],
            'unitSource' => ['required', Rule::in([ProjectSettings::UNIT_KECAMATAN, ProjectSettings::UNIT_FILE])],
            'keyMode' => ['required', Rule::in([ProjectSettings::KEY_COLUMN, ProjectSettings::KEY_ROW])],
            'keyColumn' => [Rule::requiredIf($this->keyMode === ProjectSettings::KEY_COLUMN), 'nullable', 'string', 'max:100'],
            'keyDetailColumn' => ['nullable', 'string', 'max:100'],
            'reportKeyFields' => [Rule::requiredIf($this->keyMode === ProjectSettings::KEY_COLUMN), 'nullable', 'string', 'max:300'],
            'recapColumn' => ['nullable', 'string', 'max:100'],
            'initialStatusColumn' => ['nullable', 'string', 'max:100'],
            'taskColumn' => ['nullable', 'string', 'max:100'],
            'taskValues' => [Rule::requiredIf($this->taskColumn !== ''), 'nullable', 'string', 'max:200'],
            'taskConditions' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                foreach (ProjectSettings::toConditionList($value) as $condition) {
                    if (ProjectSettings::parseCondition($condition) === null) {
                        $fail("Syarat \"{$condition}\" tidak bisa dibaca. Contoh: skor_kecocokan >= 80");
                    }
                }
            }],
            'requiredColumns' => ['nullable', 'string', 'max:300'],
            'ignoredSheets' => ['nullable', 'string', 'max:300'],
            'displayColumns' => ['nullable', 'string', 'max:2000'],
            'statuses' => ['required', 'array', 'min:1'],
            'statuses.*.code' => ['required', 'string', 'max:20', 'regex:/^[a-z0-9_-]+$/i', 'distinct:ignore_case'],
            'statuses.*.label' => ['required', 'string', 'max:40'],
            'statuses.*.color' => ['required', Rule::in(array_keys(StatusSet::COLORS))],
            'statuses.*.done' => ['boolean'],
            'statuses.*.aliases' => ['nullable', 'string', 'max:300'],
        ], [
            'statuses.*.code.regex' => 'Kode hanya huruf, angka, - atau _.',
            'statuses.*.code.distinct' => 'Kode status tidak boleh sama.',
            'keyColumn.required' => 'Isi nama kolom kunci di Excel.',
            'reportKeyFields.required' => 'Isi nama field kunci di laporan.',
        ]);

        $before = $this->matchingSettings($this->project->config());

        $this->project->update(['settings' => (new ProjectSettings([
            'unit_label' => $this->unitLabel,
            'unit_source' => $this->unitSource,
            'key_mode' => $this->keyMode,
            'key_column' => $this->keyColumn,
            'key_detail_column' => $this->keyDetailColumn,
            'report_key_fields' => ProjectSettings::toList($this->reportKeyFields),
            'recap_column' => $this->recapColumn,
            'initial_status_column' => $this->initialStatusColumn,
            'task_column' => $this->taskColumn,
            'task_values' => ProjectSettings::toList($this->taskValues),
            'task_conditions' => ProjectSettings::toConditionList($this->taskConditions),
            'required_columns' => ProjectSettings::toList($this->requiredColumns),
            'ignored_sheets' => ProjectSettings::toList($this->ignoredSheets),
            'display_columns' => ProjectSettings::toList($this->displayColumns),
            'statuses' => $this->statuses,
        ]))->toArray()]);

        // Cara pencocokan / status berubah: data lama langsung disesuaikan, tanpa perlu klik "Proses ulang"
        if ($before !== $this->matchingSettings($this->project->refresh()->config())) {
            $this->startReprocess(app(UnitReportService::class));
            $this->dispatch('toast', message: 'Pengaturan disimpan, data sedang diproses ulang…');

            return;
        }

        $this->dispatch('toast', message: 'Pengaturan disimpan');
        $this->dispatch('project-updated');
    }

    /**
     * Bagian pengaturan yang memengaruhi kunci baris dan status: bila berubah, Excel & laporan perlu diproses ulang.
     *
     * @return array<string, mixed>
     */
    private function matchingSettings(ProjectSettings $config): array
    {
        return [
            'unit_source' => $config->unitSource(),
            'key_mode' => $config->keyMode(),
            'key_column' => $config->keyColumn(),
            'key_detail_column' => $config->keyDetailColumn(),
            'recap_column' => $config->recapColumn(),
            'report_key_fields' => $config->reportKeyFields(),
            'initial_status_column' => $config->initialStatusColumn(),
            'task_column' => $config->taskColumn(),
            'task_values' => $config->taskValues(),
            'task_conditions' => $config->taskConditions(),
            'required_columns' => $config->requiredColumns(),
            'ignored_sheets' => $config->ignoredSheets(),
            'statuses' => $config->statuses()->toArray(),
        ];
    }

    /**
     * Hitung ulang kunci baris (dari isi baris yang tersimpan, tanpa membaca file Excel lagi) lalu terapkan
     * ulang laporan tiap unit. Berjalan setelah respons terkirim (tanpa batas waktu gateway); progres di cache.
     */
    public function reprocessAll(UnitReportService $unitReports): void
    {
        $this->authorize('manage');

        $this->startReprocess($unitReports);
        $this->dispatch('toast', message: 'Proses ulang berjalan di latar belakang…');
    }

    public static function reprocessKey(Project $project): string
    {
        return "reprocess:{$project->id}";
    }

    private function startReprocess(UnitReportService $unitReports): void
    {
        $key = self::reprocessKey($this->project);

        if ((Cache::get($key)['status'] ?? null) === 'running') {
            return;
        }

        $total = $this->project->kecamatans()->whereHas('targetRows')->count();
        Cache::put($key, ['status' => 'running', 'done' => 0, 'total' => $total, 'log' => []], now()->addHours(2));
        $this->reprocessRunning = true;
        $project = $this->project;

        defer(function () use ($project, $unitReports, $key) {
            // Batas 15 menit: proses yang macet tidak boleh menahan slot PHP situs selamanya (503 "server busy")
            @set_time_limit(900);
            ignore_user_abort(true);

            try {
                $log = $unitReports->reprocessProject($project, function (int $done, int $total) use ($key) {
                    Cache::put($key, ['status' => 'running', 'done' => $done, 'total' => $total, 'log' => []], now()->addHours(2));
                });

                Cache::put($key, ['status' => 'done', 'done' => count($log), 'total' => count($log), 'log' => $log], now()->addDay());
            } catch (Throwable $e) {
                report($e);
                Cache::put($key, ['status' => 'failed', 'done' => 0, 'total' => 0, 'log' => ['Gagal: '.$e->getMessage()]], now()->addDay());
            }
        });
    }

    public function dismissReprocess(): void
    {
        if ((Cache::get(self::reprocessKey($this->project))['status'] ?? null) !== 'running') {
            Cache::forget(self::reprocessKey($this->project));
        }
    }

    public function render(): View
    {
        $headers = TargetSheet::whereIn('kecamatan_id', $this->project->kecamatans()->select('id'))
            ->get(['headers'])
            ->pluck('headers')
            ->flatten()
            ->unique()
            ->values();

        /** @var array{status: string, done: int, total: int, log: list<string>}|null $reprocess */
        $reprocess = Cache::get(self::reprocessKey($this->project));
        $running = ($reprocess['status'] ?? null) === 'running';

        // Baru selesai: tabel & grafik di komponen lain ikut diperbarui
        if ($this->reprocessRunning && ! $running) {
            $this->dispatch('project-updated');
            $this->dispatch('toast', message: 'Proses ulang selesai');
        }

        $this->reprocessRunning = $running;

        return view('livewire.projects.project-settings-form', [
            'reprocess' => $reprocess,
            'headers' => $headers,
            'presets' => ProjectType::cases(),
            'colors' => array_keys(StatusSet::COLORS),
        ]);
    }
}
