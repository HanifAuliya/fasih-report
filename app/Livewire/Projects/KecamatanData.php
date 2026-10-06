<?php

namespace App\Livewire\Projects;

use App\Models\Kecamatan;
use App\Models\Project;
use App\Models\TargetRow;
use App\Models\TargetSheet;
use App\Services\UnitReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class KecamatanData extends Component
{
    use WithFileUploads;
    use WithPagination;

    public Project $project;

    public Kecamatan $kecamatan;

    #[Url(as: 'sheet')]
    public ?int $sheetId = null;

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'baris', except: 50)]
    public int $perPage = 50;

    #[Url(as: 'ringkas')]
    public bool $compact = true;

    public bool $showDetail = false;

    public bool $showReportUpload = false;

    /** @var TemporaryUploadedFile|null */
    public $reportUpload = null;

    public ?int $detailId = null;

    /** @var list<int|string> id baris yang dicentang untuk ubah status sekaligus */
    public array $selected = [];

    /**
     * Filter per kolom ala Excel: index kolom => nilai yang dipilih.
     *
     * @var array<int|string, string>
     */
    #[Url(as: 'kolom', except: [])]
    public array $columnFilters = [];

    /** Kolom yang sedang dibuka di panel filter (untuk menampilkan pilihan nilainya). */
    public ?int $filterColumn = null;

    public function mount(Project $project, string $kode): void
    {
        $this->kecamatan = $project->kecamatans()->where('kode', $kode)->firstOrFail();
    }

    public function updatedSheetId(): void
    {
        $this->reset('statusFilter', 'selected', 'columnFilters', 'filterColumn');
        $this->resetPage();
    }

    public function addColumnFilter(int $column, string $value): void
    {
        $this->columnFilters[$column] = $value;
        $this->reset('filterColumn', 'selected');
        $this->resetPage();
    }

    public function removeColumnFilter(int $column): void
    {
        unset($this->columnFilters[$column]);
        $this->reset('selected');
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('statusFilter', 'search', 'columnFilters', 'filterColumn', 'selected');
        $this->resetPage();
    }

    /**
     * Teks sel seperti yang tampil di tabel, dipakai untuk mencocokkan filter kolom.
     */
    public static function cellText(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_float($value) && floor($value) === $value => (string) (int) $value,
            default => trim((string) $value),
        };
    }

    public function updatedStatusFilter(): void
    {
        $this->reset('selected');
        $this->resetPage();
    }

    public function updatedPaginators(): void
    {
        $this->reset('selected');
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array($this->perPage, [25, 50, 100, 250, 500], true) ? $this->perPage : 50;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->reset('selected');
        $this->resetPage();
    }

    public function setStatus(int $rowId, string $status): void
    {
        $this->authorize('manage');

        $this->kecamatan->targetRows()->tracked()->findOrFail($rowId);
        $this->applyStatus([$rowId], $status);
    }

    /**
     * Ubah status semua baris yang dicentang sekaligus.
     */
    public function setStatusForSelected(string $status): void
    {
        $this->authorize('manage');

        $count = $this->applyStatus(array_map('intval', $this->selected), $status);
        $this->reset('selected');

        if ($count === 0) {
            $this->dispatch('toast', message: 'Tidak ada baris yang dipilih', type: 'error');
        }
    }

    /**
     * @param  list<int>  $rowIds
     */
    private function applyStatus(array $rowIds, string $status): int
    {
        $statuses = $this->project->config()->statuses();
        abort_unless($statuses->has($status), 422);

        $count = $this->kecamatan->targetRows()->tracked()->whereIn('id', $rowIds)->update([
            'status' => $status,
            'reason' => 'diubah manual di web',
            'status_at' => now(),
            'status_file_id' => null,
        ]);

        if ($count > 0) {
            $this->kecamatan->syncProgressFromTargets();
            $this->dispatch('toast', message: ($count > 1 ? "{$count} baris → " : 'Status diubah: ').$statuses->label($status));
            $this->dispatch('project-updated');
        }

        return $count;
    }

    public function openReportUpload(): void
    {
        $this->authorize('manage');

        $this->reset('reportUpload');
        $this->resetValidation();
        $this->showReportUpload = true;
    }

    /**
     * Upload laporan JSON/CSV khusus unit ini; laporan baru langsung jadi aktif.
     */
    public function uploadReport(UnitReportService $unitReports): void
    {
        $this->authorize('manage');

        $this->validate([
            'reportUpload' => ['required', 'file', 'max:'.config('fasih.max_upload_kb'), function (string $attribute, $file, \Closure $fail): void {
                if (! in_array(strtolower($file->getClientOriginalExtension()), ['json', 'csv'], true)) {
                    $fail('Laporan harus file .json atau .csv.');
                }
            }],
        ], ['reportUpload.required' => 'Pilih file laporan.']);

        $file = $unitReports->upload($this->kecamatan, $this->reportUpload, auth()->id());

        $this->reset(['reportUpload', 'showReportUpload']);
        $this->reportChanged($file->is_active ? 'Laporan aktif: '.$file->original_name : $file->summary, $file->is_active);
    }

    public function activateReport(int $id, UnitReportService $unitReports): void
    {
        $this->authorize('manage');

        $file = $this->kecamatan->reports()->findOrFail($id);
        $summary = $unitReports->activate($file);

        $this->reportChanged($file->refresh()->is_active ? 'Laporan aktif: '.$file->original_name : $summary, $file->is_active);
    }

    public function deleteReport(int $id, UnitReportService $unitReports): void
    {
        $this->authorize('manage');

        $unitReports->delete($this->kecamatan->reports()->findOrFail($id));

        $this->reportChanged('Laporan dihapus');
    }

    private function reportChanged(string $message, bool $success = true): void
    {
        $this->kecamatan->refresh();
        $this->resetPage();
        $this->dispatch('toast', message: $message, type: $success ? 'success' : 'error');
        $this->dispatch('project-updated');
    }

    public function openDetail(int $rowId): void
    {
        $this->detailId = $this->kecamatan->targetRows()->findOrFail($rowId)->id;
        $this->showDetail = true;
    }

    public function render(): View
    {
        $sheets = $this->kecamatan->targetSheets()->get();
        $sheet = $sheets->firstWhere('id', $this->sheetId) ?? $sheets->first();
        $tracked = $sheet?->isTracked() ?? false;

        $filteredIds = $sheet ? $this->idsMatchingColumnFilters($sheet) : null;

        $rows = $sheet
            ? $sheet->rows()
                ->when($filteredIds !== null, fn ($query) => $query->whereIn('id', $filteredIds))
                ->when($this->statusFilter && $tracked, fn ($query) => $query->where('status', $this->statusFilter))
                ->when($this->search, fn ($query) => $query->where('cells', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%'))
                ->paginate(in_array($this->perPage, [25, 50, 100, 250, 500], true) ? $this->perPage : 50)
            : null;

        $kecamatans = $this->project->kecamatans()->get(['id', 'kode', 'nama']);
        $position = $kecamatans->search(fn (Kecamatan $kecamatan) => $kecamatan->is($this->kecamatan));

        return view('livewire.projects.kecamatan-data', [
            'filterValues' => $sheet && $this->filterColumn !== null ? $this->columnValues($sheet, $this->filterColumn) : collect(),
            'sheets' => $sheets,
            'sheet' => $sheet,
            'tracked' => $tracked,
            'columns' => $sheet ? $this->visibleColumns($sheet) : [],
            'rows' => $rows,
            'statusCounts' => $sheet && $tracked
                ? $sheet->rows()->reorder()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
                : collect(),
            'previousKecamatan' => $position > 0 ? $kecamatans[$position - 1] : null,
            'nextKecamatan' => $kecamatans[$position + 1] ?? null,
            'reports' => $this->kecamatan->reports()->with('uploader')->get(),
            'detail' => $this->detailId ? TargetRow::with(['sheet', 'statusFile'])->find($this->detailId) : null,
        ])->title($this->kecamatan->nama.' · '.$this->project->name);
    }

    /**
     * Id baris yang lolos semua filter kolom (dicocokkan dengan teks sel seperti di tabel), null bila tanpa filter.
     *
     * @return list<int>|null
     */
    private function idsMatchingColumnFilters(TargetSheet $sheet): ?array
    {
        if ($this->columnFilters === []) {
            return null;
        }

        return $sheet->rows()->reorder()->get(['id', 'cells'])
            ->filter(function (TargetRow $row) {
                foreach ($this->columnFilters as $column => $value) {
                    if (self::cellText($row->cells[(int) $column] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Pilihan nilai satu kolom beserta jumlah barisnya (terbanyak dulu), untuk panel filter.
     *
     * @return Collection<string, int>
     */
    private function columnValues(TargetSheet $sheet, int $column): Collection
    {
        return $sheet->rows()->reorder()->get(['cells'])
            ->countBy(fn (TargetRow $row) => self::cellText($row->cells[$column] ?? null))
            ->sortDesc()
            ->take(300);
    }

    /**
     * @return array<int, string> index kolom => nama header
     */
    private function visibleColumns(TargetSheet $sheet): array
    {
        $columns = $sheet->headers;

        if (! $this->compact) {
            return $columns;
        }

        $wanted = array_map(TargetSheet::normalizeHeader(...), $this->project->config()->displayColumns());
        $compact = array_filter($columns, fn (string $header) => in_array(TargetSheet::normalizeHeader($header), $wanted, true));

        return $compact ?: $columns;
    }
}
