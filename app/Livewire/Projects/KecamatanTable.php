<?php

namespace App\Livewire\Projects;

use App\Models\Kecamatan;
use App\Models\Project;
use App\Models\TargetRow;
use App\Services\ReportFileProcessor;
use App\Services\TargetImporter;
use App\Services\UnitReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class KecamatanTable extends Component
{
    use WithFileUploads;

    public Project $project;

    public bool $showUpload = false;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    /** @var list<array{name: string, summary: ?string}> */
    public array $uploadResults = [];

    public string $filter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $kode = '';

    public string $nama = '';

    public int $target = 0;

    public int $realisasi = 0;

    public string $status = 'belum';

    public string $catatan = '';

    public function create(): void
    {
        $this->authorize('manage');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage');

        $kecamatan = $this->project->kecamatans()->findOrFail($id);
        $this->resetForm();
        $this->editingId = $kecamatan->id;
        $this->fill($kecamatan->only(['kode', 'nama', 'target', 'realisasi', 'status']));
        $this->catatan = (string) $kecamatan->catatan;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage');

        $data = $this->validate([
            'kode' => ['required', 'string', 'max:10', Rule::unique('kecamatans')->where('project_id', $this->project->id)->ignore($this->editingId)],
            'nama' => ['required', 'string', 'max:100'],
            'target' => ['required', 'integer', 'min:0'],
            'realisasi' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(array_keys(Kecamatan::STATUSES))],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['nama'] = mb_strtoupper($data['nama']);

        $kecamatan = $this->editingId
            ? $this->project->kecamatans()->findOrFail($this->editingId)
            : new Kecamatan(['project_id' => $this->project->id]);

        $kecamatan->fill($data);
        // Status otomatis hanya jika user tidak memilih status yang berbeda dari hasil hitungan
        if ($kecamatan->isDirty(['target', 'realisasi']) && ! $kecamatan->isDirty('status')) {
            $kecamatan->syncStatus();
        }
        $kecamatan->save();

        $this->showForm = false;
        $this->changed($this->editingId ? 'Kecamatan diperbarui' : 'Kecamatan ditambahkan');
    }

    /**
     * Edit langsung dari tabel (target, realisasi, status).
     */
    public function updateField(int $id, string $field, $value): void
    {
        $this->authorize('manage');

        abort_unless(in_array($field, ['target', 'realisasi', 'status']), 422);

        $kecamatan = $this->project->kecamatans()->findOrFail($id);

        if ($field === 'status') {
            abort_unless(array_key_exists($value, Kecamatan::STATUSES), 422);
            $kecamatan->status = $value;
        } else {
            $kecamatan->{$field} = max(0, (int) $value);
            $kecamatan->syncStatus();
        }

        $kecamatan->save();
        $this->changed('Tersimpan');
    }

    public function openUpload(): void
    {
        $this->authorize('manage');

        $this->reset(['uploads', 'uploadResults']);
        $this->resetValidation();
        $this->showUpload = true;
    }

    /**
     * Upload Excel target (kecamatan ditebak dari nama file) atau laporan JSON/CSV hasil script.
     */
    public function saveUploads(ReportFileProcessor $processor): void
    {
        $this->authorize('manage');

        $max = config('fasih.max_upload_kb');

        $this->validate([
            'uploads' => ['required', 'array', 'min:1'],
            'uploads.*' => ['file', "max:{$max}", function (string $attribute, $file, \Closure $fail): void {
                if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
                    $fail('Di sini khusus Excel (.xlsx). Laporan JSON/CSV diupload dari halaman masing-masing unit: '.$file->getClientOriginalName());
                }
            }],
        ], [
            'uploads.required' => 'Pilih minimal satu file.',
        ]);

        $this->uploadResults = [];

        foreach ($this->uploads as $upload) {
            $file = $processor->store($this->project, $upload, 'auto', 'auto', null, auth()->id());
            $summary = $processor->process($file);

            if ($summary === null && $file->extension === 'xlsx' && ! $file->kecamatan_id) {
                $summary = 'Gagal diproses: kecamatan tidak terdeteksi dari nama file. Atur kecamatannya di tab File Report lalu proses ulang.';
            }

            $this->uploadResults[] = ['name' => $file->original_name, 'summary' => $summary];
        }

        $this->reset('uploads');
        $this->changed(count($this->uploadResults).' file diproses');
    }

    public function delete(int $id, TargetImporter $importer, UnitReportService $unitReports): void
    {
        $this->authorize('manage');

        $unit = $this->project->kecamatans()->findOrFail($id);
        // Unit lain yang barisnya kembar dengan unit ini: sesudah dihapus, baris itu jadi miliknya sendiri
        $affected = TargetRow::where('duplicate_of_id', $unit->id)->distinct()->pluck('kecamatan_id');

        $unit->delete();

        foreach (Kecamatan::whereKey($affected)->get() as $other) {
            $importer->rekey($other);
            $unitReports->reapply($other);
        }

        $this->changed('Kecamatan dihapus');
    }

    protected function changed(string $message): void
    {
        $this->dispatch('toast', message: $message);
        $this->dispatch('project-updated');
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'kode', 'nama', 'target', 'realisasi', 'status', 'catatan']);
        $this->resetValidation();
    }

    public function render()
    {
        $kecamatans = $this->project->kecamatans()
            ->withCount('files')
            ->with(['reports' => fn ($q) => $q->where('is_active', true)])
            ->when($this->filter, fn ($q) => $q->where('status', $this->filter))
            ->get();

        $all = $this->project->kecamatans()->get();
        $stats = $this->stats();
        $statusCounts = collect($stats['status_counts'])->map(fn (array $counts) => collect($counts));

        return view('livewire.projects.kecamatan-table', [
            'kecamatans' => $kecamatans,
            'statusCounts' => $statusCounts,
            'changedRowsCount' => $stats['changed_rows'],
            'allUnits' => $all,
            'overallCounts' => $statusCounts->reduce(
                fn ($totals, $counts) => $counts->reduce(fn ($totals, $total, $status) => $totals->put($status, $totals->get($status, 0) + $total), $totals),
                collect(),
            ),
            'counts' => collect(Kecamatan::STATUSES)->map(fn ($label, $key) => $all->where('status', $key)->count()),
            'totalTarget' => $all->sum('target'),
            'totalRealisasi' => $all->sum('realisasi'),
            'kecamatanRecap' => collect($stats['recap']),
        ]);
    }

    /**
     * Ringkasan berat pekerjaan (jumlah per unit & status, rekap kecamatan, baris yang diubah laporan),
     * dihitung sekali lalu dipakai bersama semua pengunjung sampai data atau pengaturan berubah.
     *
     * @return array{status_counts: array<int, array<string, int>>, changed_rows: int, recap: array<string, array{total: int, done: int, percent: int, statuses: array<string, int>}>}
     */
    private function stats(): array
    {
        $version = Kecamatan::dataVersion($this->project->id).'-'.$this->project->updated_at?->getTimestamp();

        return Cache::remember("project-stats:{$this->project->id}:{$version}", now()->addHour(), fn () => [
            'status_counts' => TargetRow::where('project_id', $this->project->id)
                ->tracked()
                ->selectRaw('kecamatan_id, status, count(*) as total')
                ->groupBy('kecamatan_id', 'status')
                ->get()
                ->groupBy('kecamatan_id')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [(string) $row->status => (int) $row->total])->all())
                ->all(),
            'changed_rows' => TargetRow::where('project_id', $this->project->id)->whereNotNull('status_file_id')->count(),
            'recap' => $this->project->config()->recapColumn() ? $this->kecamatanRecap()->all() : [],
        ]);
    }

    /**
     * Rekap progress per kecamatan dari kolom rekap di tiap baris (mis. "Kecamatan" / "kec").
     *
     * @return Collection<string, array{total: int, done: int, percent: int, statuses: array<string, int>}>
     */
    private function kecamatanRecap(): Collection
    {
        $settings = $this->project->config();
        $statuses = $settings->statuses();
        $recap = [];

        TargetRow::where('project_id', $this->project->id)
            ->tracked()
            ->selectRaw('recap, status, count(*) as total')
            ->groupBy('recap', 'status')
            ->get()
            ->each(function (TargetRow $group) use ($statuses, &$recap) {
                $name = (string) $group->recap !== '' ? $group->recap : '(TANPA KECAMATAN)';
                $status = $group->status ?? $statuses->defaultCode();
                $total = (int) $group->total;

                $recap[$name]['total'] = ($recap[$name]['total'] ?? 0) + $total;
                $recap[$name]['done'] = ($recap[$name]['done'] ?? 0) + ($statuses->isDone($status) ? $total : 0);
                $recap[$name]['statuses'][$status] = ($recap[$name]['statuses'][$status] ?? 0) + $total;
            });

        return collect($recap)
            ->map(fn (array $item) => [...$item, 'percent' => (int) round($item['done'] / $item['total'] * 100)])
            ->sortKeys();
    }
}
