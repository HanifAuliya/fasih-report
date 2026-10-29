{{--
    Isian "Dikerjakan oleh" satu unit. Nama terakhir yang diketik di browser ini diingat (localStorage),
    jadi unit berikutnya cukup klik tombol "Saya (…)". $action = nama method Livewire penerima nilai baru.
--}}
@props(['unit', 'action', 'names' => []])

@php $listId = 'worked-by-names-'.$unit->id; @endphp

@can('manage')
    <div x-data="{
            mine: (() => { try { return localStorage.getItem('worked-by-name') || '' } catch (e) { return '' } })(),
            save(value) {
                value = value.trim();
                if (value) { try { localStorage.setItem('worked-by-name', value) } catch (e) {} this.mine = value; }
                $wire.{{ $action }}({{ $unit->id }}, value);
            },
        }" {{ $attributes->merge(['class' => 'flex min-w-0 items-center gap-1.5']) }}>
        <input type="text" value="{{ $unit->worked_by }}" list="{{ $listId }}" maxlength="60" placeholder="— belum ada —"
            x-on:change="save($event.target.value)" x-on:keydown.enter.prevent="$event.target.blur()"
            title="Siapa / komputer mana yang mengerjakan. Kosongkan bila sudah tidak dikerjakan."
            class="w-36 min-w-0 rounded-lg border-transparent bg-transparent px-2 py-1 text-sm hover:border-slate-200 focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-500/20 focus:outline-none dark:focus:bg-slate-900">
        <datalist id="{{ $listId }}">
            @foreach ($names as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>
        @unless ($unit->worked_by)
            <button type="button" x-show="mine" x-cloak x-on:click="save(mine)" class="btn-ghost btn-sm shrink-0 whitespace-nowrap text-xs" title="Tandai dikerjakan oleh nama yang biasa kamu pakai">
                Saya (<span x-text="mine"></span>)
            </button>
        @endunless
    </div>
@else
    <span {{ $attributes->merge(['class' => 'text-sm text-slate-700']) }}>{{ $unit->worked_by ?: '–' }}</span>
@endcan
