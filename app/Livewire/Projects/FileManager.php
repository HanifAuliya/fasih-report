<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ReportFile;
use App\Services\ReportFileProcessor;
use App\Services\UnitReportService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class FileManager extends Component
{
    use WithFileUploads;

    public Project $project;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'kategori', except: '')]
    public string $category = '';

    #[Url(as: 'kec', except: '')]
    public string $kecamatanFilter = '';

    // Upload
    public bool $showUpload = false;

    public array $uploads = [];

    public string $uploadCategory = 'auto';

    public string $uploadKecamatan = 'auto';

    public string $uploadNotes = '';

    // Edit metadata
    public bool $showEdit = false;

    public ?int $editingId = null;

    public string $editCategory = 'report';

    public string $editKecamatan = '';

    public string $editNotes = '';

    // Preview
    public bool $showPreview = false;

    public ?int $previewId = null;

    public function openUpload(): void
    {
        $this->authorize('manage');

        $this->reset(['uploads', 'uploadCategory', 'uploadKecamatan', 'uploadNotes']);
        $this->resetValidation();
        $this->showUpload = true;
    }

    public function removeUpload(int $index): void
    {
        $this->authorize('manage');

        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function saveUploads(ReportFileProcessor $processor): void
    {
        $this->authorize('manage');

        $max = config('fasih.max_upload_kb');

        $this->validate([
            'uploads' => ['required', 'array', 'min:1'],
            'uploads.*' => ['file', "max:{$max}", function ($attribute, $file, $fail) {
                if (! in_array(strtolower($file->getClientOriginalExtension()), ReportFile::ALLOWED_EXTENSIONS)) {
                    $fail('Tipe file '.$file->getClientOriginalName().' tidak diizinkan.');
                }
            }],
            'uploadCategory' => ['required', Rule::in(['auto', ...array_keys(ReportFile::CATEGORIES)])],
            'uploadNotes' => ['nullable', 'string', 'max:1000'],
        ], [
            'uploads.required' => 'Pilih minimal satu file.',
            'uploads.*.max' => 'Ukuran file maksimal '.round($max / 1024).' MB.',
        ]);

        $messages = [];

        foreach ($this->uploads as $upload) {
            $file = $processor->store($this->project, $upload, $this->uploadCategory, $this->uploadKecamatan, $this->uploadNotes, auth()->id());

            if ($summary = $processor->process($file)) {
                $messages[] = $summary;
            }
        }

        $count = count($this->uploads);
        $this->reset(['uploads', 'showUpload']);
        $this->dispatch('toast', message: $messages ? implode(' | ', $messages) : "{$count} file diupload");
        $this->dispatch('project-updated');
    }

    public function reprocess(int $id, ReportFileProcessor $processor): void
    {
        $this->authorize('manage');

        $file = $this->project->files()->findOrFail($id);
        $summary = $processor->process($file);

        $this->dispatch('toast', message: $summary ?? 'File ini tidak perlu diproses', type: $summary ? 'success' : 'error');
        $this->dispatch('project-updated');
    }

    public function edit(int $id): void
    {
        $this->authorize('manage');

        $file = $this->project->files()->findOrFail($id);

        $this->editingId = $file->id;
        $this->editCategory = $file->category;
        $this->editKecamatan = (string) $file->kecamatan_id;
        $this->editNotes = (string) $file->notes;
        $this->resetValidation();
        $this->showEdit = true;
    }

    public function update(): void
    {
        $this->authorize('manage');

        $this->validate([
            'editCategory' => ['required', Rule::in(array_keys(ReportFile::CATEGORIES))],
            'editKecamatan' => ['nullable', Rule::exists('kecamatans', 'id')->where('project_id', $this->project->id)],
            'editNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->project->files()->findOrFail($this->editingId)->update([
            'category' => $this->editCategory,
            'kecamatan_id' => $this->editKecamatan ?: null,
            'notes' => $this->editNotes ?: null,
        ]);

        $this->showEdit = false;
        $this->dispatch('toast', message: 'File diperbarui');
        $this->dispatch('project-updated');
    }

    public function delete(int $id, UnitReportService $unitReports): void
    {
        $this->authorize('manage');

        // Lewat service supaya laporan aktif sebelumnya dipulihkan jika laporan aktif dihapus
        $unitReports->delete($this->project->files()->findOrFail($id));

        $this->dispatch('toast', message: 'File dihapus');
        $this->dispatch('project-updated');
    }

    public function preview(int $id): void
    {
        $this->previewId = $this->project->files()->findOrFail($id)->id;
        $this->showPreview = true;
    }

    public function render()
    {
        $files = $this->project->files()
            ->with('kecamatan')
            ->when($this->search, fn ($q) => $q->where('original_name', 'like', "%{$this->search}%"))
            ->when($this->category, fn ($q) => $q->where('category', $this->category))
            ->when($this->kecamatanFilter === 'none', fn ($q) => $q->whereNull('kecamatan_id'))
            ->when($this->kecamatanFilter && $this->kecamatanFilter !== 'none', fn ($q) => $q->where('kecamatan_id', $this->kecamatanFilter))
            ->get();

        return view('livewire.projects.file-manager', [
            'files' => $files,
            'kecamatans' => $this->project->kecamatans()->get(),
            'previewFile' => $this->previewId ? ReportFile::find($this->previewId) : null,
        ]);
    }
}
