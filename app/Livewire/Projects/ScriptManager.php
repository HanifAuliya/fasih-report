<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\Script;
use App\Models\ScriptVersion;
use App\Services\GithubScriptSync;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class ScriptManager extends Component
{
    public Project $project;

    #[Url(as: 'script')]
    public ?int $selectedId = null;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $filename = '';

    public string $language = 'javascript';

    public string $version = '1.0.0';

    public string $code = '';

    public string $notes = '';

    public string $changeNote = '';

    public bool $isPublic = true;

    /** Sumber kode: manual (ditempel di web) atau github (diambil dari file di repo). */
    public string $source = 'manual';

    public string $githubUrl = '';

    public bool $showVersion = false;

    public ?int $viewingVersionId = null;

    public function select(int $id): void
    {
        $this->selectedId = $id;
    }

    public function create(): void
    {
        $this->authorize('manage');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage');

        $script = $this->project->scripts()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $script->id;
        $this->fill($script->only(['name', 'filename', 'language', 'version', 'code']));
        $this->notes = (string) $script->notes;
        $this->isPublic = $script->is_public;
        $this->source = $script->isFromGithub() ? 'github' : 'manual';
        $this->githubUrl = (string) $script->githubUrl();
        $this->showForm = true;
    }

    public function save(GithubScriptSync $sync): void
    {
        $this->authorize('manage');

        $github = $this->source === 'github' ? GithubScriptSync::parseUrl($this->githubUrl) : null;

        if ($github && $this->filename === '') {
            $this->filename = basename($github['path']);
        }

        if ($github && $this->name === '') {
            $this->name = pathinfo($github['path'], PATHINFO_FILENAME);
        }

        if ($this->source === 'manual' && ($version = GithubScriptSync::versionOf($this->code))) {
            $this->version = $version;
        }

        $data = $this->validate([
            'source' => ['required', Rule::in(['manual', 'github'])],
            'githubUrl' => [Rule::requiredIf($this->source === 'github'), 'nullable', 'string', 'max:500', function (string $attribute, $value, \Closure $fail) use ($github): void {
                if ($this->source === 'github' && ! $github) {
                    $fail('Tempel link file dari GitHub, mis. https://github.com/akun/repo/blob/main/script.user.js');
                }
            }],
            'name' => ['required', 'string', 'max:150'],
            'filename' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('scripts')->where('project_id', $this->project->id)->ignore($this->editingId)],
            'language' => ['required', Rule::in(array_keys(Script::LANGUAGES))],
            'version' => ['required', 'string', 'max:20'],
            'code' => [Rule::requiredIf($this->source === 'manual'), 'nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'isPublic' => ['boolean'],
            'changeNote' => ['nullable', 'string', 'max:500'],
        ], [
            'filename.regex' => 'Nama file hanya boleh huruf, angka, titik, strip dan underscore.',
            'githubUrl.required' => 'Tempel link file script di GitHub.',
        ]);

        $script = $this->editingId
            ? $this->project->scripts()->findOrFail($this->editingId)
            : new Script(['project_id' => $this->project->id, 'code' => '', 'version' => $data['version']]);

        $script->fill([
            'name' => $data['name'],
            'filename' => $data['filename'],
            'language' => $data['language'],
            'notes' => $data['notes'],
            'is_public' => $data['isPublic'],
        ]);

        if ($github) {
            $targetChanged = $script->github_repo !== $github['repo']
                || $script->github_branch !== $github['branch']
                || $script->github_path !== $github['path'];

            $script->fill([
                'github_repo' => $github['repo'],
                'github_branch' => $github['branch'],
                'github_path' => $github['path'],
                'github_sha' => $targetChanged ? null : $script->github_sha,
            ]);
        } else {
            // Simpan kode lama ke riwayat sebelum ditimpa
            if ($script->exists && $script->code !== $data['code']) {
                $script->versions()->create([
                    'version' => $script->version,
                    'code' => $script->code,
                    'notes' => $data['changeNote'] ?: null,
                ]);
            }

            $script->fill([
                'code' => $data['code'],
                'version' => $data['version'],
                'github_repo' => null,
                'github_branch' => null,
                'github_path' => null,
                'github_sha' => null,
                'github_commit' => null,
                'github_commit_message' => null,
                'synced_at' => null,
                'sync_error' => null,
            ]);
        }

        $isNew = ! $script->exists;
        $script->save();

        $this->selectedId = $script->id;
        $this->showForm = false;
        $this->dispatch('project-updated');

        if ($github) {
            $this->runSync($sync, $script);

            return;
        }

        $this->dispatch('toast', message: $isNew ? 'Script ditambahkan' : 'Script diperbarui');
    }

    /**
     * Ambil kode terbaru dari GitHub sekarang juga.
     */
    public function syncNow(int $id, GithubScriptSync $sync): void
    {
        $this->authorize('manage');

        $this->runSync($sync, $this->project->scripts()->findOrFail($id));
    }

    private function runSync(GithubScriptSync $sync, Script $script): void
    {
        try {
            $changed = $sync->sync($script, force: true);
            $this->dispatch('toast', message: $changed
                ? 'Kode diperbarui dari GitHub · v'.$script->version
                : 'Sudah sama dengan GitHub · v'.$script->version);
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function delete(int $id): void
    {
        $this->authorize('manage');

        $this->project->scripts()->findOrFail($id)->delete();

        $this->selectedId = null;
        $this->dispatch('toast', message: 'Script dihapus');
        $this->dispatch('project-updated');
    }

    public function viewVersion(int $id): void
    {
        $this->viewingVersionId = $this->findVersion($id)->id;
        $this->showVersion = true;
    }

    public function restoreVersion(int $id): void
    {
        $this->authorize('manage');

        $old = $this->findVersion($id);
        $script = $old->script;

        $script->versions()->create([
            'version' => $script->version,
            'code' => $script->code,
            'notes' => 'Sebelum restore ke v'.$old->version,
        ]);
        $script->update(['code' => $old->code, 'version' => $old->version]);

        $this->showVersion = false;
        $this->dispatch('toast', message: 'Dikembalikan ke v'.$old->version);
    }

    protected function findVersion(int $id): ScriptVersion
    {
        return ScriptVersion::whereIn('script_id', $this->project->scripts()->select('id'))->findOrFail($id);
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'filename', 'language', 'version', 'code', 'notes', 'changeNote', 'isPublic', 'source', 'githubUrl']);
        $this->resetValidation();
    }

    public function render()
    {
        $scripts = $this->project->scripts()->withCount('versions')->get();
        $selected = $scripts->firstWhere('id', $this->selectedId) ?? $scripts->first();

        // Script GitHub yang sudah lama tidak dicek: ambil versi terbaru saat dibuka
        if ($selected?->isFromGithub()) {
            app(GithubScriptSync::class)->syncIfStale($selected);
        }

        return view('livewire.projects.script-manager', [
            'scripts' => $scripts,
            'selected' => $selected,
            'versions' => $selected?->versions()->take(15)->get() ?? collect(),
            'viewingVersion' => $this->viewingVersionId ? ScriptVersion::find($this->viewingVersionId) : null,
        ]);
    }
}
