@php
    $selectedLabel = $options[(string) $value] ?? '';
@endphp

<x-filament::dropdown placement="bottom-start" width="none" class="mp2-closing-select" :flip="false" :offset="4">
    <x-slot name="trigger">
        <button
            type="button"
            class="mp2-closing-select-trigger"
            @disabled($disabled ?? false)
        >
            <span>{{ $selectedLabel }}</span>
            <x-filament::icon icon="heroicon-m-chevron-down" />
        </button>
    </x-slot>

    <x-filament::dropdown.list role="listbox">
        @foreach ($options as $optionValue => $optionLabel)
            @php
                $isSelected = (string) $optionValue === (string) $value;
            @endphp
            <x-filament::dropdown.list.item
                x-on:click="close(); $wire.set('{{ $model }}', '{{ $optionValue }}')"
                :icon="$isSelected ? 'heroicon-m-check' : null"
                :color="$isSelected ? 'primary' : 'gray'"
                :disabled="$disabled ?? false"
                role="option"
                aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                @class(['fi-selected' => $isSelected])
            >
                {{ $optionLabel }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
