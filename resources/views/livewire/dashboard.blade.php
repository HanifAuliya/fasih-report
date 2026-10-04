<div class="space-y-6">
    <x-page-header :eyebrow="now()->translatedFormat('l, d F Y')"
        :title="$greeting.(auth()->check() ? ', '.auth()->user()->name : '')"
        description="Ringkasan progress tindak lanjut: pekerjaan tambahan & yang belum selesai.">
        <x-slot:actions>
            <a href="{{ route('projects.index') }}" wire:navigate class="btn-secondary">
                <x-icon name="grid" class="size-4" /> Daftar pekerjaan
            </a>
            @can('manage')
                <a href="{{ route('projects.index') }}" wire:navigate class="btn-primary">
                    <x-icon name="plus" class="size-4" /> Pekerjaan baru
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $stat)
            <x-stat :label="$stat['label']" :value="$stat['value']" :hint="$stat['hint']" :icon="$stat['icon']" :progress="$stat['progress'] ?? null" />
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        {{-- Progress per data --}}
        <section class="card overflow-hidden">
            <div class="card-header">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                    Progress per data
                </h2>
                <a href="{{ route('projects.index') }}" wire:navigate class="text-[13px] font-medium text-slate-500 hover:text-slate-900">Lihat semua</a>
            </div>

            @forelse ($projects as $project)
                @php
                    $progress = $project->progress();
                    $config = $project->config();
                    $done = $project->kecamatans->where('status', 'selesai')->count();
                @endphp
                <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="p-{{ $project->id }}"
                    class="group grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-3 border-b border-slate-100 px-5 py-4 transition-colors last:border-0 hover:bg-slate-50 md:grid-cols-[auto_minmax(0,1fr)_14rem_auto]">
                    <x-project-avatar :project="$project" />
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900 group-hover:text-brand-700">{{ $project->name }}</p>
                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            {{ $project->type->label() }} · {{ $done }}/{{ $project->kecamatans_count }} {{ strtolower($config->unitLabel()) }} selesai · {{ $project->scripts_count }} script
                        </p>
                    </div>
                    <div class="col-span-2 flex items-center gap-3 md:col-span-1">
                        <x-progress :value="$progress" size="sm" />
                        <span class="w-10 text-right text-[13px] font-semibold text-slate-900 tabular-nums">{{ $progress }}%</span>
                    </div>
                    <span class="btn-secondary btn-sm hidden group-hover:border-brand-500 group-hover:text-brand-700 md:inline-flex">
                        Buka <x-icon name="chevron-right" class="size-3.5" />
                    </span>
                </a>
            @empty
                <div class="px-5 py-14 text-center">
                    <p class="text-sm font-medium text-slate-900">Belum ada manajemen data</p>
                    <p class="mt-1 text-sm text-slate-500">Buat data pertama untuk mulai memantau progress.</p>
                </div>
            @endforelse
        </section>

        {{-- Aktivitas --}}
        <section class="card overflow-hidden">
            <div class="card-header">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                    Aktivitas terbaru
                </h2>
            </div>
            <ol class="relative px-5 py-3">
                @forelse ($recentFiles as $file)
                    <li class="relative flex gap-3 py-2.5">
                        @unless ($loop->last)
                            <span class="absolute top-9 bottom-0 left-[0.9375rem] w-px bg-slate-200"></span>
                        @endunless
                        <span @class([
                            'relative z-10 flex size-[1.875rem] shrink-0 items-center justify-center rounded-full text-[9px] font-bold uppercase ring-4 ring-(--surface)',
                            'bg-amber-50 text-amber-700' => $file->isStatusReport(),
                            'bg-emerald-50 text-emerald-700' => ! $file->isStatusReport(),
                        ])>{{ $file->extension }}</span>
                        <a href="{{ $file->kecamatan ? route('projects.kecamatan', [$file->project, $file->kecamatan->kode]) : route('projects.show', ['project' => $file->project, 'tab' => 'files']) }}" wire:navigate class="min-w-0 flex-1">
                            <p class="truncate text-[13px] font-medium text-slate-800 hover:text-brand-700">{{ $file->original_name }}</p>
                            <p class="mt-0.5 truncate text-xs text-slate-500">
                                {{ $file->kecamatan?->nama ?? $file->project->name }} · {{ $file->created_at->diffForHumans() }}
                            </p>
                        </a>
                    </li>
                @empty
                    <li class="py-10 text-center text-sm text-slate-500">Belum ada aktivitas.</li>
                @endforelse
            </ol>
        </section>
    </div>
</div>
