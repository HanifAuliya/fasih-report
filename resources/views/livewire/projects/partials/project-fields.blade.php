<div class="space-y-4">
    <div>
        <label class="label">Nama Data</label>
        <input wire:model="name" type="text" class="input" placeholder="cth. FASIH Auto Ganti Wilayah OSS" autofocus>
        @error('name') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">Deskripsi</label>
        <textarea wire:model="description" rows="3" class="input" placeholder="Keterangan singkat (opsional)"></textarea>
        @error('description') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">Warna</label>
        <div class="flex flex-wrap gap-2">
            @foreach (\App\Models\Project::COLORS as $c)
                <label class="cursor-pointer">
                    <input type="radio" wire:model="color" value="{{ $c }}" class="peer sr-only">
                    <span @class([
                        'block size-8 rounded-full ring-offset-2 transition peer-checked:ring-2 peer-checked:ring-slate-900',
                        'bg-brand-500' => $c === 'indigo',
                        'bg-emerald-500' => $c === 'emerald',
                        'bg-sky-500' => $c === 'sky',
                        'bg-amber-500' => $c === 'amber',
                        'bg-rose-500' => $c === 'rose',
                        'bg-violet-500' => $c === 'violet',
                        'bg-teal-500' => $c === 'teal',
                        'bg-slate-500' => $c === 'slate',
                    ])></span>
                </label>
            @endforeach
        </div>
    </div>
</div>
