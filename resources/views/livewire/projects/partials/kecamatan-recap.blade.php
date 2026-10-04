@if ($kecamatanRecap->isNotEmpty())
    @php
        $recapStatusSet = $project->config()->statuses();
        $recapStatuses = $recapStatusSet->all()->reject(fn (array $status) => $status['done']);
    @endphp

    <div class="card overflow-x-auto">
        <div class="card-header">
            <x-icon name="map" class="size-4 text-slate-400" />
            <h3 class="text-sm font-semibold text-slate-900">Rekap per Kecamatan</h3>
            <span class="text-xs text-slate-400">dari kolom "{{ $project->config()->recapColumn() }}"</span>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="table-head">
                    <th class="px-4 py-2.5">Kecamatan</th>
                    <th class="px-4 py-2.5 text-right">Total</th>
                    <th class="px-4 py-2.5 text-right">Selesai</th>
                    @foreach ($recapStatuses as $status)
                        <th class="px-4 py-2.5 text-right">{{ $status['label'] }}</th>
                    @endforeach
                    <th class="w-48 px-4 py-2.5">Progress</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($kecamatanRecap as $name => $item)
                    <tr wire:key="recap-{{ $name }}" class="hover:bg-slate-50/60">
                        <td class="px-4 py-2.5 font-medium text-slate-900">{{ $name }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ $item['total'] }}</td>
                        <td class="px-4 py-2.5 text-right font-medium tabular-nums text-emerald-700">{{ $item['done'] }}</td>
                        @foreach ($recapStatuses as $code => $status)
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ $item['statuses'][$code] ?? '–' }}</td>
                        @endforeach
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <x-progress :value="$item['percent']" size="sm" />
                                <span class="w-9 text-right text-xs font-medium tabular-nums text-slate-700">{{ $item['percent'] }}%</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
