{{--
    Isian "Dikerjakan oleh" satu unit (nama / komputer). Nama yang pernah dipakai di pekerjaan ini muncul
    sebagai pilihan. $action = nama method Livewire penerima nilai baru.
--}}
@props(['unit', 'action', 'names' => []])

@php $listId = 'worked-by-names-'.$unit->id; @endphp

@can('manage')
    <div {{ $attributes->merge(['class' => 'flex min-w-0 items-center']) }}>
        <input type="text" value="{{ $unit->worked_by }}" list="{{ $listId }}" maxlength="60" placeholder="— belum ada —"
            x-on:change="$wire.{{ $action }}({{ $unit->id }}, $event.target.value)" x-on:keydown.enter.prevent="$event.target.blur()"
            title="Siapa / komputer mana yang mengerjakan. Kosongkan bila sudah tidak dikerjakan."
            class="w-36 min-w-0 rounded-lg border-transparent bg-transparent px-2 py-1 text-sm hover:border-slate-200 focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-500/20 focus:outline-none dark:focus:bg-slate-900">
        <datalist id="{{ $listId }}">
            @foreach ($names as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>
    </div>
@else
    <span {{ $attributes->merge(['class' => 'text-sm text-slate-700']) }}>{{ $unit->worked_by ?: '–' }}</span>
@endcan
