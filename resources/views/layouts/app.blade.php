<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <x-theme-script />
    @fonts

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen bg-(--page) font-sans text-[14px] text-slate-800 antialiased"
    x-data="{
        sidebar: false,
        toggleCollapsed() {
            const collapsed = ! document.documentElement.classList.contains('sidebar-collapsed');
            document.documentElement.classList.toggle('sidebar-collapsed', collapsed);
            try { localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0') } catch (e) {}
        },
    }"
    x-on:keydown.window.prevent.ctrl.b="toggleCollapsed()">

    {{-- Overlay sidebar mobile --}}
    <div x-show="sidebar" x-transition.opacity x-cloak class="fixed inset-0 z-30 bg-black/40 backdrop-blur-[2px] lg:hidden" @click="sidebar = false"></div>

    {{-- Sidebar --}}
    <aside :data-open="sidebar"
        class="fixed inset-y-0 left-0 z-40 flex w-72 flex-col overflow-hidden border-r border-slate-200 bg-(--sidebar) transition-[width,transform] duration-200 ease-out max-lg:-translate-x-full max-lg:data-[open=true]:translate-x-0 lg:w-(--sidebar-w)">

        {{-- Brand --}}
        <div class="flex h-14 shrink-0 items-center gap-2 px-3 lg:sb-collapsed:justify-center lg:sb-collapsed:px-0">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg p-1 transition hover:bg-slate-200/50 lg:sb-collapsed:flex-none">
                <span class="relative flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-brand-600 text-white shadow-sm">
                    <x-icon name="chart" class="relative size-4" />
                </span>
                <span class="min-w-0 leading-tight lg:sb-collapsed:hidden">
                    <span class="block truncate text-[13.5px] font-semibold tracking-tight text-slate-900">FASIH Report</span>
                    <span class="block truncate text-[11px] text-slate-500">Pelacak tindak lanjut</span>
                </span>
            </a>
            <button type="button" class="btn-icon lg:hidden" @click="sidebar = false" title="Tutup">
                <x-icon name="x" class="size-4" />
            </button>
        </div>


        <livewire:sidebar />

        <div class="shrink-0 border-t border-slate-200 p-2">
            <button type="button" x-on:click="toggleCollapsed()" title="Ciutkan / lebarkan sidebar (Ctrl+B)"
                class="hidden h-8 w-full items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 lg:flex lg:sb-collapsed:justify-center lg:sb-collapsed:px-0">
                <span class="shrink-0 transition-transform [.sidebar-collapsed_&]:rotate-180"><x-icon name="chevron-left" class="size-4" /></span>
                <span class="lg:sb-collapsed:hidden">Ciutkan sidebar</span>
            </button>
        </div>
    </aside>

    <div class="flex min-h-screen flex-col transition-[padding] duration-200 ease-out lg:pl-(--sidebar-w)">
        {{-- Topbar --}}
        <header class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b border-slate-200 bg-(--page)/80 px-4 backdrop-blur-xl sm:px-6 lg:px-8">
            <button type="button" class="btn-icon -ml-1 lg:hidden" @click="sidebar = true" title="Menu">
                <x-icon name="menu" class="size-5" />
            </button>
            <p class="min-w-0 truncate text-[13px] text-slate-500">
                @isset($title)
                    <span class="font-medium text-slate-900">{{ $title }}</span>
                @else
                    FASIH Report
                @endisset
            </p>

            <div class="ml-auto flex items-center gap-2">
                <x-theme-toggle compact />

                @auth
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                        <button type="button" @click="open = ! open" :aria-expanded="open"
                            class="flex items-center gap-2 rounded-full border border-slate-200 bg-(--surface) py-1 pr-2.5 pl-1 shadow-(--shadow-xs) transition hover:border-slate-300">
                            <span class="flex size-7 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">
                                {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                            </span>
                            <span class="hidden text-[13px] font-medium text-slate-700 sm:inline">{{ auth()->user()->name }}</span>
                            <span class="transition-transform" :class="open && 'rotate-180'"><x-icon name="chevron-right" class="size-3.5 rotate-90 text-slate-400" /></span>
                        </button>
                        <div x-show="open" x-transition.origin.top.right x-cloak
                            class="absolute right-0 z-50 mt-2 w-60 overflow-hidden rounded-xl border border-slate-200 bg-(--surface) shadow-(--shadow-pop)">
                            <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3.5">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">
                                    {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p>
                                </div>
                            </div>
                            <div class="px-4 py-2.5">
                                <span class="badge bg-emerald-50 text-emerald-700"><span class="size-1.5 rounded-full bg-emerald-500"></span> Admin · bisa mengubah data</span>
                            </div>
                            <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100 p-1.5">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-[13px] font-medium text-slate-700 hover:bg-rose-50 hover:text-rose-700">
                                    <x-icon name="logout" class="size-4" /> Keluar
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="btn-primary h-8 px-3 text-[13px]">
                        <x-icon name="logout" class="size-3.5 rotate-180" /> Masuk admin
                    </a>
                @endauth
            </div>
        </header>

        <main class="relative w-full flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="relative">
                {{ $slot }}
            </div>
        </main>
    </div>

    @livewireScriptConfig
</body>

</html>
