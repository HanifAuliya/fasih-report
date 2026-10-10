{{--
    Grafik hasil pekerjaan:
    1. Hasil pekerjaan: jumlah baris per hasil (status selain "belum"), bar horizontal diurutkan terbanyak,
       angka & persen tertulis; baris yang belum dikerjakan disebut terpisah.
    2. Hasil per unit: bar bertumpuk horizontal (porsi tiap status), legenda di atas, nilai di tooltip.
       Hanya unit yang sudah dikerjakan yang digambar (terbanyak dikerjakan di atas, sisanya dibuka lewat tombol);
       unit yang belum disentuh sama sekali diringkas jadi satu baris supaya grafik tetap rapi walau unitnya puluhan.
--}}
@php
    $defaultCode = $statuses->defaultCode();
    $legendCodes = $orderedCounts->keys();

    $unitStats = $allUnits
        ->map(function ($unit) use ($statusCounts, $statuses, $defaultCode) {
            $counts = $statusCounts->get($unit->id) ?? collect();
            $total = (int) $counts->sum();

            return (object) [
                'unit' => $unit,
                'counts' => $counts,
                'total' => $total,
                'worked' => $total - (int) $counts->get($defaultCode, 0),
                'done' => (int) $counts->filter(fn ($n, $code) => $statuses->isDone($code))->sum(),
            ];
        })
        ->filter(fn ($stat) => $stat->total > 0);
    $chartUnits = $unitStats->filter(fn ($stat) => $stat->worked > 0)
        ->sortByDesc(fn ($stat) => [$stat->worked / $stat->total, $stat->worked])
        ->values();
    $untouchedUnits = $unitStats->filter(fn ($stat) => $stat->worked === 0)->values();
    $visibleUnits = 8;

    $results = $overallCounts->except([$defaultCode])->filter()->sortDesc();
    $workedTotal = $results->sum();
    $resultMax = max(1, (int) $results->max());
    $notWorked = (int) $overallCounts->get($defaultCode, 0);
@endphp

<div class="grid items-start gap-4 lg:grid-cols-2" x-data="chartTip">
    {{-- 1. Hasil pekerjaan --}}
    <div class="card p-5">
        <h3 class="text-sm font-semibold text-slate-900">Hasil pekerjaan</h3>
        <p class="mt-0.5 text-xs text-slate-500">
            {{ number_format($workedTotal, 0, ',', '.') }} baris sudah dikerjakan · persen dari yang sudah dikerjakan
        </p>

        @if ($workedTotal > 0)
            <div class="mt-4 space-y-2.5">
                @foreach ($results as $code => $n)
                    @php
                        $share = round($n / $workedTotal * 100);
                        $tipArgs = \Illuminate\Support\Js::from([$statuses->label($code), [['baris', number_format($n, 0, ',', '.')], ['dari yang dikerjakan', $share.'%']]]);
                    @endphp
                    <div wire:key="result-{{ $code }}" class="grid grid-cols-[minmax(0,8rem)_1fr_5.5rem] items-center gap-3">
                        <span class="flex min-w-0 items-center gap-1.5 text-xs text-slate-600">
                            <span class="truncate">{{ $statuses->label($code) }}</span>
                            @if ($statuses->isDone($code))
                                <x-icon name="check" class="size-3 shrink-0 text-emerald-600" />
                            @endif
                        </span>
                        <div class="h-5">
                            <span tabindex="0"
                                class="block h-full min-w-[3px] rounded-r-[4px] outline-none transition-opacity hover:opacity-80 focus-visible:ring-2 focus-visible:ring-brand-500 {{ $statuses->dotClasses($code) }}"
                                style="width: {{ $n / $resultMax * 100 }}%"
                                x-on:pointerenter="show($event, ...{{ $tipArgs }})" x-on:pointermove="move($event)" x-on:pointerleave="hide()"
                                x-on:focus="show($event, ...{{ $tipArgs }})" x-on:blur="hide()"></span>
                        </div>
                        <span class="text-right text-xs text-slate-500 tabular-nums">
                            <b class="font-semibold text-slate-900">{{ number_format($n, 0, ',', '.') }}</b> · {{ $share }}%
                        </span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="mt-3 flex h-32 items-center justify-center rounded-lg border border-dashed border-slate-200 px-6 text-center text-xs text-slate-500">
                Belum ada hasil. Upload laporan JSON di halaman {{ strtolower($unitLabel) }} untuk melihat hasilnya.
            </div>
        @endif

        @if ($notWorked > 0)
            <p class="mt-4 flex items-center gap-1.5 border-t border-slate-100 pt-3 text-xs text-slate-500">
                <span class="size-2 rounded-full {{ $statuses->dotClasses($defaultCode) }}"></span>
                {{ $statuses->label($defaultCode) }}: <b class="font-semibold text-slate-700 tabular-nums">{{ number_format($notWorked, 0, ',', '.') }}</b> baris belum dikerjakan
            </p>
        @endif
    </div>

    {{-- 2. Hasil per unit --}}
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Hasil per {{ strtolower($unitLabel) }}</h3>
                <p class="mt-0.5 text-xs text-slate-500">
                    {{ $chartUnits->count() }} dari {{ $unitStats->count() }} {{ strtolower($unitLabel) }} sudah dikerjakan · angka kanan = % selesai
                </p>
            </div>
            <ul class="flex flex-wrap gap-x-3 gap-y-1">
                @foreach ($legendCodes as $code)
                    <li class="flex items-center gap-1.5 text-[11px] text-slate-600">
                        <span class="size-2.5 rounded-[2px] {{ $statuses->dotClasses($code) }}"></span>{{ $statuses->label($code) }}
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-4 space-y-2.5" x-data="{ showAll: false }">
            @forelse ($chartUnits as $stat)
                <div wire:key="chart-unit-{{ $stat->unit->id }}" class="grid grid-cols-[minmax(0,8rem)_1fr_2.75rem] items-center gap-3"
                    @if ($loop->index >= $visibleUnits) x-show="showAll" x-cloak @endif>
                    <a href="{{ route('projects.kecamatan', [$stat->unit->project_id, $stat->unit->kode]) }}" wire:navigate.hover
                        class="truncate text-xs text-slate-600 hover:text-brand-600" title="{{ $stat->unit->nama }} · {{ number_format($stat->worked, 0, ',', '.') }}/{{ number_format($stat->total, 0, ',', '.') }} dikerjakan">{{ $stat->unit->nama }}</a>
                    <div class="flex h-3 gap-[2px]">
                        @foreach ($legendCodes as $code)
                            @if ($n = (int) $stat->counts->get($code))
                                @php
                                    $tipArgs = \Illuminate\Support\Js::from([$stat->unit->nama, [[$statuses->label($code), number_format($n, 0, ',', '.').' baris · '.round($n / $stat->total * 100).'%']]]);
                                @endphp
                                <span tabindex="0"
                                    class="h-full min-w-[3px] outline-none transition-opacity last:rounded-r-[4px] hover:opacity-80 focus-visible:ring-2 focus-visible:ring-brand-500 {{ $statuses->dotClasses($code) }}"
                                    style="width: {{ $n / $stat->total * 100 }}%"
                                    x-on:pointerenter="show($event, ...{{ $tipArgs }})" x-on:pointermove="move($event)" x-on:pointerleave="hide()"
                                    x-on:focus="show($event, ...{{ $tipArgs }})" x-on:blur="hide()"></span>
                            @endif
                        @endforeach
                    </div>
                    <span class="text-right text-xs font-medium text-slate-700 tabular-nums">{{ (int) floor($stat->done / $stat->total * 100) }}%</span>
                </div>
            @empty
                <div class="flex h-24 items-center justify-center rounded-lg border border-dashed border-slate-200 px-6 text-center text-xs text-slate-500">
                    Belum ada {{ strtolower($unitLabel) }} yang dikerjakan.
                </div>
            @endforelse

            @if ($chartUnits->count() > $visibleUnits)
                <button type="button" x-on:click="showAll = ! showAll" class="text-xs font-medium text-brand-600 hover:text-brand-700">
                    <span x-show="! showAll">Tampilkan {{ $chartUnits->count() - $visibleUnits }} {{ strtolower($unitLabel) }} lainnya</span>
                    <span x-show="showAll" x-cloak>Ringkas</span>
                </button>
            @endif
        </div>

        @if ($untouchedUnits->isNotEmpty())
            <details class="group mt-4 border-t border-slate-100 pt-3">
                <summary class="flex cursor-pointer list-none items-center gap-1.5 text-xs text-slate-500 hover:text-slate-700">
                    <span class="size-2 rounded-full {{ $statuses->dotClasses($defaultCode) }}"></span>
                    <b class="font-semibold text-slate-700 tabular-nums">{{ $untouchedUnits->count() }}</b> {{ strtolower($unitLabel) }} belum dikerjakan
                    ({{ number_format($untouchedUnits->sum('total'), 0, ',', '.') }} baris)
                    <x-icon name="chevron-right" class="size-3.5 transition-transform group-open:rotate-90" />
                </summary>
                <div class="mt-2.5 flex flex-wrap gap-1.5">
                    @foreach ($untouchedUnits as $stat)
                        <a href="{{ route('projects.kecamatan', [$stat->unit->project_id, $stat->unit->kode]) }}" wire:navigate.hover
                            class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 hover:bg-slate-200 hover:text-slate-900"
                            title="{{ number_format($stat->total, 0, ',', '.') }} baris">{{ $stat->unit->nama }}</a>
                    @endforeach
                </div>
            </details>
        @endif
    </div>

    {{-- tooltip bersama --}}
    <div x-show="tip" x-cloak class="pointer-events-none fixed z-50 min-w-36 rounded-lg bg-slate-900 px-3 py-2 text-xs text-slate-50 shadow-(--shadow-pop)"
        :style="tip && `left:${tip.x + 12}px; top:${tip.y + 12}px`">
        <p class="text-[11px] opacity-70" x-text="tip?.title"></p>
        <template x-for="row in tip?.rows ?? []">
            <p class="mt-0.5"><span class="font-semibold" x-text="row[1]"></span> <span class="opacity-70" x-text="row[0]"></span></p>
        </template>
    </div>
</div>
