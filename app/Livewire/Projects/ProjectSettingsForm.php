<?php

namespace App\Livewire\Projects;

use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\TargetSheet;
use App\Services\TargetImporter;
use App\Services\UnitReportService;
use App\Support\ProjectSettings;
use App\Support\StatusSet;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

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

    public string $reportKeyFields = '';

    public string $recapColumn = '';

    public string $initialStatusColumn = '';

    public string $taskColumn = '';

    public string $taskValues = '';

    public string $requiredColumns = '';

    public string $displayColumns = '';

    /** @var list<array{code: string, label: string, color: string, done: bool, aliases: string}> */
    public array $statuses = [];

    /** @var list<string> */
    public array $processLog = [];

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
        $this->reportKeyFields = implode(', ', $config->reportKeyFields());
        $this->recapColumn = (string) $config->recapColumn();
        $this->initialStatusColumn = (string) $config->initialStatusColumn();
        $this->taskColumn = (string) $config->taskColumn();
        $this->taskValues = implode(', ', $config->taskValues());
        $this->requiredColumns = implode(', ', $config->requiredColumns());
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
            'reportKeyFields' => [Rule::requiredIf($this->keyMode === ProjectSettings::KEY_COLUMN), 'nullable', 'string', 'max:300'],
            'recapColumn' => ['nullable', 'string', 'max:100'],
            'initialStatusColumn' => ['nullable', 'string', 'max:100'],
            'taskColumn' => ['nullable', 'string', 'max:100'],
            'taskValues' => [Rule::requiredIf($this->taskColumn !== ''), 'nullable', 'string', 'max:200'],
            'requiredColumns' => ['nullable', 'string', 'max:300'],
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
            'report_key_fields' => ProjectSettings::toList($this->reportKeyFields),
            'recap_column' => $this->recapColumn,
            'initial_status_column' => $this->initialStatusColumn,
            'task_column' => $this->taskColumn,
            'task_values' => ProjectSettings::toList($this->taskValues),
            'required_columns' => ProjectSettings::toList($this->requiredColumns),
            'display_columns' => ProjectSettings::toList($this->displayColumns),
            'statuses' => $this->statuses,
        ]))->toArray()]);

        // Cara pencocokan / status berubah: data lama langsung disesuaikan, tanpa perlu klik "Proses ulang"
        if ($before !== $this->matchingSettings($this->project->refresh()->config())) {
            $this->reprocessAll(app(TargetImporter::class), app(UnitReportService::class));
            $this->dispatch('toast', message: 'Pengaturan disimpan & data diproses ulang');

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
            'report_key_fields' => $config->reportKeyFields(),
            'initial_status_column' => $config->initialStatusColumn(),
            'task_column' => $config->taskColumn(),
            'task_values' => $config->taskValues(),
            'required_columns' => $config->requiredColumns(),
            'statuses' => $config->statuses()->toArray(),
        ];
    }

    /**
     * Hitung ulang kunci baris (dari isi baris yang tersimpan, tanpa membaca file Excel lagi)
     * lalu terapkan ulang laporan aktif tiap unit; dipakai setelah pengaturan kunci/status diubah.
     */
    public function reprocessAll(TargetImporter $importer, UnitReportService $unitReports): void
    {
        $this->authorize('manage');

        $this->processLog = [];

        foreach ($this->project->kecamatans()->whereHas('targetRows')->get() as $unit) {
            $rekeyed = $importer->rekey($unit);
            $this->processLog[] = $unit->nama.($rekeyed ? " ({$rekeyed} kunci baris diperbarui)" : '').': '
                .($unitReports->reapply($unit) ?? 'belum ada laporan aktif, status dari Excel awal');
        }

        $this->dispatch('toast', message: count($this->processLog).' file diproses ulang');
        $this->dispatch('project-updated');
    }

    public function render(): View
    {
        $headers = TargetSheet::whereIn('kecamatan_id', $this->project->kecamatans()->select('id'))
            ->get(['headers'])
            ->pluck('headers')
            ->flatten()
            ->unique()
            ->values();

        return view('livewire.projects.project-settings-form', [
            'headers' => $headers,
            'presets' => ProjectType::cases(),
            'colors' => array_keys(StatusSet::COLORS),
        ]);
    }
}
