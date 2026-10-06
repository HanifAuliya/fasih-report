@php
    $statuses = $project->config()->statuses();
    // Isi sel bisa berupa URL atau HTML <a href="…">; ambil URL-nya supaya bisa diklik
    $linkOf = fn ($value) => is_string($value) && preg_match('~https?://[^\s"<>]+~', $value, $match) ? $match[0] : null;
    $trackedTotal = $statusCounts->sum();
    $percent = $kecamatan->percent();
@endphp

<div class="space-y-5">
    {{-- Breadcrumb & navigasi unit --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <nav class="flex min-w-0 items-center gap-1.5 text-sm text-slate-500">
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="inline-flex items-center gap-1.5 truncate hover:text-slate-900">
                <x-icon name="arrow-left" class="size-4 shrink-0" /> {{ $project->name }}
            </a>
            <x-icon name="chevron-right" class="size-3.5 shrink-0 text-slate-300" />
            <span class="truncate font-medium text-slate-900">{{ $kecamatan->nama }}</span>
        </nav>
        <div class="flex items-center gap-1">
            @if ($previousKecamatan)
                <a href="{{ route('projects.kecamatan', [$project, $previousKecamatan->kode]) }}" wire:navigate class="btn-ghost py-1.5 text-xs">
                    <x-icon name="chevron-left" class="size-3.5" /> {{ $previousKecamatan->nama }}
                </a>
            @endif
            @if ($nextKecamatan)
                <a href="{{ route('projects.kecamatan', [$project, $nextKecamatan->kode]) }}" wire:navigate class="btn-ghost py-1.5 text-xs">
                    {{ $nextKecamatan->nama }} <x-icon name="chevron-right" class="size-3.5" />
                </a>
            @endif
        </div>
    </div>

    {{-- Header + ringkasan --}}
    <div class="card overflow-hidden">
        <div class="flex flex-col gap-5 p-5 lg:flex-row lg:items-center">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span class="badge bg-slate-100 font-mono text-slate-500">{{ $kecamatan->kode }}</span>
                    @if ($kecamatan->catatan)
                        <span class="truncate text-xs text-slate-400">{{ $kecamatan->catatan }}</span>
                    @endif
                </div>
                <h1 class="mt-1.5 text-2xl font-semibold tracking-tight text-slate-900">{{ $kecamatan->nama }}</h1>
                @if ($sheet?->sourceFile)
                    <p class="mt-1 text-xs text-slate-500">Sumber: {{ $sheet->sourceFile->original_name }} · diimpor {{ $sheet->created_at->diffForHumans() }}</p>
                @endif
            </div>
            <div class="flex items-center gap-4 lg:w-[26rem]">
                <div class="flex-1">
                    <div class="mb-1.5 flex items-baseline justify-between">
                        <span class="text-sm text-slate-500">Progress</span>
                        <span class="text-sm font-semibold text-slate-900">{{ $percent }}% <span class="font-normal text-slate-400">· {{ number_format($kecamatan->realisasi, 0, ',', '.') }} / {{ number_format($kecamatan->target, 0, ',', '.') }}</span></span>
                    </div>
                    <x-progress :value="$percent" />
                </div>
                @if ($sheets->isNotEmpty())
                    <a href="{{ route('projects.kecamatan.export', [$project, $kecamatan->kode]) }}" class="btn-secondary shrink-0" title="Download Excel dengan status terbaru">
                        <x-icon name="download" class="size-4" /> Excel
                    </a>
                @endif
            </div>
        </div>

        @if ($tracked && $trackedTotal)
            <div class="flex items-center gap-2 border-t border-slate-100 px-5 pt-3 text-xs text-slate-500">
                <x-icon name="search" class="size-3.5" /> Klik salah satu kotak untuk menyaring tabel berdasarkan status
            </div>
            <div class="grid grid-cols-2 gap-2 p-3 sm:grid-cols-3 lg:grid-flow-col lg:auto-cols-fr lg:grid-cols-none">
                <button type="button" wire:click="$set('statusFilter', '')" @class(['rounded-lg border p-3 text-left transition', 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' => $statusFilter === '', 'border-slate-200 hover:border-slate-300 hover:bg-slate-50' => $statusFilter !== ''])>
                    <p class="text-xs text-slate-500">Semua baris</p>
                    <p class="mt-1 text-xl font-semibold text-slate-900 tabular-nums">{{ number_format($trackedTotal, 0, ',', '.') }}</p>
                </button>
                @foreach ($statuses->all() as $code => $status)
                    @if ($statusCounts->get($code))
                        <button type="button" wire:key="stat-{{ $code }}" wire:click="$set('statusFilter', '{{ $code }}')" @class(['rounded-lg border p-3 text-left transition', 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' => $statusFilter === $code, 'border-slate-200 hover:border-slate-300 hover:bg-slate-50' => $statusFilter !== $code])>
                            <p class="flex items-center gap-1.5 text-xs text-slate-500">
                                <span class="size-2 rounded-full {{ \App\Support\StatusSet::COLORS[$status['color']]['dot'] }}"></span>
                                {{ $status['label'] }}
                            </p>
                            <p class="mt-1 text-xl font-semibold text-slate-900 tabular-nums">
                                {{ number_format($statusCounts->get($code), 0, ',', '.') }}
                                <span class="text-xs font-normal text-slate-400">{{ round($statusCounts->get($code) / $trackedTotal * 100) }}%</span>
                            </p>
                        </button>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    @if ($sheets->isNotEmpty())
        @include('livewire.projects.partials.unit-reports')
    @endif

    @if ($sheets->isEmpty())
        <div class="card flex flex-col items-center p-12 text-center">
            <span class="flex size-12 items-center justify-center rounded-xl bg-slate-100 text-slate-400">
                <x-icon name="table" class="size-6" />
            </span>
            <p class="mt-4 font-medium text-slate-900">Belum ada data Excel</p>
            <p class="mt-1 text-sm text-slate-500">Upload Excel untuk {{ $kecamatan->nama }} lewat tombol "Upload Excel" di tab Progress.</p>
        </div>
    @else
        <div x-data="{ full: false }" x-on:keydown.escape.window="full = false"
            :class="full ? 'fixed inset-0 z-50 flex flex-col rounded-none border-0' : ''"
            class="card overflow-hidden">
            {{-- Tab sheet (seperti Excel) --}}
            <div class="flex items-center gap-1 overflow-x-auto border-b border-slate-200 bg-slate-50 px-2 pt-2">
                @foreach ($sheets as $tab)
                    <button type="button" wire:key="sheet-{{ $tab->id }}" wire:click="$set('sheetId', {{ $tab->id }})" @class([
                        'rounded-t-lg border border-b-0 px-3.5 py-2 text-xs font-medium whitespace-nowrap transition',
                        'border-slate-200 bg-white text-emerald-700' => $sheet->is($tab),
                        'border-transparent text-slate-500 hover:bg-white/60 hover:text-slate-800' => ! $sheet->is($tab),
                    ])>
                        {{ $tab->name }} <span class="ml-1 text-slate-400">{{ number_format($tab->row_count, 0, ',', '.') }}</span>
                    </button>
                @endforeach
                <span x-show="full" x-cloak class="ml-auto truncate px-3 pb-2 text-xs font-medium text-slate-500">{{ $project->name }} · {{ $kecamatan->nama }}</span>
            </div>

            {{-- Bar ubah status sekaligus: muncul saat ada baris yang dicentang --}}
            @can('manage')
                @if ($tracked)
                    <div x-data="{ bulkStatus: @js($statuses->defaultCode()) }" x-show="$wire.selected.length" x-cloak x-transition.opacity
                        class="flex flex-wrap items-center gap-2 border-b border-brand-200 bg-brand-50 px-3 py-2.5 text-sm">
                        <span class="font-semibold text-brand-700"><span x-text="$wire.selected.length"></span> baris dipilih</span>
                        <span class="text-slate-500">· Ubah status ke</span>
                        <select x-model="bulkStatus" class="input w-auto py-1.5 text-xs">
                            @foreach ($statuses->all() as $code => $status)
                                <option value="{{ $code }}">{{ $status['label'] }}</option>
                            @endforeach
                        </select>
                        <button type="button" x-on:click="$wire.setStatusForSelected(bulkStatus)" class="btn-primary btn-sm" wire:loading.attr="disabled" wire:target="setStatusForSelected">
                            <x-icon name="check" class="size-3.5" /> Terapkan
                        </button>
                        <button type="button" x-on:click="$wire.selected = []" class="btn-ghost btn-sm">Batal</button>
                    </div>
                @endif
            @endcan

            {{-- Toolbar --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3">
                <div class="relative min-w-0 flex-1 sm:max-w-sm">
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                    <input wire:model.live.debounce.300ms="search" type="search" class="input py-1.5 pl-9" placeholder="Cari nama, desa, id…">
                </div>

                @if ($tracked)
                    <select wire:model.live="statusFilter" class="input w-auto py-1.5 text-xs" title="Saring berdasarkan status">
                        <option value="">Semua status</option>
                        @foreach ($statuses->all() as $code => $status)
                            @if ($statusCounts->get($code))
                                <option value="{{ $code }}">{{ $status['label'] }} ({{ number_format($statusCounts->get($code), 0, ',', '.') }})</option>
                            @endif
                        @endforeach
                    </select>
                @endif

                {{-- Filter per kolom ala Excel: pilih kolom, lalu pilih nilainya --}}
                <div class="relative" x-data="{ open: false, q: '' }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                    <button type="button" x-on:click="open = ! open" class="btn-secondary px-2.5 py-1.5 text-xs" :aria-expanded="open">
                        <x-icon name="filter" class="size-3.5" /> Filter kolom
                        @if ($columnFilters)
                            <span class="rounded-full bg-brand-600 px-1.5 text-[10px] font-semibold text-white">{{ count($columnFilters) }}</span>
                        @endif
                    </button>
                    <div x-show="open" x-cloak x-transition.opacity class="card absolute left-0 z-30 mt-1.5 w-80 p-3 shadow-(--shadow-pop)">
                        <label class="label text-xs">Kolom</label>
                        <select wire:model.live="filterColumn" class="input py-1.5 text-xs">
                            <option value="">Pilih kolom…</option>
                            @foreach ($sheet->headers as $index => $header)
                                <option value="{{ $index }}">{{ $header }}</option>
                            @endforeach
                        </select>

                        @if ($filterColumn !== null)
                            <input x-model="q" type="search" class="input mt-2 py-1.5 text-xs" placeholder="Cari nilai…">
                            <ul class="scroll-thin mt-2 max-h-64 space-y-px overflow-y-auto" wire:loading.class="opacity-50" wire:target="filterColumn">
                                @foreach ($filterValues as $value => $total)
                                    @php
                                        $value = (string) $value;
                                        $valueJs = \Illuminate\Support\Js::from($value);
                                        $searchJs = \Illuminate\Support\Js::from(mb_strtolower($value));
                                    @endphp
                                    <li x-show="! q || {{ $searchJs }}.includes(q.toLowerCase())">
                                        <button type="button" wire:click="addColumnFilter({{ $filterColumn }}, {{ $valueJs }})" x-on:click="open = false; q = ''"
                                            class="flex w-full items-center justify-between gap-3 rounded-md px-2 py-1.5 text-left text-xs hover:bg-slate-100">
                                            <span class="truncate {{ $value === '' ? 'text-slate-400 italic' : 'text-slate-700' }}">{{ $value === '' ? '(kosong)' : \Illuminate\Support\Str::limit(strip_tags($value), 60) }}</span>
                                            <span class="shrink-0 text-slate-400 tabular-nums">{{ number_format($total, 0, ',', '.') }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                @foreach ($columnFilters as $column => $value)
                    <button type="button" wire:key="chip-{{ $column }}" wire:click="removeColumnFilter({{ (int) $column }})" class="badge chip-active max-w-56 px-2.5 py-1" title="Hapus filter ini">
                        <span class="truncate">{{ $sheet->headers[(int) $column] ?? 'Kolom' }}: {{ $value === '' ? '(kosong)' : \Illuminate\Support\Str::limit(strip_tags($value), 30) }}</span>
                        <x-icon name="x" class="size-3 shrink-0" />
                    </button>
                @endforeach

                @if ($columnFilters || $statusFilter !== '' || $search !== '')
                    <button type="button" wire:click="clearFilters" class="text-xs text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline">Reset filter</button>
                @endif

                <div class="ml-auto flex items-center gap-2">
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 text-xs text-slate-600 hover:bg-slate-100">
                        <input type="checkbox" wire:model.live="compact" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        Kolom ringkas
                    </label>
                    <select wire:model.live="perPage" class="input w-auto py-1.5 text-xs" title="Baris per halaman">
                        @foreach ([25, 50, 100, 250, 500] as $size)
                            <option value="{{ $size }}">{{ $size }} / hal</option>
                        @endforeach
                    </select>
                    <button type="button" x-on:click="full = ! full" class="btn-secondary px-2.5 py-1.5" :title="full ? 'Keluar layar penuh (Esc)' : 'Layar penuh'">
                        <x-icon name="expand" class="size-4" x-show="! full" />
                        <x-icon name="compress" class="size-4" x-show="full" x-cloak />
                    </button>
                </div>
            </div>

            {{-- Tabel --}}
            <div class="scroll-thin relative overflow-auto" :class="full ? 'flex-1' : 'h-[calc(100vh-13rem)] min-h-[24rem]'"
                wire:loading.class="opacity-60" wire:target="sheetId,statusFilter,search,compact,perPage,gotoPage,nextPage,previousPage,addColumnFilter,removeColumnFilter,clearFilters">
                <table class="data-grid w-full border-separate border-spacing-0 text-xs">
                    <thead class="sticky top-0 z-10">
                        <tr class="text-left text-[11px] font-semibold text-slate-600">
                            <th class="sticky left-0 z-10 bg-slate-100 px-2 py-2.5 text-left text-slate-500">
                                <span class="flex items-center gap-2">
                                    @can('manage')
                                        @if ($tracked && $rows->isNotEmpty())
                                            <input type="checkbox" title="Pilih semua baris di halaman ini"
                                                x-data="{ ids: @js($rows->pluck('id')->map(fn ($id) => (string) $id)->values()) }"
                                                :checked="ids.length && ids.every(id => $wire.selected.includes(id))"
                                                x-on:change="$wire.selected = $event.target.checked ? [...ids] : []"
                                                class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        @endif
                                    @endcan
                                    Baris
                                </span>
                            </th>
                            @if ($tracked)
                                <th class="bg-slate-100 px-3 py-2.5 whitespace-nowrap">Status</th>
                                <th class="bg-slate-100 px-3 py-2.5 whitespace-nowrap">Keterangan</th>
                            @endif
                            @foreach ($columns as $header)
                                <th class="bg-slate-100 px-3 py-2.5 font-mono whitespace-nowrap">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="row-{{ $row->id }}" class="group {{ $statuses->rowClasses($row->status) }} hover:bg-brand-50/60">
                                <td class="sticky left-0 bg-slate-50 px-2 py-1 whitespace-nowrap group-hover:bg-brand-50">
                                    @can('manage')
                                        @if ($tracked)
                                            <input type="checkbox" wire:model="selected" value="{{ $row->id }}" title="Pilih baris ini"
                                                class="mr-1.5 rounded border-slate-300 align-middle text-brand-600 focus:ring-brand-500">
                                        @endif
                                    @endcan
                                    <button type="button" wire:click="openDetail({{ $row->id }})" title="Lihat semua isi baris ini"
                                        class="inline-flex h-6 items-center gap-1.5 rounded-md border border-slate-200 bg-(--surface) px-1.5 text-[11px] font-medium text-slate-600 tabular-nums transition hover:border-brand-500 hover:text-brand-700">
                                        <x-icon name="eye" class="size-3.5" /> {{ $row->row_number }}
                                    </button>
                                </td>
                                @if ($tracked)
                                    <td class="whitespace-nowrap">
                                        @if ($row->status)
                                            <div class="relative inline-flex items-center">
                                                <span class="badge ring-1 {{ $statuses->badgeClasses($row->status) }}">
                                                    {{ $statuses->label($row->status) }}
                                                    @can('manage')
                                                        <x-icon name="chevron-right" class="size-3 rotate-90 opacity-60" />
                                                    @endcan
                                                </span>
                                                @can('manage')
                                                    <select wire:change="setStatus({{ $row->id }}, $event.target.value)" class="absolute inset-0 opacity-0" title="Klik untuk mengubah status">
                                                        @foreach ($statuses->all() as $code => $status)
                                                            <option value="{{ $code }}" @selected($row->status === $code)>{{ $status['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                @endcan
                                            </div>
                                        @endif
                                    </td>
                                    <td class="max-w-xs truncate text-slate-500" title="{{ $row->reason }}">{{ $row->reason }}</td>
                                @endif
                                @foreach ($columns as $index => $header)
                                    @php $value = $row->cells[$index] ?? null; @endphp
                                    <td class="cell" title="{{ $value }}">
                                        @if ($link = $linkOf($value))
                                            <a href="{{ $link }}" target="_blank" rel="noopener" class="link inline-flex items-center gap-1" title="Buka di tab baru">
                                                <x-icon name="link" class="size-3" /> Buka
                                            </a>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($columns) + ($tracked ? 3 : 1) }}" class="border-0! px-4 py-12 text-center text-sm text-slate-500">Tidak ada baris yang cocok.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rows->hasPages())
                <div class="border-t border-slate-100 px-4 py-2.5">
                    {{ $rows->onEachSide(1)->links(data: ['scrollTo' => false]) }}
                </div>
            @endif
        </div>
    @endif

    {{-- Detail baris --}}
    <x-modal wire:model="showDetail" :title="$detail ? 'Baris '.$detail->row_number.' · '.$detail->sheet->name : 'Detail'" max-width="2xl">
        @if ($detail)
            <div class="max-h-[70vh] space-y-4 overflow-y-auto p-5">
                @if ($detail->status)
                    <div class="rounded-xl bg-slate-50 p-4">
                        <div class="flex items-center gap-2">
                            <span class="badge ring-1 {{ $statuses->badgeClasses($detail->status) }}">{{ $statuses->label($detail->status) }}</span>
                            @if ($detail->status_at)
                                <span class="text-xs text-slate-500">{{ $detail->status_at->translatedFormat('d M Y H:i') }}</span>
                            @endif
                        </div>
                        @if ($detail->reason)
                            <p class="mt-2 text-sm text-slate-700">{{ $detail->reason }}</p>
                        @endif
                        @if ($detail->statusFile)
                            <p class="mt-1 text-xs text-slate-400">dari laporan {{ $detail->statusFile->original_name }}</p>
                        @endif
                        @if ($detail->result)
                            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                                @foreach ($detail->result as $key => $value)
                                    <dt class="font-mono text-slate-400">{{ $key }}</dt>
                                    <dd class="truncate text-slate-700" title="{{ $value }}">{{ $value }}</dd>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                @endif

                <dl class="divide-y divide-slate-100 text-sm">
                    @foreach ($detail->sheet->headers as $index => $header)
                        @php $value = $detail->cells[$index] ?? null; @endphp
                        <div class="grid grid-cols-3 gap-3 py-2">
                            <dt class="font-mono text-xs text-slate-500">{{ $header }}</dt>
                            <dd class="col-span-2 break-words text-slate-800">
                                @if ($link = $linkOf($value))
                                    <a href="{{ $link }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">{{ $link }}</a>
                                @else
                                    {{ $value ?? '–' }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
    </x-modal>
</div>
