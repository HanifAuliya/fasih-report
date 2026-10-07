@php
    $colorDots = collect(\App\Support\StatusSet::COLORS)->map(fn ($color) => $color['dot']);
@endphp

<div class="space-y-6">
    <datalist id="header-options">
        @foreach ($headers as $header)
            <option value="{{ $header }}"></option>
        @endforeach
    </datalist>

    {{-- Preset --}}
    <div class="card p-5">
        <h3 class="text-sm font-semibold text-slate-900">Mulai dari preset</h3>
        <p class="mt-1 text-sm text-slate-500">Pilih yang paling mirip dengan kasusmu, lalu sesuaikan di bawah. Belum tersimpan sampai klik Simpan.</p>
        <div class="mt-4 grid gap-3 md:grid-cols-3">
            @foreach ($presets as $preset)
                <button type="button" wire:click="applyPreset('{{ $preset->value }}')" class="rounded-xl border border-slate-200 p-3 text-left transition hover:border-brand-300 hover:bg-brand-50/40">
                    <span class="block text-sm font-medium text-slate-900">{{ $preset->label() }}</span>
                    <span class="mt-0.5 block text-xs text-slate-500">{{ $preset->description() }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Unit & kunci --}}
        <div class="card grid gap-5 p-5 md:grid-cols-2">
            <div class="md:col-span-2">
                <h3 class="text-sm font-semibold text-slate-900">Excel &amp; pencocokan baris</h3>
                @if ($headers->isEmpty())
                    <p class="mt-1 text-xs text-amber-700">Upload satu Excel dulu supaya nama-nama kolomnya muncul sebagai saran.</p>
                @else
                    <p class="mt-1 text-xs text-slate-500">Saran nama kolom diambil dari Excel yang sudah diupload ({{ $headers->count() }} kolom).</p>
                @endif
            </div>

            <div>
                <label class="label">Sebutan unit</label>
                <input wire:model="unitLabel" type="text" class="input" placeholder="Kecamatan / Bagian / File">
                @error('unitLabel') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label">Unit dari mana?</label>
                <select wire:model="unitSource" class="input">
                    <option value="{{ \App\Support\ProjectSettings::UNIT_KECAMATAN }}">Cocokkan nama file ke daftar kecamatan</option>
                    <option value="{{ \App\Support\ProjectSettings::UNIT_FILE }}">Setiap Excel = 1 unit ("Bagian 01…" / nama file)</option>
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="label">Baris dicocokkan lewat</label>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="cursor-pointer rounded-xl border border-slate-200 p-3 has-checked:border-brand-500 has-checked:bg-brand-50/60 has-checked:ring-1 has-checked:ring-brand-500">
                        <input type="radio" wire:model.live="keyMode" value="{{ \App\Support\ProjectSettings::KEY_COLUMN }}" class="sr-only">
                        <span class="block text-sm font-medium text-slate-900">Kolom kunci</span>
                        <span class="block text-xs text-slate-500">mis. assignment_id, idsbr, link. UUID di dalam link diambil otomatis.</span>
                    </label>
                    <label class="cursor-pointer rounded-xl border border-slate-200 p-3 has-checked:border-brand-500 has-checked:bg-brand-50/60 has-checked:ring-1 has-checked:ring-brand-500">
                        <input type="radio" wire:model.live="keyMode" value="{{ \App\Support\ProjectSettings::KEY_ROW }}" class="sr-only">
                        <span class="block text-sm font-medium text-slate-900">Nomor baris Excel</span>
                        <span class="block text-xs text-slate-500">Laporan berisi id "Sheet1!4" atau field baris/baris_excel.</span>
                    </label>
                </div>
            </div>

            @if ($keyMode === \App\Support\ProjectSettings::KEY_COLUMN)
                <div>
                    <label class="label">Kolom kunci di Excel</label>
                    <input wire:model="keyColumn" type="text" list="header-options" class="input font-mono" placeholder="assignment_id">
                    @error('keyColumn') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Field kunci di laporan JSON/CSV</label>
                    <input wire:model="reportKeyFields" type="text" class="input font-mono" placeholder="assignment_id, id">
                    <p class="mt-1 text-[11px] text-slate-400">Pisahkan dengan koma; yang pertama terisi dipakai.</p>
                    @error('reportKeyFields') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            @else
                <div class="md:col-span-2">
                    <label class="label">Field nomor baris di laporan (opsional)</label>
                    <input wire:model="reportKeyFields" type="text" class="input font-mono" placeholder="id, row, baris_excel">
                </div>
            @endif

            <div>
                <label class="label">Kolom rekap kecamatan (opsional)</label>
                <input wire:model="recapColumn" type="text" list="header-options" class="input font-mono" placeholder="Kecamatan / kec">
                <p class="mt-1 text-[11px] text-slate-400">Diisi → muncul tabel Rekap per Kecamatan di tab Progress.</p>
            </div>

            <div>
                <label class="label">Kolom status awal di Excel (opsional)</label>
                <input wire:model="initialStatusColumn" type="text" list="header-options" class="input font-mono" placeholder="status_awal / Catatan FASIH">
                <p class="mt-1 text-[11px] text-slate-400">Isinya dicocokkan ke alias status, mis. "dipindah" atau "KUNING: …".</p>
            </div>

            <div>
                <label class="label">Kolom penanda baris yang dikerjakan (opsional)</label>
                <input wire:model="taskColumn" type="text" list="header-options" class="input font-mono" placeholder="Edit KBLI (1=Ya)">
                <p class="mt-1 text-[11px] text-slate-400">Diisi → hanya baris yang nilainya cocok yang jadi target & dihitung progress. Baris lain tetap tampil.</p>
            </div>

            <div>
                <label class="label">Nilai yang berarti "dikerjakan"</label>
                <input wire:model="taskValues" type="text" class="input font-mono" placeholder="1, ya, true">
                <p class="mt-1 text-[11px] text-slate-400">Pisahkan dengan koma; huruf besar/kecil tidak dibedakan.</p>
                @error('taskValues') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="md:col-span-2">
                <label class="label">Kolom yang tampil di mode Ringkas</label>
                <textarea wire:model="displayColumns" rows="2" class="input font-mono text-xs" placeholder="nama_usaha, kec, desa, link"></textarea>
                <p class="mt-1 text-[11px] text-slate-400">Pisahkan dengan koma. Kosong → semua kolom ditampilkan.</p>
            </div>
        </div>

        {{-- Status --}}
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Daftar status</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Status pertama = status awal baris. Alias = teks status di laporan/Excel (pisahkan koma, tidak peka huruf besar).</p>
                </div>
                <button type="button" wire:click="addStatus" class="btn-secondary shrink-0">
                    <x-icon name="plus" class="size-4" /> Status
                </button>
            </div>
            @error('statuses') <p class="px-5 pt-3 text-xs text-rose-600">{{ $message }}</p> @enderror

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="table-head">
                            <th class="px-3 py-2.5"></th>
                            <th class="px-3 py-2.5">Kode</th>
                            <th class="px-3 py-2.5">Label</th>
                            <th class="px-3 py-2.5">Warna</th>
                            <th class="px-3 py-2.5 text-center">Selesai?</th>
                            <th class="px-3 py-2.5">Alias dari laporan</th>
                            <th class="px-3 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($statuses as $i => $status)
                            <tr wire:key="status-{{ $i }}" class="align-top">
                                <td class="px-3 py-2">
                                    <div class="flex flex-col">
                                        <button type="button" wire:click="moveStatus({{ $i }}, -1)" class="text-xs text-slate-400 hover:text-slate-900 disabled:opacity-30" @disabled($i === 0)>▲</button>
                                        <button type="button" wire:click="moveStatus({{ $i }}, 1)" class="text-xs text-slate-400 hover:text-slate-900 disabled:opacity-30" @disabled($loop->last)>▼</button>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <input wire:model="statuses.{{ $i }}.code" type="text" class="input w-28 py-1.5 font-mono text-xs">
                                    @error("statuses.$i.code") <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-2">
                                    <input wire:model="statuses.{{ $i }}.label" type="text" class="input w-36 py-1.5 text-xs">
                                    @error("statuses.$i.label") <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="size-3 shrink-0 rounded-full {{ $colorDots[$status['color']] ?? 'bg-slate-400' }}"></span>
                                        <select wire:model.live="statuses.{{ $i }}.color" class="input w-28 py-1.5 text-xs">
                                            @foreach ($colors as $color)
                                                <option value="{{ $color }}">{{ $color }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <input wire:model="statuses.{{ $i }}.done" type="checkbox" class="mt-2 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                </td>
                                <td class="px-3 py-2">
                                    <input wire:model="statuses.{{ $i }}.aliases" type="text" class="input min-w-56 py-1.5 font-mono text-xs" placeholder="selesai, hijau">
                                </td>
                                <td class="px-3 py-2">
                                    <button type="button" wire:click="removeStatus({{ $i }})" class="btn-icon hover:text-rose-600" title="Hapus status">
                                        <x-icon name="trash" class="size-4" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <p class="mr-auto text-xs text-slate-500">Setelah mengubah kolom kunci / status, klik <b>Proses ulang semua file</b> supaya data lama ikut menyesuaikan.</p>
            <button type="button" x-on:click="$confirm({{ \Illuminate\Support\Js::from('Impor ulang semua Excel lalu terapkan ulang semua laporan dengan pengaturan yang tersimpan?') }}, () => $wire.reprocessAll(), {})" class="btn-secondary" wire:loading.attr="disabled" wire:target="reprocessAll">
                <x-icon name="history" class="size-4" />
                <span wire:loading.remove wire:target="reprocessAll">Proses ulang semua file</span>
                <span wire:loading wire:target="reprocessAll">Memproses…</span>
            </button>
            <button type="submit" class="btn-primary">Simpan pengaturan</button>
        </div>
    </form>

    @if ($processLog)
        <div class="card p-5">
            <h3 class="text-sm font-semibold text-slate-900">Hasil proses ulang</h3>
            <ul class="mt-3 space-y-1.5 text-xs text-slate-600">
                @foreach ($processLog as $line)
                    <li @class(['text-rose-600' => str_contains($line, 'Gagal')])>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
