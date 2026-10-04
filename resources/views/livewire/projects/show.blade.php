@php
    $progress = $project->progress();
    $done = $project->kecamatans->where('status', 'selesai')->count();
    $unitLabel = $project->config()->unitLabel();
    $tabs = [
        'progress' => ['label' => 'Progress '.$unitLabel, 'icon' => 'map', 'count' => $project->kecamatans->count()],
        'scripts' => ['label' => 'Script', 'icon' => 'code', 'count' => $project->scripts_count],
        'files' => ['label' => 'File Report', 'icon' => 'document', 'count' => $project->files_count],
    ];

    if (auth()->user()?->can('manage')) {
        $tabs['settings'] = ['label' => 'Pengaturan', 'icon' => 'pencil', 'count' => null];
    }
@endphp

<div class="space-y-6">
    {{-- Header --}}
    <div class="space-y-5">
        <nav class="flex items-center gap-1.5 text-[13px] text-slate-500">
            <a href="{{ route('projects.index') }}" wire:navigate class="hover:text-slate-900">Daftar pekerjaan</a>
            <x-icon name="chevron-right" class="size-3.5 text-slate-300" />
            <span class="truncate text-slate-900">{{ $project->name }}</span>
        </nav>

        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 items-start gap-4">
                <x-project-avatar :project="$project" class="size-12! rounded-xl! text-sm!" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="truncate text-[22px] leading-tight font-semibold tracking-tight text-slate-900">{{ $project->name }}</h1>
                        <span class="badge border border-slate-200 bg-(--surface) text-slate-600">{{ $project->type->label() }}</span>
                    </div>
                    <p class="mt-1 max-w-3xl text-sm text-slate-500">{{ $project->description ?: 'Tanpa deskripsi.' }}</p>
                </div>
            </div>
            @can('manage')
                <button type="button" wire:click="edit" class="btn-secondary shrink-0">
                    <x-icon name="pencil" class="size-4" /> Edit data
                </button>
            @endcan
        </div>

        {{-- KPI --}}
        <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
            <x-stat label="Progress" :value="$progress.'%'" :progress="$progress" icon="chart" />
            <x-stat :label="$unitLabel.' selesai'" :value="$done.' / '.$project->kecamatans->count()" icon="map" />
            <x-stat label="Baris selesai" :value="number_format($project->kecamatans->sum('realisasi'), 0, ',', '.')" :hint="'dari '.number_format($project->kecamatans->sum('target'), 0, ',', '.').' baris'" icon="check" />
            <x-stat label="Script & file" :value="$project->scripts_count.' · '.$project->files_count" hint="script · file report" icon="code" />
        </div>

        {{-- Tabs --}}
        <nav class="scroll-thin -mb-px flex gap-5 overflow-x-auto border-b border-slate-200">
            @foreach ($tabs as $key => $t)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                    'relative inline-flex items-center gap-2 pt-1 pb-3 text-sm font-medium whitespace-nowrap transition-colors',
                    'text-slate-900' => $tab === $key,
                    'text-slate-500 hover:text-slate-800' => $tab !== $key,
                ])>
                    <x-icon :name="$t['icon']" :class="'size-4'.($tab === $key ? ' text-brand-600' : ' text-slate-400')" />
                    {{ $t['label'] }}
                    @if ($t['count'] !== null)
                        <span class="rounded-full bg-slate-100 px-1.5 text-[11px] font-medium text-slate-500 tabular-nums">{{ $t['count'] }}</span>
                    @endif
                    @if ($tab === $key)
                        <span class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-brand-600"></span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Isi tab --}}
    @if ($tab === 'progress')
        <livewire:projects.kecamatan-table :project="$project" :key="'kec-'.$project->id" />
    @elseif ($tab === 'scripts')
        <livewire:projects.script-manager :project="$project" :key="'scr-'.$project->id" />
    @elseif ($tab === 'settings')
        <livewire:projects.project-settings-form :project="$project" :key="'settings-'.$project->id" />
    @else
        <livewire:projects.file-manager :project="$project" :key="'file-'.$project->id" />
    @endif

    @can('manage')
        <x-modal wire:model="showForm" title="Edit Data">
            <form wire:submit="save">
                <div class="p-5">
                    @include('livewire.projects.partials.project-fields')
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" class="btn-secondary" @click="show = false">Batal</button>
                    <button type="submit" class="btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endcan
</div>
