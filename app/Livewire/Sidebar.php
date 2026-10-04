<?php

namespace App\Livewire;

use App\Models\Project;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Navigasi samping: menu utama dan pohon data → unit (kecamatan / Bagian).
 * Ikut diperbarui saat data ditambah / diubah / dihapus (event "project-updated").
 */
class Sidebar extends Component
{
    public string $section = '';

    public ?int $activeProjectId = null;

    public ?string $activeKode = null;

    public function mount(): void
    {
        $route = request()->route();
        $project = $route?->parameter('project');

        $this->section = match (true) {
            request()->routeIs('dashboard') => 'dashboard',
            request()->routeIs('projects.index') => 'projects',
            default => '',
        };
        $this->activeProjectId = match (true) {
            $project instanceof Project => $project->id,
            is_string($project) => Project::where('slug', $project)->value('id'),
            default => null,
        };
        $this->activeKode = $route?->parameter('kode');
    }

    #[On('project-updated')]
    public function refreshList(): void
    {
        // Render ulang dengan data terbaru
    }

    public function render(): View
    {
        return view('livewire.sidebar', [
            'projects' => Project::orderBy('name')
                ->with(['kecamatans' => fn ($query) => $query->select(['id', 'project_id', 'kode', 'nama', 'status', 'target', 'realisasi'])])
                ->get(['id', 'name', 'slug', 'color', 'type', 'settings']),
        ]);
    }
}
