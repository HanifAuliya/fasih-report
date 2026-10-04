@props(['project', 'size' => 'md'])

@php
    $colors = [
        'indigo' => 'bg-brand-100 text-brand-700',
        'emerald' => 'bg-emerald-100 text-emerald-700',
        'sky' => 'bg-sky-100 text-sky-700',
        'amber' => 'bg-amber-100 text-amber-700',
        'rose' => 'bg-rose-100 text-rose-700',
        'violet' => 'bg-violet-100 text-violet-700',
        'teal' => 'bg-teal-100 text-teal-700',
        'slate' => 'bg-slate-200 text-slate-700',
    ];
    $initials = collect(preg_split('/\s+/', trim($project->name)))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
    $dimension = $size === 'sm' ? 'size-6 text-[10px] rounded-md' : 'size-9 text-xs rounded-lg';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center font-semibold uppercase {$dimension} ".($colors[$project->color] ?? $colors['indigo'])]) }}>
    {{ $initials }}
</span>
