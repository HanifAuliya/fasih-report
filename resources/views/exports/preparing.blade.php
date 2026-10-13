<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Menyiapkan file · {{ config('app.name') }}</title>

    <x-theme-script />
    @fonts

    @vite(['resources/css/app.css'])
</head>

<body class="flex min-h-screen items-center justify-center bg-(--page) p-4 font-sans text-[14px] text-slate-800 antialiased">
    <div class="card w-full max-w-md p-6 text-center">
        @if ($failed)
            <p class="text-sm font-semibold text-rose-600">File gagal disiapkan</p>
            <p class="mt-1 text-xs text-slate-500">{{ $failed }}</p>
            <p class="mt-3 text-xs text-slate-500">Halaman ini mencoba lagi otomatis.</p>
        @else
            <svg class="mx-auto size-8 animate-spin text-brand-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
            <p class="mt-4 text-sm font-semibold text-slate-900">Menyiapkan file {{ $project->name }}…</p>
            <p class="mt-1 text-xs text-slate-500">Datanya besar, jadi file dibuat di server dulu. Unduhan mulai otomatis begitu siap (biasanya kurang dari satu menit).</p>
        @endif
        <a href="{{ route('projects.show', $project) }}" class="btn-secondary btn-sm mt-5 inline-flex">Kembali ke pekerjaan</a>
    </div>
</body>

</html>
