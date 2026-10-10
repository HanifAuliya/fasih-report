{{-- Kartu File induk: Excel utuh pekerjaan; baris unit (Bagian) tersambung lewat kolom kunci yang sama --}}
<div @if ($polling) wire:poll.4s @endif>
    @if ($visible)
        <div class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                        <x-icon name="table" class="size-4.5" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-slate-900">File induk</h3>
                        @if ($file)
                            <p class="mt-0.5 truncate text-xs text-slate-500" title="{{ $file->original_name }}">
                                {{ $file->original_name }} · {{ $file->created_at->translatedFormat('d M Y H:i') }}
                            </p>
                        @else
                            <p class="mt-0.5 max-w-xl text-xs text-slate-500">
                                Upload Excel utuh (bisa puluhan ribu baris). File per {{ strtolower($project->config()->unitLabel()) }} tersambung otomatis lewat kolom kunci
                                <code class="font-mono">{{ $project->config()->keyColumn() ?? '—' }}</code>, lalu statusnya bisa diunduh di file induk.
                            </p>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if (($export['status'] ?? null) === 'ready')
                        <a href="{{ route('projects.master.export', $project) }}" class="btn-secondary" title="Disiapkan {{ \Illuminate\Support\Carbon::parse($export['at'])->translatedFormat('d M Y H:i') }}">
                            <x-icon name="download" class="size-4" /> Excel induk + status
                        </a>
                    @endif

                    @can('manage')
                        @if ($stats)
                            <button type="button" wire:click="prepareExport" class="btn-secondary" @disabled(($export['status'] ?? null) === 'running')>
                                <x-icon name="history" class="size-4" />
                                {{ ($export['status'] ?? null) === 'ready' ? 'Siapkan ulang' : 'Siapkan Excel induk' }}
                            </button>
                        @endif

                        <label class="btn-primary cursor-pointer">
                            <x-icon name="upload" class="size-4" /> {{ $file ? 'Ganti file induk' : 'Upload file induk' }}
                            <input type="file" accept=".xlsx" wire:model="masterUpload" class="sr-only">
                        </label>
                    @endcan
                </div>
            </div>

            <x-upload-status upload="masterUpload" process="uploadMaster" process-label="Menyimpan file induk…" class="mt-3" />
            @if ($masterUpload)
                <div class="mt-3 flex flex-wrap items-center gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                    <span class="truncate">{{ $masterUpload->getClientOriginalName() }}</span>
                    <button type="button" wire:click="uploadMaster" class="btn-primary btn-sm" wire:loading.attr="disabled" wire:target="uploadMaster">Simpan sebagai file induk</button>
                    <button type="button" wire:click="$set('masterUpload', null)" class="btn-ghost btn-sm">Batal</button>
                </div>
            @endif
            @error('masterUpload') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror

            @if ($processing)
                <p class="mt-4 flex items-center gap-2 text-sm text-slate-600">
                    <span class="size-2 animate-pulse rounded-full bg-brand-600"></span> Membaca file induk… (bisa sampai 1 menit untuk file besar)
                </p>
            @elseif ($failed)
                <p class="mt-4 text-sm text-rose-600">{{ $file->summary }}</p>
            @elseif ($stats)
                @php
                    $tasks = max(1, $stats['tasks']);
                    $donePct = (int) floor($stats['done'] / $tasks * 100);
                @endphp
                <div class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
                    <div>
                        <p class="text-xs text-slate-500">Total baris</p>
                        <p class="text-lg font-semibold text-slate-900 tabular-nums">{{ number_format($stats['rows'], 0, ',', '.') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Perlu dikerjakan</p>
                        <p class="text-lg font-semibold text-slate-900 tabular-nums">{{ number_format($stats['tasks'], 0, ',', '.') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Sudah ada di {{ strtolower($project->config()->unitLabel()) }}</p>
                        <p class="text-lg font-semibold text-slate-900 tabular-nums">
                            {{ number_format($stats['linked'], 0, ',', '.') }}
                            <span class="text-xs font-normal text-slate-400">{{ (int) floor($stats['linked'] / $tasks * 100) }}%</span>
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Selesai</p>
                        <p class="text-lg font-semibold text-emerald-600 tabular-nums">
                            {{ number_format($stats['done'], 0, ',', '.') }}
                            <span class="text-xs font-normal text-slate-400">{{ $donePct }}%</span>
                        </p>
                    </div>
                </div>
                <x-progress :value="$donePct" size="sm" class="mt-3" />
                @if ($stats['linked'] < $stats['tasks'])
                    <p class="mt-2 text-xs text-slate-500">
                        {{ number_format($stats['tasks'] - $stats['linked'], 0, ',', '.') }} baris yang perlu dikerjakan belum ada di file {{ strtolower($project->config()->unitLabel()) }} mana pun.
                    </p>
                @endif
            @endif

            @if (($export['status'] ?? null) === 'running')
                <p class="mt-3 flex items-center gap-2 text-xs text-slate-500">
                    <span class="size-2 animate-pulse rounded-full bg-brand-600"></span> Menyiapkan Excel induk + status…
                </p>
            @elseif (($export['status'] ?? null) === 'failed')
                <p class="mt-3 text-xs text-rose-600">Gagal menyiapkan Excel induk: {{ $export['message'] ?? '' }}</p>
            @endif

            @can('manage')
                @if ($file)
                    <div class="mt-4 border-t border-slate-100 pt-3 text-right">
                        <button type="button" x-on:click="$confirm({{ \Illuminate\Support\Js::from('Hapus file induk? File per '.strtolower($project->config()->unitLabel()).' tidak ikut terhapus.') }}, () => $wire.deleteMaster(), { danger: true, confirm: 'Ya, hapus' })"
                            class="text-xs text-slate-400 hover:text-rose-600">Hapus file induk</button>
                    </div>
                @endif
            @endcan
        </div>
    @endif
</div>
