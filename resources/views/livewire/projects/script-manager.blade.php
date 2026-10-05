<div class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
    {{-- Daftar script --}}
    <aside class="space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">Script <span class="font-normal text-slate-400">{{ $scripts->count() }}</span></h2>
            @can('manage')
                <button type="button" wire:click="create" class="btn-primary py-1.5 text-xs">
                    <x-icon name="plus" class="size-3.5" /> Script baru
                </button>
            @endcan
        </div>

        <div class="space-y-2">
            @forelse ($scripts as $script)
                @php $active = $selected?->is($script); @endphp
                <button type="button" wire:key="s-{{ $script->id }}" wire:click="select({{ $script->id }})" @class([
                    'group w-full rounded-xl border p-3.5 text-left transition',
                    'border-brand-300 bg-brand-50/70 ring-2 ring-brand-500/15' => $active,
                    'border-slate-200 bg-white hover:border-slate-300 hover:shadow-sm' => ! $active,
                ])>
                    <div class="flex items-start gap-3">
                        <span @class([
                            'flex size-9 shrink-0 items-center justify-center rounded-xl',
                            'bg-slate-900 text-slate-50' => $script->isFromGithub(),
                            'bg-brand-100 text-brand-700' => ! $script->isFromGithub(),
                        ])>
                            @if ($script->isFromGithub())
                                <x-github-icon class="size-4.5" />
                            @else
                                <x-icon name="code" class="size-4.5" />
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $script->name }}</p>
                            <p class="truncate font-mono text-[11px] text-slate-500">{{ $script->filename }}</p>
                            <div class="mt-2 flex items-center gap-1.5">
                                <span class="badge bg-slate-100 font-mono text-slate-600">v{{ $script->version }}</span>
                                @if ($script->sync_error)
                                    <span class="badge bg-rose-50 text-rose-700">gagal sinkron</span>
                                @elseif ($script->isFromGithub())
                                    <span class="badge bg-emerald-50 text-emerald-700">
                                        <span class="size-1.5 rounded-full bg-emerald-500"></span> GitHub
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </button>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                    Belum ada script.
                </div>
            @endforelse
        </div>
    </aside>

    {{-- Detail script --}}
    <div class="min-w-0 space-y-5">
        @if ($selected)
            <div class="card overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-4 p-5">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="truncate text-xl font-semibold tracking-tight text-slate-900">{{ $selected->name }}</h2>
                            <span class="badge bg-brand-50 font-mono text-brand-700">v{{ $selected->version }}</span>
                            <span class="badge bg-slate-100 text-slate-600">{{ \App\Models\Script::LANGUAGES[$selected->language] ?? $selected->language }}</span>
                        </div>
                        <p class="mt-1 font-mono text-xs text-slate-500">{{ $selected->filename }} · diperbarui {{ $selected->updated_at->diffForHumans() }}</p>
                        @if ($selected->notes)
                            <p class="mt-2 max-w-3xl text-sm whitespace-pre-line text-slate-600">{{ $selected->notes }}</p>
                        @endif
                    </div>
                    @can('manage')
                        <div class="flex flex-wrap gap-2">
                            @if ($selected->isFromGithub())
                                <button type="button" wire:click="syncNow({{ $selected->id }})" wire:loading.attr="disabled" wire:target="syncNow" class="btn-primary">
                                    <x-icon name="history" class="size-4" wire:loading.class="animate-spin" wire:target="syncNow" />
                                    <span wire:loading.remove wire:target="syncNow">Sinkron sekarang</span>
                                    <span wire:loading wire:target="syncNow">Mengambil…</span>
                                </button>
                            @endif
                            <button type="button" wire:click="edit({{ $selected->id }})" class="btn-secondary">
                                <x-icon name="pencil" class="size-4" /> {{ $selected->isFromGithub() ? 'Pengaturan' : 'Edit / Update' }}
                            </button>
                            <button type="button" x-on:click="$confirm({{ \Illuminate\Support\Js::from('Hapus script '.($selected->name).' beserta riwayatnya?') }}, () => $wire.delete({{ $selected->id }}), { danger: true, confirm: 'Ya, hapus' })" class="btn-icon size-9 hover:text-rose-600" title="Hapus">
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </div>
                    @endcan
                </div>

                {{-- Sumber GitHub --}}
                @if ($selected->isFromGithub())
                    <div @class([
                        'flex flex-wrap items-center gap-x-6 gap-y-2 border-t px-5 py-3 text-xs',
                        'border-rose-100 bg-rose-50/60' => $selected->sync_error,
                        'border-slate-100 bg-slate-50/70' => ! $selected->sync_error,
                    ])>
                        <a href="{{ $selected->githubUrl() }}" target="_blank" rel="noopener" class="inline-flex min-w-0 items-center gap-1.5 font-medium text-slate-700 hover:text-brand-600">
                            <x-github-icon class="size-4 shrink-0" />
                            <span class="truncate">{{ $selected->github_repo }}</span>
                            <span class="text-slate-400">/</span>
                            <span class="truncate font-mono font-normal">{{ $selected->github_path }}</span>
                            <span class="badge bg-slate-200/70 font-mono text-slate-600">{{ $selected->github_branch }}</span>
                        </a>
                        @if ($selected->github_commit)
                            <a href="{{ $selected->githubCommitUrl() }}" target="_blank" rel="noopener" class="inline-flex min-w-0 items-center gap-1.5 text-slate-500 hover:text-brand-600">
                                <span class="font-mono text-slate-400">{{ substr($selected->github_commit, 0, 7) }}</span>
                                <span class="max-w-md truncate">{{ $selected->github_commit_message }}</span>
                            </a>
                        @endif
                        <span class="ml-auto inline-flex items-center gap-1.5 text-slate-500">
                            @if ($selected->sync_error)
                                <span class="size-2 rounded-full bg-rose-500"></span>
                                <span class="text-rose-700">{{ $selected->sync_error }}</span>
                            @else
                                <span class="size-2 rounded-full bg-emerald-500"></span>
                                Sinkron {{ $selected->synced_at?->diffForHumans() ?? 'belum pernah' }}
                            @endif
                        </span>
                    </div>
                @endif

                {{-- URL install / raw --}}
                @if ($selected->is_public)
                    <div class="flex flex-col gap-2 border-t border-slate-100 px-5 py-3 sm:flex-row sm:items-center">
                        <div class="flex min-w-0 flex-1 items-center gap-2">
                            <span class="badge shrink-0 bg-emerald-50 text-emerald-700">{{ $selected->isUserscript() ? 'Install & auto-update' : 'URL raw' }}</span>
                            <code class="truncate font-mono text-xs text-slate-600">{{ $selected->rawUrl() }}</code>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button type="button" x-data="copyButton" @click="copy(@js($selected->rawUrl()))" class="btn-secondary py-1.5 text-xs">
                                <x-icon name="copy" class="size-3.5" /> <span x-text="copied ? 'Tersalin' : 'Copy URL'"></span>
                            </button>
                            <a href="{{ $selected->rawUrl() }}" target="_blank" class="btn-secondary py-1.5 text-xs">
                                <x-icon name="{{ $selected->isUserscript() ? 'download' : 'eye' }}" class="size-3.5" />
                                {{ $selected->isUserscript() ? 'Install' : 'Raw' }}
                            </a>
                        </div>
                    </div>
                @endif
            </div>

            @if ($selected->isUserscript() && $selected->is_public)
                <p class="-mt-2 px-1 text-xs text-slate-500">
                    Pasang lewat tombol <b>Install</b>: <code class="font-mono">@updateURL</code> otomatis diarahkan ke web ini, jadi Tampermonkey ikut update setiap versi naik{{ $selected->isFromGithub() ? ' (setiap kamu push ke GitHub)' : '' }}.
                </p>
            @endif

            {{-- Kode --}}
            @php($preview = \App\Models\Script::preview($selected->code))
            <div class="code-surface overflow-hidden rounded-xl shadow-sm ring-1 ring-slate-200"
                x-data="codeBlock({ truncated: @js($preview['truncated']), load: () => $wire.fullCode({{ $selected->id }}) })"
                wire:key="code-{{ $selected->id }}-{{ $selected->updated_at->timestamp }}">
                <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-2.5">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="size-2.5 rounded-full bg-rose-400/80"></span>
                        <span class="size-2.5 rounded-full bg-amber-400/80"></span>
                        <span class="size-2.5 rounded-full bg-emerald-400/80"></span>
                        <span class="ml-2 truncate font-mono text-xs text-white/50">{{ $selected->filename }}</span>
                        @if ($selected->isFromGithub())
                            <span class="hidden rounded-md bg-white/10 px-1.5 py-0.5 text-[10px] text-white/60 sm:inline">read-only · edit di GitHub</span>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-[11px] text-white/40">{{ number_format($preview['lines'], 0, ',', '.') }} baris</span>
                        <button type="button" @click="copy()" :disabled="loading" :class="copied ? 'bg-emerald-500 text-white' : 'bg-white/10 text-white/80 hover:bg-white/20'"
                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition">
                            <x-icon name="copy" class="size-3.5" />
                            <span x-text="copied ? 'Tersalin!' : 'Copy Kode'"></span>
                        </button>
                    </div>
                </div>
                @if (filled($selected->code))
                    <pre wire:ignore class="scroll-thin max-h-[calc(100vh-18rem)] min-h-64 overflow-auto p-4 text-[13px] leading-relaxed"><code x-ref="code" class="language-{{ $selected->language }} font-mono">{{ $preview['text'] }}</code></pre>
                    @if ($preview['truncated'])
                        <div x-show="truncated" class="flex flex-wrap items-center justify-between gap-2 border-t border-white/10 px-4 py-2.5 text-xs text-white/50">
                            <span>Menampilkan 300 dari {{ number_format($preview['lines'], 0, ',', '.') }} baris supaya halaman tetap ringan. Copy Kode tetap menyalin semuanya.</span>
                            <button type="button" @click="showAll()" :disabled="loading" class="rounded-lg bg-white/10 px-3 py-1.5 font-medium text-white/80 hover:bg-white/20">
                                <span x-text="loading ? 'Memuat…' : 'Tampilkan semua'"></span>
                            </button>
                        </div>
                    @endif
                @else
                    <p class="px-4 py-16 text-center text-sm text-white/50" x-ref="code">Kode belum diambil dari GitHub. Klik “Sinkron sekarang”.</p>
                @endif
            </div>

            {{-- Riwayat versi --}}
            <div class="card">
                <div class="card-header">
                    <x-icon name="history" class="size-4 text-slate-400" />
                    <h3 class="text-sm font-semibold text-slate-900">Riwayat Versi</h3>
                    <span class="text-xs text-slate-400">{{ $selected->versions_count }} versi sebelumnya</span>
                </div>
                <ol class="relative px-5 py-4">
                    <span class="absolute top-6 bottom-6 left-[1.6rem] w-px bg-slate-200"></span>
                    <li class="relative flex items-center gap-3 py-2">
                        <span class="relative z-10 size-2.5 rounded-full bg-emerald-500 ring-4 ring-emerald-500/15"></span>
                        <span class="badge bg-emerald-50 font-mono text-emerald-700">v{{ $selected->version }}</span>
                        <span class="truncate text-sm text-slate-700">Versi saat ini{{ $selected->github_commit_message ? ' · '.$selected->github_commit_message : '' }}</span>
                        <span class="ml-auto shrink-0 text-xs text-slate-400">{{ $selected->updated_at->translatedFormat('d M Y H:i') }}</span>
                    </li>
                    @forelse ($versions as $ver)
                        <li wire:key="v-{{ $ver->id }}" class="relative flex items-center gap-3 py-2">
                            <span class="relative z-10 size-2.5 rounded-full bg-slate-300"></span>
                            <span class="badge bg-slate-100 font-mono text-slate-600">v{{ $ver->version }}</span>
                            <span class="truncate text-sm text-slate-500">{{ $ver->notes ?: 'Tanpa catatan' }}</span>
                            <span class="ml-auto shrink-0 text-xs text-slate-400">{{ $ver->created_at->translatedFormat('d M Y H:i') }}</span>
                            <button type="button" wire:click="viewVersion({{ $ver->id }})" class="btn-icon" title="Lihat">
                                <x-icon name="eye" class="size-4" />
                            </button>
                        </li>
                    @empty
                        <li class="relative py-2 pl-6 text-sm text-slate-400">Belum ada versi sebelumnya.</li>
                    @endforelse
                </ol>
            </div>
        @else
            <div class="card flex flex-col items-center p-12 text-center">
                <span class="flex size-12 items-center justify-center rounded-xl bg-slate-100 text-slate-400">
                    <x-icon name="code" class="size-6" />
                </span>
                <p class="mt-4 font-medium text-slate-900">Belum ada script</p>
                <p class="mt-1 max-w-md text-sm text-slate-500">Tambahkan script, mis. <span class="font-mono">fasih-ganti-wilayah-oss.user.js</span>. Bisa ditempel langsung atau dihubungkan ke file di GitHub supaya selalu update.</p>
            </div>
        @endif
    </div>

    {{-- Form script --}}
    @can('manage')
        <x-modal wire:model="showForm" :title="$editingId ? 'Pengaturan Script' : 'Script Baru'" max-width="4xl">
            <form wire:submit="save">
                <div class="space-y-5 p-5">
                    {{-- Sumber kode --}}
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="cursor-pointer rounded-xl border border-slate-200 p-4 transition has-checked:border-brand-500 has-checked:bg-brand-50/60 has-checked:ring-1 has-checked:ring-brand-500">
                            <input type="radio" wire:model.live="source" value="github" class="sr-only">
                            <span class="flex items-center gap-2 text-sm font-medium text-slate-900"><x-github-icon class="size-4" /> Ambil dari GitHub</span>
                            <span class="mt-1 block text-xs text-slate-500">Kode selalu mengikuti file di repo. Push ke GitHub → web &amp; Tampermonkey ikut update.</span>
                        </label>
                        <label class="cursor-pointer rounded-xl border border-slate-200 p-4 transition has-checked:border-brand-500 has-checked:bg-brand-50/60 has-checked:ring-1 has-checked:ring-brand-500">
                            <input type="radio" wire:model.live="source" value="manual" class="sr-only">
                            <span class="flex items-center gap-2 text-sm font-medium text-slate-900"><x-icon name="code" class="size-4" /> Tulis / tempel kode</span>
                            <span class="mt-1 block text-xs text-slate-500">Kode disimpan di web, diupdate manual lewat form ini.</span>
                        </label>
                    </div>

                    @if ($source === 'github')
                        <div>
                            <label class="label">Link file di GitHub</label>
                            <input wire:model="githubUrl" type="url" class="input font-mono text-xs" placeholder="https://github.com/akun/repo/blob/main/fasih-ganti-wilayah-oss.user.js">
                            <p class="mt-1 text-[11px] text-slate-400">Buka file-nya di GitHub lalu salin link dari address bar. Link raw.githubusercontent.com juga bisa. Repo privat butuh <code>GITHUB_TOKEN</code> di .env.</p>
                            @error('githubUrl') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Nama</label>
                            <input wire:model="name" type="text" class="input" placeholder="{{ $source === 'github' ? 'Kosongkan = dari nama file' : 'FASIH Ganti Wilayah OSS' }}">
                            @error('name') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Nama File</label>
                            <input wire:model="filename" type="text" class="input font-mono" placeholder="{{ $source === 'github' ? 'Kosongkan = sama dengan di GitHub' : 'fasih-ganti-wilayah-oss.user.js' }}">
                            @error('filename') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Bahasa</label>
                            <select wire:model="language" class="input">
                                @foreach (\App\Models\Script::LANGUAGES as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($source === 'manual')
                            <div>
                                <label class="label">Versi</label>
                                <input wire:model="version" type="text" class="input font-mono" placeholder="1.0.0">
                                <p class="mt-1 text-[11px] text-slate-400">Otomatis dibaca dari <code>// @version</code> jika ada.</p>
                                @error('version') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>

                    @if ($source === 'manual')
                        <div x-data="{
                            load(e) {
                                const file = e.target.files[0];
                                if (!file) return;
                                const reader = new FileReader();
                                reader.onload = () => {
                                    $wire.set('code', reader.result, false);
                                    if (!$wire.filename) $wire.set('filename', file.name, false);
                                    $refs.editor.value = reader.result;
                                };
                                reader.readAsText(file);
                            }
                        }">
                            <div class="mb-1.5 flex items-center justify-between">
                                <label class="label mb-0">Kode</label>
                                <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-brand-600 hover:text-brand-500">
                                    <x-icon name="upload" class="size-3.5" /> Ambil dari file
                                    <input type="file" accept=".js,.php,.py,.sql,.json,.sh,.txt" class="sr-only" @change="load">
                                </label>
                            </div>
                            <textarea x-ref="editor" wire:model="code" rows="14" spellcheck="false"
                                class="input font-mono text-xs leading-relaxed" placeholder="// ==UserScript==&#10;// @name ...&#10;// ==/UserScript=="
                                @keydown.tab.prevent="const s = $el.selectionStart; $el.setRangeText('    ', s, $el.selectionEnd, 'end');"></textarea>
                            @error('code') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>

                        @if ($editingId)
                            <div>
                                <label class="label">Catatan perubahan</label>
                                <input wire:model="changeNote" type="text" class="input" placeholder="cth. perbaiki pilih kecamatan">
                            </div>
                        @endif
                    @endif

                    <div>
                        <label class="label">Keterangan</label>
                        <textarea wire:model="notes" rows="2" class="input" placeholder="Cara pakai, catatan, dll (opsional)"></textarea>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" wire:model="isPublic" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        URL install publik (bisa diakses tanpa login, dibutuhkan untuk auto-update Tampermonkey)
                    </label>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" class="btn-secondary" @click="show = false">Batal</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ $source === 'github' ? 'Simpan & ambil dari GitHub' : 'Simpan' }}</span>
                        <span wire:loading wire:target="save">Menyimpan…</span>
                    </button>
                </div>
            </form>
        </x-modal>
    @endcan

    {{-- Lihat versi lama --}}
    <x-modal wire:model="showVersion" :title="$viewingVersion ? 'Versi v'.$viewingVersion->version : 'Versi'" max-width="4xl">
        @if ($viewingVersion)
            <div class="space-y-3 p-5">
                <p class="text-sm text-slate-500">{{ $viewingVersion->notes ?: 'Tanpa catatan' }} · {{ $viewingVersion->created_at->translatedFormat('d M Y H:i') }}</p>
                @php($versionPreview = \App\Models\Script::preview($viewingVersion->code))
                <div class="code-surface overflow-hidden rounded-xl"
                    x-data="codeBlock({ truncated: @js($versionPreview['truncated']), load: () => $wire.fullVersionCode({{ $viewingVersion->id }}) })"
                    wire:key="ver-code-{{ $viewingVersion->id }}">
                    <div class="flex justify-end border-b border-white/10 px-3 py-2">
                        <button type="button" @click="copy()" class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-white/80 hover:bg-white/20">
                            <x-icon name="copy" class="size-3.5" /> <span x-text="copied ? 'Tersalin!' : 'Copy'"></span>
                        </button>
                    </div>
                    <pre wire:ignore class="scroll-thin max-h-[26rem] overflow-auto p-4 text-xs leading-relaxed"><code x-ref="code" class="language-{{ $viewingVersion->script->language }} font-mono">{{ $versionPreview['text'] }}</code></pre>
                    @if ($versionPreview['truncated'])
                        <p x-show="truncated" class="border-t border-white/10 px-4 py-2 text-xs text-white/50">
                            300 dari {{ number_format($versionPreview['lines'], 0, ',', '.') }} baris ditampilkan ·
                            <button type="button" @click="showAll()" class="underline hover:text-white/80" x-text="loading ? 'Memuat…' : 'Tampilkan semua'"></button>
                        </p>
                    @endif
                </div>
                @if ($viewingVersion->script->isFromGithub())
                    <p class="text-xs text-amber-700">Script ini terhubung ke GitHub: restore akan tertimpa lagi saat sinkron berikutnya. Untuk kembali permanen, revert commit di GitHub.</p>
                @endif
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                <button type="button" class="btn-secondary" @click="show = false">Tutup</button>
                @can('manage')
                    <button type="button" x-on:click="$confirm({{ \Illuminate\Support\Js::from('Kembalikan script ke versi ini?') }}, () => $wire.restoreVersion({{ $viewingVersion->id }}), {})" class="btn-primary">
                        <x-icon name="history" class="size-4" /> Restore versi ini
                    </button>
                @endcan
            </div>
        @endif
    </x-modal>
</div>
