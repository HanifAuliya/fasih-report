<div class="space-y-6">
    <x-page-header title="Daftar pekerjaan" description="Pekerjaan tindak lanjut: tiap pekerjaan punya Excel target, laporan script, dan progress per unit.">
        <x-slot:actions>
            <div class="relative w-full sm:w-64">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                <input wire:model.live.debounce.300ms="search" type="search" class="input pl-9" placeholder="Cari pekerjaan…">
            </div>
            @can('manage')
                <button type="button" wire:click="create" class="btn-primary">
                    <x-icon name="plus" class="size-4" /> Pekerjaan baru
                </button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-3">
        @forelse ($projects as $project)
            @php
                $progress = $project->progress();
                $done = $project->kecamatans->where('status', 'selesai')->count();
            @endphp
            <div wire:key="project-{{ $project->id }}" class="card group relative flex flex-col transition-colors hover:border-brand-300 dark:hover:border-brand-500">
                <div class="flex items-start gap-3 p-5 pb-4">
                    <x-project-avatar :project="$project" />
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('projects.show', $project) }}" wire:navigate class="line-clamp-1 font-semibold text-slate-900 after:absolute after:inset-0 hover:text-brand-700">
                            {{ $project->name }}
                        </a>
                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ $project->type->label() }}</p>
                    </div>
                    @can('manage')
                        <div class="relative z-10 -mt-1 -mr-1 flex opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100">
                            <button type="button" wire:click="edit({{ $project->id }})" class="btn-icon" title="Edit">
                                <x-icon name="pencil" class="size-4" />
                            </button>
                            <button type="button" x-on:click="$confirm({{ \Illuminate\Support\Js::from($project->name.' beserta semua kecamatan, script dan file-nya akan dihapus permanen.') }}, () => $wire.delete({{ $project->id }}), { title: 'Hapus data ini?', danger: true, confirm: 'Ya, hapus' })" class="btn-icon hover:text-rose-600" title="Hapus">
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </div>
                    @endcan
                </div>

                <p class="line-clamp-2 min-h-10 px-5 text-[13px] leading-5 text-slate-500">{{ $project->description ?: 'Tanpa deskripsi.' }}</p>

                <div class="px-5 pt-4 pb-5">
                    <div class="mb-2 flex items-baseline justify-between">
                        <span class="text-xs text-slate-500">{{ $done }}/{{ $project->kecamatans_count }} {{ strtolower($project->config()->unitLabel()) }} selesai</span>
                        <span class="text-sm font-semibold text-slate-900 tabular-nums">{{ $progress }}%</span>
                    </div>
                    <x-progress :value="$progress" size="sm" />
                </div>

                <div class="mt-auto flex items-center gap-4 border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                    <span class="inline-flex items-center gap-1.5"><x-icon name="code" class="size-3.5 text-slate-400" /> {{ $project->scripts_count }} script</span>
                    <span class="inline-flex items-center gap-1.5"><x-icon name="document" class="size-3.5 text-slate-400" /> {{ $project->files_count }} file</span>
                    <span class="ml-auto inline-flex items-center gap-1 font-medium text-brand-600 group-hover:underline">
                        Buka data <x-icon name="chevron-right" class="size-3.5" />
                    </span>
                </div>
            </div>
        @empty
            <div class="card col-span-full flex flex-col items-center p-14 text-center">
                <span class="flex size-11 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-400">
                    <x-icon name="folder" class="size-5" />
                </span>
                <p class="mt-4 font-medium text-slate-900">{{ $search ? 'Tidak ada hasil' : 'Belum ada data' }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ $search ? 'Coba kata kunci lain.' : 'Mulai dengan membuat manajemen data baru.' }}</p>
            </div>
        @endforelse
    </div>

    {{-- Form tambah / edit --}}
    @can('manage')
        <x-modal wire:model="showForm" :title="$editingId ? 'Edit pekerjaan' : 'Pekerjaan baru'">
            <form wire:submit="save">
                <div class="space-y-4 p-5">
                    @include('livewire.projects.partials.project-fields')

                    <div>
                        <label class="label">Jenis Data</label>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach (\App\Enums\ProjectType::cases() as $projectType)
                                <label @class(['cursor-pointer rounded-xl border border-slate-200 p-3 transition has-checked:border-brand-500 has-checked:bg-brand-50/60 has-checked:ring-1 has-checked:ring-brand-500', 'pointer-events-none opacity-60' => $editingId])>
                                    <input type="radio" wire:model.live="type" value="{{ $projectType->value }}" class="sr-only" @disabled($editingId)>
                                    <span class="block text-sm font-medium text-slate-900">{{ $projectType->label() }}</span>
                                    <span class="mt-0.5 block text-xs text-slate-500">{{ $projectType->description() }}</span>
                                </label>
                            @endforeach
                        </div>
                        @if ($editingId)
                            <p class="mt-1.5 text-xs text-slate-400">Jenis data tidak bisa diubah setelah dibuat.</p>
                        @endif
                    </div>

                    @if (! $editingId && $type === 'oss')
                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <input type="checkbox" wire:model="withKecamatans" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span class="text-sm">
                                <span class="font-medium text-slate-800">Isi {{ count(config('fasih.kecamatans')) }} kecamatan default</span>
                                <span class="block text-xs text-slate-500">Haruyan, Batu Benawa, Hantakan, … Limpasu</span>
                            </span>
                        </label>
                    @endif
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" class="btn-secondary" @click="show = false">Batal</button>
                    <button type="submit" class="btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endcan
</div>
