<div class="w-full max-w-sm">
    <span class="mb-8 flex size-11 items-center justify-center rounded-xl bg-brand-600 text-white shadow-sm lg:hidden">
        <x-icon name="chart" class="size-5" />
    </span>

    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Masuk sebagai admin</h1>
    <p class="mt-1.5 text-sm text-slate-500">Login hanya diperlukan untuk mengubah data. Semua orang tetap bisa melihat progress tanpa login.</p>

    <form wire:submit="login" class="mt-8 space-y-5">
        <div>
            <label class="label" for="email">Username atau email</label>
            <input wire:model="email" id="email" type="text" class="input h-10" placeholder="admin" autocomplete="username" autocapitalize="none" autofocus>
            @error('email') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div x-data="{ visible: false }">
            <label class="label" for="password">Password</label>
            <div class="relative">
                <input wire:model="password" id="password" :type="visible ? 'text' : 'password'" type="password" class="input h-10 pr-10" autocomplete="current-password">
                <button type="button" x-on:click="visible = ! visible" class="absolute top-1/2 right-2 -translate-y-1/2 rounded-md p-1 text-slate-400 hover:text-slate-700" :title="visible ? 'Sembunyikan password' : 'Tampilkan password'">
                    <x-icon name="eye" class="size-4" />
                </button>
            </div>
            @error('password') <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input wire:model="remember" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            Ingat saya di perangkat ini
        </label>

        <button type="submit" class="btn-primary h-10 w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Masuk</span>
            <span wire:loading wire:target="login">Memproses…</span>
        </button>
    </form>

    <div class="mt-8 border-t border-slate-200 pt-6">
        <a href="{{ route('dashboard') }}" wire:navigate class="link inline-flex items-center gap-1.5 text-sm">
            <x-icon name="arrow-left" class="size-4" /> Lihat data tanpa login
        </a>
    </div>
</div>
