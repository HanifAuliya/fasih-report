{{--
    Grafik progress pekerjaan:
    1. Status per unit: bar bertumpuk horizontal (porsi tiap status), legenda di atas, nilai di tooltip & tabel di bawah.
    2. Perkembangan selesai: garis kumulatif per hari (satu seri), crosshair + tooltip.
--}}
@php
    $chartUnits = $allUnits->filter(fn ($unit) => $statusCounts->get($unit->id)?->sum());
    $legendCodes = $orderedCounts->keys();

    // Sumbu Y grafik garis: dibulatkan ke angka "bersih"
    $trendMax = max(1, collect($doneTrend)->max('total') ?? 1);
    $magnitude = 10 ** max(0, strlen((string) $trendMax) - 1);
    $yMax = (int) (ceil($trendMax / $magnitude) * $magnitude);
    $chart = ['w' => 600, 'h' => 200, 'left' => 44, 'right' => 12, 'top' => 12, 'bottom' => 26];
    $plotW = $chart['w'] - $chart['left'] - $chart['right'];
    $plotH = $chart['h'] - $chart['top'] - $chart['bottom'];
    $count = count($doneTrend);
    $points = collect($doneTrend)->values()->map(fn ($point, $i) => [
        ...$point,
        'x' => round($chart['left'] + ($count > 1 ? $i / ($count - 1) : 0.5) * $plotW, 2),
        'y' => round($chart['top'] + $plotH - $point['total'] / $yMax * $plotH, 2),
    ]);
    $linePath = $points->map(fn ($p, $i) => ($i ? 'L' : 'M').$p['x'].' '.$p['y'])->implode(' ');
    $baseY = $chart['top'] + $plotH;
    $pointsJson = $points->map(fn ($p) => [
        'x' => $p['x'],
        'y' => $p['y'],
        'label' => $p['label'],
        'total' => number_format($p['total'], 0, ',', '.'),
        'added' => $p['added'],
    ])->toJson();
    $areaPath = $points->isNotEmpty()
        ? $linePath.' L'.$points->last()['x'].' '.$baseY.' L'.$points->first()['x'].' '.$baseY.' Z'
        : '';
@endphp

<div class="grid gap-4 lg:grid-cols-2" x-data="chartTip">
    {{-- 1. Status per unit --}}
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Status per {{ strtolower($unitLabel) }}</h3>
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

    {{-- 2. Perkembangan baris selesai --}}
    <div class="card p-5">
        <h3 class="text-sm font-semibold text-slate-900">Perkembangan baris selesai</h3>
        <p class="mt-0.5 text-xs text-slate-500">Jumlah kumulatif menurut waktu status (laporan JSON / ubah manual)</p>

        @if ($count >= 2)
            <div class="relative mt-3"
                x-on:pointermove="trend($event, $refs.plot)" x-on:pointerleave="hide(); cursor = null">
                <svg x-ref="plot" viewBox="0 0 {{ $chart['w'] }} {{ $chart['h'] }}" class="h-auto w-full overflow-visible text-slate-200" role="img"
                    aria-label="Baris selesai kumulatif: dari {{ $points->first()['total'] }} menjadi {{ $points->last()['total'] }}"
                    data-points="{{ $pointsJson }}">
                    {{-- grid & sumbu Y (0, ½, max) --}}
                    @foreach ([0, 0.5, 1] as $fraction)
                        @php $gy = $chart['top'] + $plotH - $fraction * $plotH; @endphp
                        <line x1="{{ $chart['left'] }}" x2="{{ $chart['w'] - $chart['right'] }}" y1="{{ $gy }}" y2="{{ $gy }}" stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke" />
                        <text x="{{ $chart['left'] - 8 }}" y="{{ $gy + 4 }}" text-anchor="end" class="fill-slate-400 text-[11px] tabular-nums">{{ number_format($yMax * $fraction, 0, ',', '.') }}</text>
                    @endforeach

                    <path d="{{ $areaPath }}" class="fill-brand-600/10" />
                    <path d="{{ $linePath }}" fill="none" class="stroke-brand-600" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />

                    {{-- label tanggal pertama & terakhir --}}
                    <text x="{{ $points->first()['x'] }}" y="{{ $chart['h'] - 6 }}" class="fill-slate-400 text-[11px]">{{ $points->first()['label'] }}</text>
                    <text x="{{ $points->last()['x'] }}" y="{{ $chart['h'] - 6 }}" text-anchor="end" class="fill-slate-400 text-[11px]">{{ $points->last()['label'] }}</text>

                    {{-- titik akhir + nilainya --}}
                    <circle cx="{{ $points->last()['x'] }}" cy="{{ $points->last()['y'] }}" r="4" class="fill-brand-600 stroke-(--surface)" stroke-width="2" />
                    <text x="{{ $points->last()['x'] - 8 }}" y="{{ $points->last()['y'] - 10 }}" text-anchor="end" class="fill-slate-900 text-[12px] font-semibold tabular-nums">{{ number_format($points->last()['total'], 0, ',', '.') }}</text>

                    {{-- crosshair --}}
                    <g x-show="cursor" x-cloak>
                        <line :x1="cursor?.x" :x2="cursor?.x" y1="{{ $chart['top'] }}" y2="{{ $baseY }}" class="stroke-slate-400" stroke-width="1" vector-effect="non-scaling-stroke" />
                        <circle :cx="cursor?.x" :cy="cursor?.y" r="4" class="fill-brand-600 stroke-(--surface)" stroke-width="2" />
                    </g>
                </svg>
            </div>
        @else
            <div class="mt-3 flex h-40 items-center justify-center rounded-lg border border-dashed border-slate-200 px-6 text-center text-xs text-slate-500">
                Grafik muncul setelah ada baris selesai di lebih dari satu hari.
            </div>
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
