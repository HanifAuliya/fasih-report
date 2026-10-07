<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\MasterWorkbook;
use App\Services\ReportFileProcessor;
use App\Support\ProjectSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

use function Illuminate\Support\defer;

/**
 * Kartu "File induk" di tab Progress: upload Excel utuh, ringkasan keterhubungan dengan unit (Bagian),
 * dan menyiapkan Excel induk + status. Proses berat berjalan setelah respons terkirim (tanpa batas waktu gateway).
 */
class MasterWorkbookPanel extends Component
{
    use WithFileUploads;

    public const PROCESSING = 'Sedang diproses…';

    public Project $project;

    /** @var TemporaryUploadedFile|null */
    public $masterUpload = null;

    public function uploadMaster(ReportFileProcessor $processor, MasterWorkbook $master): void
    {
        $this->authorize('manage');

        $this->validate([
            'masterUpload' => ['required', 'file', 'max:'.config('fasih.max_upload_kb'), function (string $attribute, $file, \Closure $fail): void {
                if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
                    $fail('File induk harus Excel (.xlsx).');
                }
            }],
        ], ['masterUpload.required' => 'Pilih file Excel induk.']);

        // File induk lama diganti (baris induknya ikut terhapus lewat relasi)
        $master->masterFile($this->project)?->delete();

        $file = $processor->store($this->project, $this->masterUpload, 'induk', '', null, auth()->id());
        $file->update(['summary' => self::PROCESSING]);
        $this->reset('masterUpload');

        defer(fn () => $master->runImport($file));

        $this->dispatch('toast', message: 'File induk diupload, sedang dibaca…');
    }

    public function prepareExport(MasterWorkbook $master): void
    {
        $this->authorize('manage');

        $master->markExportRunning($this->project);
        defer(fn () => $master->runExport($this->project));

        $this->dispatch('toast', message: 'Menyiapkan Excel induk + status…');
    }

    public function deleteMaster(MasterWorkbook $master): void
    {
        $this->authorize('manage');

        $master->masterFile($this->project)?->delete();
        $master->forgetExport($this->project);

        $this->dispatch('toast', message: 'File induk dihapus');
    }

    public function render(MasterWorkbook $master): View
    {
        $file = $master->masterFile($this->project);
        $processing = $file?->summary === self::PROCESSING;
        $export = $master->exportState($this->project);

        if (($export['status'] ?? null) === 'ready' && ! Storage::disk('local')->exists($export['path'])) {
            $export = null;
        }

        $config = $this->project->config();

        return view('livewire.projects.master-workbook-panel', [
            // Untuk pekerjaan berkolom kunci & unit per file (mis. Bagian KBLI), atau bila sudah ada file induk
            'visible' => $file || (auth()->user()?->can('manage') && $config->keyMode() === ProjectSettings::KEY_COLUMN && $config->unitSource() === ProjectSettings::UNIT_FILE),
            'file' => $file,
            'processing' => $processing,
            'failed' => $file && str_starts_with((string) $file->summary, 'Gagal'),
            'stats' => $file && ! $processing ? $master->stats($this->project) : null,
            'export' => $export,
            'polling' => $processing || ($export['status'] ?? null) === 'running',
        ]);
    }
}
