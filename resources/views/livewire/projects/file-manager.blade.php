@php
    $extStyle = fn (string $ext) => match ($ext) {
        'xlsx', 'xls', 'csv' => 'bg-emerald-50 text-emerald-700',
        'json' => 'bg-amber-50 text-amber-700',
        'js' => 'bg-yellow-50 text-yellow-700',
        'pdf' => 'bg-rose-50 text-rose-700',
        default => 'bg-slate-100 text-slate-600',
    };
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <div class="relative w-full sm:w-64">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" class="input pl-9" placeholder="Cari nama file…">
        </div>
        <select wire:model.live="category" class="input w-auto">
            <option value="">Semua kategori</option>
            @foreach (\App\Models\ReportFile::CATEGORIES as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="kecamatanFilter" class="input w-auto">
            <option value="">Semua kecamatan</option>
            <option value="none">— Tanpa kecamatan —</option>
            @foreach ($kecamatans as $kec)
                <option value="{{ $kec->id }}">{{ $kec->kode }} {{ $kec->nama }}</option>
            @endforeach
        </select>
        @can('manage')
            <button type="button" wire:click="openUpload" class="btn-primary sm:ml-auto">
                <x-icon name="upload" class="size-4" /> Upload File
            </button>
        @endcan
    </div>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="table-head">
                    <th class="px-4 py-3">File</th>
                    <th class="px-4 py-3">Kategori</th>
                    <th class="px-4 py-3">Kecamatan</th>
                    <th class="px-4 py-3 text-right">Ukuran</th>
                    <th class="px-4 py-3">Diupload</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($files as $file)
                    <tr wire:key="f-{{ $file->id }}" class="hover:bg-slate-50/60">
                        <td class="px-4 py-3">
                            <button type="button" wire:click="preview({{ $file->id }})" class="flex items-center gap-3 text-left">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg text-[10px] font-bold uppercase {{ $extStyle($file->extension) }}">
                                    {{ $file->extension }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block max-w-xs truncate font-medium text-slate-900 hover:text-brand-600">{{ $file->original_name }}</span>
                                    @if ($file->notes)
                                        <span class="block max-w-xs truncate text-xs text-slate-500">{{ $file->notes }}</span>
                                    @endif
                                    @if ($file->summary)
                                        <span @class(['mt-0.5 block max-w-md text-xs', 'text-rose-600' => str_starts_with($file->summary, 'Gagal'), 'text-emerald-700' => ! str_starts_with($file->summary, 'Gagal')]) title="{{ $file->summary }}">
                                            {{ \Illuminate\Support\Str::limit($file->summary, 110) }}
                                        </span>
                                    @endif
                                </span>
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <span class="badge bg-slate-100 text-slate-600">{{ \App\Models\ReportFile::CATEGORIES[$file->category] ?? $file->category }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            @if ($file->kecamatan)
                                <span class="font-mono text-xs text-slate-400">{{ $file->kecamatan->kode }}</span> {{ $file->kecamatan->nama }}
                            @else
                                <span class="text-slate-400">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-xs text-slate-500 tabular-nums">{{ $file->humanSize() }}</td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap text-slate-500" title="{{ $file->created_at->translatedFormat('d M Y H:i') }}">{{ $file->created_at->diffForHumans() }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end">
                                <button type="button" wire:click="preview({{ $file->id }})" class="btn-secondary btn-sm" title="Lihat isi file">
                                    <x-icon name="eye" class="size-3.5" /> Lihat
                                </button>
                                <a href="{{ route('files.download', $file) }}" class="btn-secondary btn-sm" title="Download file">
                                    <x-icon name="download" class="size-3.5" /> Download
                                </a>
                                @if (in_array($file->category, ['target', 'report']) && in_array($file->extension, ['xlsx', 'json', 'csv']))
                                    @can('manage')
                                        <button type="button" x-on:click="$confirm({{ \Illuminate\Support\Js::from('Proses ulang file ini ke data target?') }}, () => $wire.reprocess({{ $file->id }}), {})" class="btn-icon" title="Proses ulang">
                                            <x-icon name="history" class="size-4" />
                                        </button>
                                    @endcan
                                @endif
                                @can('manage')
                                    <button type="button" wire:click="edit({{ $file->id }})" class="btn-icon" title="Edit">
                                        <x-icon name="pencil" class="size-4" />
                                    </button>
                                @endcan
                                @can('manage')
                                    <button type="button" x-on:click="$confirm({{ \Illuminate\Support\Js::from('Hapus file '.($file->original_name).'?') }}, () => $wire.delete({{ $file->id }}), { danger: true, confirm: 'Ya, hapus' })" class="btn-icon hover:text-rose-600" title="Hapus">
                                        <x-icon name="trash" class="size-4" />
                                    </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-14 text-center">
                            <x-icon name="document" class="mx-auto size-8 text-slate-300" />
                            <p class="mt-3 text-sm text-slate-500">Belum ada file{{ $search || $category || $kecamatanFilter ? ' yang cocok' : '' }}.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Upload --}}
    @can('manage')
        <x-modal wire:model="showUpload" title="Upload File" max-width="xl">
            <form wire:submit="saveUploads">
                <div class="space-y-4 p-5">
                    <label x-data="{ drag: false }" @dragover.prevent="drag = true" @dragleave.prevent="drag = false" @drop="drag = false"
                        :class="drag ? 'border-brand-400 bg-brand-50' : 'border-slate-300 hover:border-slate-400'"
                        class="relative flex cursor-pointer flex-col items-center rounded-xl border-2 border-dashed px-6 py-8 text-center transition">
                        <x-icon name="upload" class="size-7 text-slate-400" />
                        <p class="mt-2 text-sm font-medium text-slate-700">Klik atau seret file ke sini</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ strtoupper(implode(', ', \App\Models\ReportFile::ALLOWED_EXTENSIONS)) }} · maks {{ round(config('fasih.max_upload_kb') / 1024) }} MB per file</p>
                        <p class="mt-2 text-xs text-slate-400">Excel target → tabel kecamatan terisi · Laporan JSON/CSV → status baris ter-update</p>
                        <input type="file" wire:model="uploads" multiple class="absolute inset-0 cursor-pointer opacity-0">
                    </label>

                    <x-upload-status upload="uploads" process="saveUploads" process-label="Menyimpan & memproses file…" />
                    @error('uploads') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    @error('uploads.*') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

                    @if ($uploads)
                        <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200">
                            @foreach ($uploads as $i => $upload)
                                <li wire:key="up-{{ $i }}-{{ $upload->getFilename() }}" class="flex items-center gap-3 px-3 py-2 text-sm">
                                    <x-icon name="document" class="size-4 shrink-0 text-slate-400" />
                                    <span class="min-w-0 flex-1 truncate">{{ $upload->getClientOriginalName() }}</span>
                                    <span class="text-xs text-slate-400">{{ number_format($upload->getSize() / 1024, 0) }} KB</span>
                                    <button type="button" wire:click="removeUpload({{ $i }})" class="btn-icon size-6">
                                        <x-icon name="x" class="size-3.5" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Kategori</label>
                            <select wire:model="uploadCategory" class="input">
                                <option value="auto">✨ Otomatis (target / laporan)</option>
                                @foreach (\App\Models\ReportFile::CATEGORIES as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label">Kecamatan</label>
                            <select wire:model="uploadKecamatan" class="input">
                                <option value="auto">✨ Deteksi dari nama file</option>
                                <option value="">— Tanpa kecamatan —</option>
                                @foreach ($kecamatans as $kec)
                                    <option value="{{ $kec->id }}">{{ $kec->kode }} {{ $kec->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="label">Catatan</label>
                        <input wire:model="uploadNotes" type="text" class="input" placeholder="Opsional">
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" class="btn-secondary" @click="show = false">Batal</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="uploads,saveUploads">
                        <x-icon name="upload" class="size-4" /> Simpan {{ $uploads ? count($uploads).' file' : '' }}
                    </button>
                </div>
            </form>
        </x-modal>
    @endcan

    {{-- Edit metadata --}}
    @can('manage')
        <x-modal wire:model="showEdit" title="Edit File" max-width="md">
            <form wire:submit="update">
                <div class="space-y-4 p-5">
                    <div>
                        <label class="label">Kategori</label>
                        <select wire:model="editCategory" class="input">
                            @foreach (\App\Models\ReportFile::CATEGORIES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Kecamatan</label>
                        <select wire:model="editKecamatan" class="input">
                            <option value="">— Tanpa kecamatan —</option>
                            @foreach ($kecamatans as $kec)
                                <option value="{{ $kec->id }}">{{ $kec->kode }} {{ $kec->nama }}</option>
                            @endforeach
                        </select>
                        @error('editKecamatan') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Catatan</label>
                        <textarea wire:model="editNotes" rows="2" class="input"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" class="btn-secondary" @click="show = false">Batal</button>
                    <button type="submit" class="btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endcan

    {{-- Preview --}}
    <x-modal wire:model="showPreview" :title="$previewFile?->original_name" max-width="6xl">
        @if ($previewFile)
            <div wire:key="preview-{{ $previewFile->id }}" x-data="filePreview(@js(route('files.view', $previewFile)), @js($previewFile->extension))" class="p-5">
                <template x-if="loading">
                    <div class="py-16 text-center text-sm text-slate-500">Memuat file…</div>
                </template>
                <template x-if="error">
                    <div class="rounded-xl bg-rose-50 p-4 text-sm text-rose-700" x-text="error"></div>
                </template>

                {{-- Excel --}}
                <div x-show="kind === 'sheet'" x-cloak>
                    <div class="mb-3 flex gap-1 overflow-x-auto" x-show="sheets.length > 1">
                        <template x-for="(name, i) in sheets" :key="name">
                            <button type="button" @click="renderSheet(i)" x-text="name"
                                :class="active === i ? 'chip-active' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                class="rounded-lg px-3 py-1.5 text-xs font-medium whitespace-nowrap"></button>
                        </template>
                    </div>
                    <div class="sheet-preview max-h-[65vh] overflow-auto rounded-xl border border-slate-200" x-html="html"></div>
                </div>

                {{-- JSON / teks --}}
                <div x-show="kind === 'text'" x-cloak class="overflow-hidden code-surface rounded-xl">
                    <div class="flex justify-end border-b border-white/10 px-3 py-2">
                        <button type="button" @click="copyText()" class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-white/80 hover:bg-white/20">
                            <x-icon name="copy" class="size-3.5" /> Copy
                        </button>
                    </div>
                    <pre class="max-h-[65vh] overflow-auto p-4 text-xs leading-relaxed"><code x-ref="text" class="hljs font-mono"></code></pre>
                </div>

                @unless (in_array($previewFile->extension, ['xlsx', 'xls', 'csv', 'json', 'txt', 'js']))
                    <p class="py-10 text-center text-sm text-slate-500">Preview tidak tersedia untuk file .{{ $previewFile->extension }}.</p>
                @endunless
            </div>
            <div class="flex items-center justify-between gap-2 border-t border-slate-100 px-5 py-4">
                <p class="text-xs text-slate-500">
                    {{ $previewFile->humanSize() }} · {{ $previewFile->kecamatan?->nama ?? 'Tanpa kecamatan' }} · {{ $previewFile->created_at->translatedFormat('d M Y H:i') }}
                </p>
                <a href="{{ route('files.download', $previewFile) }}" class="btn-primary">
                    <x-icon name="download" class="size-4" /> Download
                </a>
            </div>
        @endif
    </x-modal>
</div>
