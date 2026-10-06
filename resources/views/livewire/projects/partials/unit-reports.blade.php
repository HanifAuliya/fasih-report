@php
    $activeReport = $reports->firstWhere('is_active', true);
    $unitName = $kecamatan->nama;
@endphp

{{-- Ringkas satu baris; riwayat dibuka bila perlu supaya tabel data tetap luas --}}
<div class="card overflow-hidden" x-data="{ history: false }">
    <div class="flex flex-wrap items-center gap-3 px-4 py-3">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
            <x-icon name="document" class="size-4" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[13px] font-semibold text-slate-900">Laporan JSON</p>
            <p class="truncate text-xs text-slate-500">
                @if ($activeReport)
                    Aktif: <span class="font-medium text-emerald-700">{{ $activeReport->original_name }}</span>
                    · {{ $activeReport->created_at->translatedFormat('d M Y H:i') }}
                @else
                    Belum ada laporan aktif, status masih dari Excel awal.
                @endif
            </p>
        </div>
        @if ($reports->isNotEmpty())
            <button type="button" x-on:click="history = ! history" class="btn-secondary btn-sm" :aria-expanded="history">
                <x-icon name="history" class="size-3.5" /> Riwayat ({{ $reports->count() }})
                <span class="transition-transform" :class="history && 'rotate-180'"><x-icon name="chevron-right" class="size-3 rotate-90" /></span>
            </button>
        @endif
        <a href="{{ route('projects.kecamatan.json', [$project, $kecamatan->kode]) }}" class="btn-secondary btn-sm"
            title="Status semua baris saat ini (laporan + perubahan manual di web), format sama seperti laporan script">
            <x-icon name="download" class="size-3.5" /> JSON terkini
        </a>
        @can('manage')
            <button type="button" wire:click="openReportUpload" class="btn-primary btn-sm">
                <x-icon name="upload" class="size-3.5" /> Upload JSON
            </button>
        @endcan
    </div>

    @if ($reports->isNotEmpty())
        <ul class="divide-y divide-slate-100 border-t border-slate-100" x-show="history" x-collapse x-cloak>
            @foreach ($reports as $report)
                @php $failed = str_starts_with((string) $report->summary, 'Gagal'); @endphp
                <li wire:key="report-{{ $report->id }}" @class(['flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3', 'bg-emerald-50/50' => $report->is_active])>
                    <span @class([
                        'flex size-9 shrink-0 items-center justify-center rounded-lg text-[10px] font-bold uppercase',
                        'bg-emerald-100 text-emerald-700' => $report->is_active,
                        'bg-rose-50 text-rose-600' => $failed,
                        'bg-slate-100 text-slate-500' => ! $report->is_active && ! $failed,
                    ])>{{ $report->extension }}</span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate text-sm font-medium text-slate-900">{{ $report->original_name }}</span>
                            @if ($report->is_active)
                                <span class="badge bg-emerald-600 text-white">Aktif</span>
                            @elseif ($failed)
                                <span class="badge bg-rose-50 text-rose-700 ring-1 ring-rose-600/20">Gagal</span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ $report->created_at->translatedFormat('d M Y H:i') }}
                            @if ($report->uploader) · {{ $report->uploader->name }} @endif
                            · {{ $report->humanSize() }}
                        </p>
                        @if ($report->summary)
                            <p @class(['mt-0.5 text-xs', 'text-rose-600' => $failed, 'text-slate-600' => ! $failed])>{{ $report->summary }}</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-1">
                        <a href="{{ route('files.download', $report) }}" class="btn-secondary py-1.5 text-xs" title="Download laporan">
                            <x-icon name="download" class="size-3.5" /> Download
                        </a>
                        @can('manage')
                            @unless ($report->is_active)
                                <button type="button" x-on:click="$confirm({{ \Illuminate\Support\Js::from('Jadikan '.($report->original_name).' sebagai laporan aktif? Status '.($unitName).' akan dihitung ulang dari laporan ini (perubahan manual akan hilang).') }}, () => $wire.activateReport({{ $report->id }}), {})"
                                    class="btn-secondary py-1.5 text-xs">
                                    <x-icon name="check" class="size-3.5" /> Jadikan aktif
                                </button>
                            @endunless
                            <button type="button" x-on:click="$confirm({{ \Illuminate\Support\Js::from('Hapus '.($report->original_name).'?'.($report->is_active ? ' Laporan sebelumnya akan jadi aktif.' : '')) }}, () => $wire.deleteReport({{ $report->id }}), { danger: true, confirm: 'Ya, hapus' })"
                                class="btn-icon hover:text-rose-600" title="Hapus laporan">
                                <x-icon name="trash" class="size-4" />
                            </button>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>

@can('manage')
    <x-modal wire:model="showReportUpload" :title="'Upload laporan '.$unitName" max-width="lg">
        <form wire:submit="uploadReport">
            <div class="space-y-4 p-5">
                <p class="text-sm text-slate-600">
                    Laporan hanya diterapkan ke baris <b>{{ $unitName }}</b> dan langsung jadi laporan aktif.
                    Jika tidak ada baris yang cocok, laporan ditolak dan laporan aktif sebelumnya tetap dipakai.
                </p>

                <label class="relative flex cursor-pointer flex-col items-center rounded-xl border-2 border-dashed border-slate-300 px-6 py-8 text-center transition hover:border-slate-400">
                    <x-icon name="upload" class="size-7 text-slate-400" />
                    @if ($reportUpload)
                        <p class="mt-2 text-sm font-medium text-slate-800">{{ $reportUpload->getClientOriginalName() }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ number_format($reportUpload->getSize() / 1024, 0) }} KB · klik untuk ganti</p>
                    @else
                        <p class="mt-2 text-sm font-medium text-slate-700">Pilih file laporan</p>
                        <p class="mt-0.5 text-xs text-slate-500">.json atau .csv dari script</p>
                    @endif
                    <input type="file" wire:model="reportUpload" accept=".json,.csv" class="absolute inset-0 cursor-pointer opacity-0">
                </label>

                <div wire:loading wire:target="reportUpload" class="text-xs text-brand-600">Mengunggah…</div>
                <div wire:loading wire:target="uploadReport" class="text-xs text-brand-600">Menerapkan laporan…</div>
                @error('reportUpload') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                <button type="button" class="btn-secondary" @click="show = false">Batal</button>
                <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="reportUpload,uploadReport">
                    <x-icon name="upload" class="size-4" /> Terapkan
                </button>
            </div>
        </form>
    </x-modal>
@endcan
