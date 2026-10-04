<?php

namespace App\Livewire\Projects;

use App\Enums\ProjectType;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Daftar pekerjaan')]
class Index extends Component
{
    #[Url(except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public string $color = 'indigo';

    public string $type = 'oss';

    public bool $withKecamatans = true;

    public function create(): void
    {
        $this->authorize('manage');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage');

        $project = Project::findOrFail($id);
        $this->resetForm();
        $this->editingId = $project->id;
        $this->name = $project->name;
        $this->description = (string) $project->description;
        $this->color = $project->color;
        $this->type = $project->type->value;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['required', Rule::in(Project::COLORS)],
            'type' => ['required', Rule::enum(ProjectType::class)],
        ]);

        if ($this->editingId) {
            // Jenis data tidak bisa diganti setelah dibuat (kunci pencocokan baris bergantung padanya)
            unset($data['type']);
            Project::findOrFail($this->editingId)->update($data);
            $message = 'Data diperbarui';
        } else {
            $project = Project::create($data);

            if ($this->withKecamatans && $project->type->usesDefaultKecamatans()) {
                foreach (config('fasih.kecamatans') as $kode => $nama) {
                    $project->kecamatans()->create(['kode' => $kode, 'nama' => $nama]);
                }
            }
            $message = 'Data baru dibuat';
        }

        $this->showForm = false;
        $this->dispatch('toast', message: $message);
        $this->dispatch('project-updated');
    }

    public function delete(int $id): void
    {
        $this->authorize('manage');

        $project = Project::with('files')->findOrFail($id);

        // Hapus file satu per satu supaya file fisik di storage ikut terhapus
        $project->files->each->delete();
        $project->delete();

        $this->dispatch('toast', message: 'Data dihapus');
        $this->dispatch('project-updated');
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'description', 'color', 'type', 'withKecamatans']);
        $this->resetValidation();
    }

    public function render()
    {
        $projects = Project::with('kecamatans')
            ->withCount(['scripts', 'files', 'kecamatans'])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->get();

        return view('livewire.projects.index', [
            'projects' => $projects,
        ]);
    }
}
