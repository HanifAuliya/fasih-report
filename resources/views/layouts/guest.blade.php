<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · {{ config('app.name') }}</title>

    <x-theme-script />
    @fonts

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen bg-(--page) font-sans text-slate-800 antialiased">
    <div class="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        {{-- Panel identitas --}}
        <aside class="relative hidden overflow-hidden bg-brand-600 p-12 text-white lg:flex lg:flex-col">

            <div class="relative flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/25">
                    <x-icon name="chart" class="size-5" />
                </span>
                <span class="text-lg font-semibold tracking-tight">FASIH Report</span>
            </div>

            <div class="relative mt-auto max-w-md">
                <h2 class="text-3xl leading-tight font-semibold tracking-tight">Pantau tindak lanjut pekerjaan yang belum selesai, di satu tempat.</h2>
                <ul class="mt-8 space-y-4 text-sm text-white/85">
                    @foreach (['Data target per kecamatan & bagian tampil seperti Excel', 'Laporan JSON dari script langsung memperbarui status', 'Script selalu sinkron dengan GitHub'] as $point)
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-white/20"><x-icon name="check" class="size-3" /></span>
                            {{ $point }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <p class="relative mt-12 text-xs text-white/60">Pelacak tindak lanjut pekerjaan FASIH</p>
        </aside>

        {{-- Form --}}
        <main class="relative flex items-center justify-center px-4 py-12 sm:px-8">
            <x-theme-toggle compact class="absolute top-5 right-5" />
            {{ $slot }}
        </main>
    </div>

    @livewireScriptConfig
</body>

</html>
