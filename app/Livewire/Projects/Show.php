<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class Show extends Component
{
    public Project $project;

    #[Url]
    public string $tab = 'progress';

    public bool $showForm = false;

    public string $name = '';

    public string $description = '';

    public string $color = 'indigo';

    public function mount(): void
    {
        $tabs = ['progress', 'scripts', 'files', ...(auth()->user()?->can('manage') ? ['settings'] : [])];

        if (! in_array($this->tab, $tabs, true)) {
            $this->tab = 'progress';
        }
    }

    public function edit(): void
    {
        $this->authorize('manage');

        $this->name = $this->project->name;
        $this->description = (string) $this->project->description;
        $this->color = $this->project->color;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage');

        $this->project->update($this->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['required', Rule::in(Project::COLORS)],
        ]));

        $this->showForm = false;
        $this->dispatch('toast', message: 'Data diperbarui');
    }

    // Dipanggil oleh komponen anak saat data berubah, supaya ringkasan di header ikut segar
    #[On('project-updated')]
    public function refreshSummary(): void
    {
        $this->project->refresh();
    }

    public function render()
    {
        $this->project->load('kecamatans')->loadCount(['scripts', 'files']);

        return view('livewire.projects.show')->title($this->project->name);
    }
}
