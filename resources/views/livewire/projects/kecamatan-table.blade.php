@php
    $config = $project->config();
    $statuses = $config->statuses();
    $unitLabel = $config->unitLabel();
    $isOss = $config->unitSource() === \App\Support\ProjectSettings::UNIT_KECAMATAN;
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <span class="text-[13px] text-slate-500">Tampilkan</span>
            <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5">
                @foreach (['' => 'Semua'] + \App\Models\Kecamatan::STATUSES as $key => $label)
                    <button type="button" wire:click="$set('filter', '{{ $key }}')" @class([
                        'inline-flex h-7 items-center gap-1.5 rounded-md px-3 text-[13px] font-medium transition',
                        'bg-(--surface) text-slate-900 shadow-(--shadow-xs) ring-1 ring-slate-200' => $filter === $key,
                        'text-slate-500 hover:text-slate-900' => $filter !== $key,
                    ])>
                        {{ $label }}
                        <span class="text-[11px] text-slate-400 tabular-nums">{{ $key === '' ? $counts->sum() : $counts[$key] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if ($changedRowsCount > 0)
                <a href="{{ route('projects.changes.export', $project) }}" class="btn-secondary" title="Semua baris dari seluruh {{ strtolower($unitLabel) }} yang statusnya diubah oleh laporan JSON">
                    <x-icon name="download" class="size-4" /> Rekap perubahan JSON
                    <span class="text-[11px] text-slate-400 tabular-nums">{{ number_format($changedRowsCount, 0, ',', '.') }}</span>
                </a>
            @endif
            @if ($totalTarget > 0)
                <span class="text-sm text-slate-500">Realisasi <span class="font-semibold text-slate-900">{{ number_format($totalRealisasi, 0, ',', '.') }}</span> / {{ number_format($totalTarget, 0, ',', '.') }}</span>
            @endif
            @can('manage')
                <button type="button" wire:click="create" class="btn-secondary">
                    <x-icon name="plus" class="size-4" /> {{ $unitLabel }}
                </button>
            @endcan
            @can('manage')
                <button type="button" wire:click="openUpload" class="btn-primary">
                    <x-icon name="upload" class="size-4" /> Upload Excel
                </button>
            @endcan
        </div>
    </div>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="table-head">
                    <th class="px-4 py-3">Kode</th>
                    <th class="px-4 py-3">{{ $unitLabel }}</th>
                    <th class="px-4 py-3 text-right">Target</th>
                    <th class="px-4 py-3 text-right">Realisasi</th>
                    <th class="w-48 px-4 py-3">Progress</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-center">File</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($kecamatans as $kec)
                    @php $pct = $kec->percent(); @endphp
                    <tr wire:key="kec-{{ $kec->id }}-{{ $kec->updated_at?->timestamp }}" class="group hover:bg-slate-50/60">
                        <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $kec->kode }}</td>
                        <td class="px-4 py-3">
                            @if ($kec->tracked_rows_count)
                                <a href="{{ route('projects.kecamatan', [$project, $kec->kode]) }}" wire:navigate class="link">{{ $kec->nama }}</a>
                            @else
                                <p class="font-medium text-slate-900">{{ $kec->nama }}</p>
                            @endif
                            @if ($kec->tracked_rows_count)
                                @if ($activeReport = $kec->reports->first())
                                    <p class="mt-0.5 flex max-w-xs items-center gap-1 text-xs text-emerald-700" title="Laporan aktif: {{ $activeReport->original_name }}">
                                        <x-icon name="check" class="size-3 shrink-0" />
                                        <span class="truncate">{{ $activeReport->original_name }}</span>
                                        <span class="shrink-0 text-slate-400">· {{ $activeReport->created_at->diffForHumans() }}</span>
                                    </p>
                                @else
                                    <p class="mt-0.5 text-xs text-slate-400">Belum ada laporan JSON</p>
                                @endif
                            @endif
                            @if ($kec->catatan)
                                <p class="mt-0.5 line-clamp-1 max-w-xs text-xs text-slate-500" title="{{ $kec->catatan }}">{{ $kec->catatan }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @can('manage')
                                <input type="number" min="0" value="{{ $kec->target }}" @disabled($kec->tracked_rows_count) title="{{ $kec->tracked_rows_count ? 'Dihitung otomatis dari data Excel' : '' }}"
                                    wire:change="updateField({{ $kec->id }}, 'target', $event.target.value)"
                                    class="w-20 rounded-lg border-transparent bg-transparent px-2 py-1 text-right text-sm tabular-nums hover:border-slate-200 disabled:cursor-default disabled:hover:border-transparent focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-500/20 focus:outline-none">
                            @else
                                <span class="px-2 tabular-nums">{{ number_format($kec->target, 0, ',', '.') }}</span>
                            @endcan
                        </td>
                        <td class="px-4 py-3 text-right">
                            @can('manage')
                                <input type="number" min="0" value="{{ $kec->realisasi }}" @disabled($kec->tracked_rows_count) title="{{ $kec->tracked_rows_count ? 'Dihitung otomatis dari data Excel' : '' }}"
                                    wire:change="updateField({{ $kec->id }}, 'realisasi', $event.target.value)"
                                    class="w-20 rounded-lg border-transparent bg-transparent px-2 py-1 text-right text-sm tabular-nums hover:border-slate-200 disabled:cursor-default disabled:hover:border-transparent focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-500/20 focus:outline-none">
                            @else
                                <span class="px-2 tabular-nums">{{ number_format($kec->realisasi, 0, ',', '.') }}</span>
                            @endcan
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <x-progress :value="$pct" size="sm" />
                                <span class="w-9 text-right text-xs font-medium tabular-nums text-slate-700">{{ $pct }}%</span>
                            </div>
                            @if ($counts = $statusCounts->get($kec->id))
                                <div class="mt-1.5 flex flex-wrap gap-x-2 text-[11px] text-slate-500">
                                    @foreach ($statuses->all() as $code => $status)
                                        @if ($counts->get($code))
                                            <span>{{ $status['label'] }} <b class="font-medium text-slate-700">{{ $counts->get($code) }}</b></span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="relative inline-flex items-center">
                                @can('manage')
                                    <span class="inline-flex items-center gap-0.5 rounded-full ring-1 ring-slate-200 transition hover:ring-slate-400">
                                        <x-status-badge :status="$kec->status" />
                                        <x-icon name="chevron-right" class="mr-1.5 size-3 rotate-90 text-slate-400" />
                                    </span>
                                @else
                                    <x-status-badge :status="$kec->status" />
                                @endcan
                                @can('manage')
                                    <select wire:change="updateField({{ $kec->id }}, 'status', $event.target.value)" class="absolute inset-0 opacity-0" title="Klik untuk mengubah status">
                                        @foreach (\App\Models\Kecamatan::STATUSES as $key => $label)
                                            <option value="{{ $key }}" @selected($kec->status === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endcan
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center text-xs text-slate-500">{{ $kec->files_count ?: '–' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                @if ($kec->tracked_rows_count)
                                    <a href="{{ route('projects.kecamatan', [$project, $kec->kode]) }}" wire:navigate class="btn-secondary btn-sm">
                                        Buka data <x-icon name="chevron-right" class="size-3.5" />
                                    </a>
                                @endif
                                @can('manage')
                                    <button type="button" wire:click="edit({{ $kec->id }})" class="btn-icon" title="Edit">
                                        <x-icon name="pencil" class="size-4" />
                                    </button>
                                @endcan
                                @can('manage')
                                    <button type="button" x-on:click="$confirm({{ \Illuminate\Support\Js::from('Hapus kecamatan '.($kec->nama).'?') }}, () => $wire.delete({{ $kec->id }}), { danger: true, confirm: 'Ya, hapus' })" class="btn-icon hover:text-rose-600" title="Hapus">
                                        <x-icon name="trash" class="size-4" />
                                    </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">Belum ada {{ strtolower($unitLabel) }}.{{ $isOss ? '' : ' Upload file Excel "Bagian 01 …" untuk mulai.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('livewire.projects.partials.kecamatan-recap')

    <p class="text-xs text-slate-400">Tips: upload Excel per {{ strtolower($unitLabel) }} di sini, lalu klik nama {{ strtolower($unitLabel) }} untuk melihat datanya &amp; upload laporan JSON khusus {{ strtolower($unitLabel) }} itu.</p>

    @can('manage')
        <x-modal wire:model="showUpload" title="Upload Excel" max-width="xl">
            <form wire:submit="saveUploads">
                <div class="space-y-4 p-5">
                    <div class="grid gap-3 text-xs sm:grid-cols-2">
                        <div class="rounded-xl bg-emerald-50 p-3 text-emerald-800 sm:col-span-2">
                            <p class="font-semibold">Excel {{ $isOss ? 'target' : 'Bagian' }} (.xlsx)</p>
                            <p class="mt-0.5 opacity-80">mis. <span class="font-mono">{{ $isOss ? 'target_OSS_010_HARUYAN.xlsx' : 'Bagian 01 (85 baris, ...).xlsx' }}</span>, mengisi data {{ strtolower($unitLabel) }}. Upload ulang aman, status lama dipertahankan.</p>
                        </div>
                        <div class="rounded-xl bg-brand-50 p-3 text-brand-800 sm:col-span-2">
                            <p class="font-semibold">Laporan JSON / CSV?</p>
                            <p class="mt-0.5 opacity-80">Buka {{ strtolower($unitLabel) }}-nya (klik nama di tabel), lalu upload di bagian <b>Laporan</b>. Jadi laporan pasti masuk ke {{ strtolower($unitLabel) }} yang benar.</p>
                        </div>
                    </div>

                    <label x-data="{ drag: false }" @dragover.prevent="drag = true" @dragleave.prevent="drag = false" @drop="drag = false"
                        :class="drag ? 'border-brand-400 bg-brand-50' : 'border-slate-300 hover:border-slate-400'"
                        class="relative flex cursor-pointer flex-col items-center rounded-xl border-2 border-dashed px-6 py-8 text-center transition">
                        <x-icon name="upload" class="size-7 text-slate-400" />
                        <p class="mt-2 text-sm font-medium text-slate-700">Klik atau seret file ke sini</p>
                        <p class="mt-0.5 text-xs text-slate-500">Bisa banyak file sekaligus</p>
                        <input type="file" wire:model="uploads" multiple accept=".xlsx" class="absolute inset-0 cursor-pointer opacity-0">
                    </label>

                    <div wire:loading wire:target="uploads" class="text-xs text-brand-600">Mengunggah…</div>
                    <div wire:loading wire:target="saveUploads" class="text-xs text-brand-600">Memproses data…</div>
                    @error('uploads') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    @error('uploads.*') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

                    @if ($uploads)
                        <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200">
                            @foreach ($uploads as $i => $upload)
                                <li wire:key="kup-{{ $i }}-{{ $upload->getFilename() }}" class="flex items-center gap-3 px-3 py-2 text-sm">
                                    <x-icon name="document" class="size-4 shrink-0 text-slate-400" />
                                    <span class="min-w-0 flex-1 truncate">{{ $upload->getClientOriginalName() }}</span>
                                    <span class="text-xs text-slate-400">{{ number_format($upload->getSize() / 1024, 0) }} KB</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($uploadResults)
                        <div class="space-y-2">
                            <p class="text-sm font-medium text-slate-900">Hasil</p>
                            @foreach ($uploadResults as $result)
                                @php $failed = ! $result['summary'] || str_starts_with($result['summary'], 'Gagal'); @endphp
                                <div @class(['rounded-xl px-3 py-2 text-xs', 'bg-rose-50 text-rose-700' => $failed, 'bg-emerald-50 text-emerald-800' => ! $failed])>
                                    <p class="font-medium">{{ $result['name'] }}</p>
                                    <p class="mt-0.5">{{ $result['summary'] ?? 'Disimpan sebagai file biasa (tidak diproses).' }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" class="btn-secondary" @click="show = false">Tutup</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="uploads,saveUploads">
                        <x-icon name="upload" class="size-4" /> Proses {{ $uploads ? count($uploads).' file' : '' }}
                    </button>
                </div>
            </form>
        </x-modal>
    @endcan

    @can('manage')
        <x-modal wire:model="showForm" :title="($editingId ? 'Edit ' : 'Tambah ').$unitLabel">
            <form wire:submit="save">
                <div class="grid grid-cols-3 gap-4 p-5">
                    <div>
                        <label class="label">Kode</label>
                        <input wire:model="kode" type="text" class="input font-mono" placeholder="010">
                        @error('kode') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="col-span-2">
                        <label class="label">Nama {{ $unitLabel }}</label>
                        <input wire:model="nama" type="text" class="input uppercase" placeholder="HARUYAN">
                        @error('nama') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Target</label>
                        <input wire:model="target" type="number" min="0" class="input">
                        @error('target') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Realisasi</label>
                        <input wire:model="realisasi" type="number" min="0" class="input">
                        @error('realisasi') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Status</label>
                        <select wire:model="status" class="input">
                            @foreach (\App\Models\Kecamatan::STATUSES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-3">
                        <label class="label">Catatan</label>
                        <textarea wire:model="catatan" rows="2" class="input" placeholder="Opsional"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" class="btn-secondary" @click="show = false">Batal</button>
                    <button type="submit" class="btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endcan
</div>
