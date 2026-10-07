@php
    // Item aktif: "kartu" putih di atas sidebar abu
    $item = fn (bool $active) => $active
        ? 'bg-brand-50 font-semibold text-brand-700 [&_svg]:text-brand-600'
        : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900';
    $avatar = [
        'indigo' => 'bg-brand-100 text-brand-700', 'emerald' => 'bg-emerald-100 text-emerald-700', 'sky' => 'bg-sky-100 text-sky-700',
        'amber' => 'bg-amber-100 text-amber-700', 'rose' => 'bg-rose-100 text-rose-700', 'violet' => 'bg-violet-100 text-violet-700',
        'teal' => 'bg-teal-100 text-teal-700', 'slate' => 'bg-slate-200 text-slate-700',
    ];
    $initials = fn (string $name) => collect(preg_split('/\s+/', trim($name)))->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('');
@endphp

{{-- Posisi scroll disimpan supaya tidak loncat ke atas setiap pindah halaman --}}
<nav class="scroll-thin flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-2 py-2"
    x-data
    x-init="try { $el.scrollTop = Number(sessionStorage.getItem('sidebar-scroll') || 0) } catch (e) {}"
    x-on:scroll.debounce.100ms="try { sessionStorage.setItem('sidebar-scroll', $el.scrollTop) } catch (e) {}"
>

    {{-- Menu utama --}}
    <div class="space-y-0.5">
        @foreach ([['dashboard', 'dashboard', 'home', 'Dashboard'], ['projects', 'projects.index', 'grid', 'Daftar pekerjaan']] as [$key, $route, $icon, $label])
            <a href="{{ route($route) }}" wire:navigate.hover title="{{ $label }}"
                class="flex h-9 items-center gap-2.5 rounded-lg px-2.5 text-[13px] transition {{ $item($section === $key) }} lg:sb-collapsed:justify-center lg:sb-collapsed:px-0">
                <x-icon :name="$icon" class="size-[1.05rem] shrink-0 text-slate-400" />
                <span class="truncate lg:sb-collapsed:hidden">{{ $label }}</span>
            </a>
        @endforeach
    </div>

    {{-- Pohon data → unit --}}
    <div>
        <div class="flex h-7 items-center justify-between px-2.5 lg:sb-collapsed:hidden">
            <p class="eyebrow">Pekerjaan</p>
            @can('manage')
                <a href="{{ route('projects.index') }}" wire:navigate.hover class="flex size-6 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Kelola / tambah pekerjaan">
                    <x-icon name="plus" class="size-3.5" />
                </a>
            @endcan
        </div>
        <div class="mx-auto mb-2 hidden h-px w-6 bg-slate-200 lg:sb-collapsed:block"></div>

        <div class="space-y-0.5">
            @forelse ($projects as $project)
                @php
                    $isActive = $project->id === $activeProjectId;
                    $units = $project->kecamatans;
                @endphp

                <div wire:key="nav-project-{{ $project->id }}">
                    <a href="{{ route('projects.show', $project) }}" wire:navigate.hover title="{{ $project->name }}"
                            class="flex h-9 min-w-0 items-center gap-2.5 rounded-lg px-2.5 text-[13px] transition {{ $item($isActive && ! $activeKode) }} {{ $isActive && $activeKode ? 'font-semibold !text-slate-900' : '' }} lg:sb-collapsed:justify-center lg:sb-collapsed:px-0">
                            <span class="flex size-5 shrink-0 items-center justify-center rounded-md text-[9px] font-bold uppercase {{ $avatar[$project->color] ?? $avatar['indigo'] }}">{{ $initials($project->name) }}</span>
                            <span class="truncate lg:sb-collapsed:hidden">{{ $project->name }}</span>
                        </a>

                    {{-- Daftar unit: hanya untuk pekerjaan yang sedang dibuka --}}
                    @if ($isActive && $units->isNotEmpty())
                        <div class="lg:sb-collapsed:hidden">
                            <div class="relative mt-0.5 mb-1.5 ml-[1.25rem] space-y-px border-l border-slate-200 pl-2">
                                @foreach ($units as $unit)
                                    @php $unitActive = $isActive && $activeKode === $unit->kode; @endphp
                                    <a href="{{ route('projects.kecamatan', [$project, $unit->kode]) }}" wire:navigate.hover wire:key="nav-unit-{{ $unit->id }}"
                                        title="Buka data {{ $unit->nama }}"
                                        @class([
                                            'relative flex h-8 items-center gap-2 rounded-lg px-2 text-[12.5px] transition',
                                            'bg-brand-50 font-semibold text-brand-700' => $unitActive,
                                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $unitActive,
                                        ])>
                                        @if ($unitActive)
                                            <span class="absolute top-2 bottom-2 -left-[0.5625rem] w-0.5 rounded-full bg-brand-600"></span>
                                        @endif
                                        <span class="flex-1 truncate capitalize">{{ mb_strtolower($unit->nama) }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <p class="px-2.5 text-xs text-slate-400 lg:sb-collapsed:hidden">Belum ada data.</p>
            @endforelse

        </div>
    </div>
</nav>
