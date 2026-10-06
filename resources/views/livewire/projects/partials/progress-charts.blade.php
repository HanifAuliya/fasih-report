{{--
    Grafik hasil pekerjaan:
    1. Hasil pekerjaan: jumlah baris per hasil (status selain "belum"), bar horizontal diurutkan terbanyak,
       angka & persen tertulis; baris yang belum dikerjakan disebut terpisah.
    2. Hasil per unit: bar bertumpuk horizontal (porsi tiap status), legenda di atas, nilai di tooltip & tabel di bawah.
--}}
@php
    $chartUnits = $allUnits->filter(fn ($unit) => $statusCounts->get($unit->id)?->sum());
    $legendCodes = $orderedCounts->keys();

    $defaultCode = $statuses->defaultCode();
    $results = $overallCounts->except([$defaultCode])->filter()->sortDesc();
    $workedTotal = $results->sum();
    $resultMax = max(1, (int) $results->max());
    $notWorked = (int) $overallCounts->get($defaultCode, 0);
@endphp

<div class="grid gap-4 lg:grid-cols-2" x-data="chartTip">
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
                <p class="mt-0.5 text-xs text-slate-500">Porsi tiap status dari semua baris; angka kanan = % selesai</p>
            </div>
            <ul class="flex flex-wrap gap-x-3 gap-y-1">
                @foreach ($legendCodes as $code)
                    <li class="flex items-center gap-1.5 text-[11px] text-slate-600">
                        <span class="size-2.5 rounded-[2px] {{ $statuses->dotClasses($code) }}"></span>{{ $statuses->label($code) }}
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-4 space-y-2.5">
            @foreach ($chartUnits as $unit)
                @php
                    $unitCounts = $statusCounts->get($unit->id);
                    $unitTotal = $unitCounts->sum();
                    $unitDone = $unitCounts->filter(fn ($n, $code) => $statuses->isDone($code))->sum();
                @endphp
                <div wire:key="chart-unit-{{ $unit->id }}" class="grid grid-cols-[minmax(0,8rem)_1fr_2.75rem] items-center gap-3">
                    <span class="truncate text-xs text-slate-600" title="{{ $unit->nama }}">{{ $unit->nama }}</span>
                    <div class="flex h-3 gap-[2px]">
                        @foreach ($legendCodes as $code)
                            @if ($n = (int) $unitCounts->get($code))
                                @php
                                    $tipArgs = \Illuminate\Support\Js::from([$unit->nama, [[$statuses->label($code), number_format($n, 0, ',', '.').' baris · '.round($n / $unitTotal * 100).'%']]]);
                                @endphp
                                <span tabindex="0"
                                    class="h-full min-w-[3px] outline-none transition-opacity last:rounded-r-[4px] hover:opacity-80 focus-visible:ring-2 focus-visible:ring-brand-500 {{ $statuses->dotClasses($code) }}"
                                    style="width: {{ $n / $unitTotal * 100 }}%"
                                    x-on:pointerenter="show($event, ...{{ $tipArgs }})" x-on:pointermove="move($event)" x-on:pointerleave="hide()"
                                    x-on:focus="show($event, ...{{ $tipArgs }})" x-on:blur="hide()"></span>
                            @endif
                        @endforeach
                    </div>
                    <span class="text-right text-xs font-medium text-slate-700 tabular-nums">{{ $unitTotal ? (int) floor($unitDone / $unitTotal * 100) : 0 }}%</span>
                </div>
            @endforeach
        </div>
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
