@props([
    'icon',
    'iconKind',
    'indexLabel',
    'indexUrl',
    'title',
])

<header {{ $attributes->class(['mp2-object-header']) }}>
    <div class="mp2-object-header-artwork" data-object-icon="{{ $iconKind }}" aria-hidden="true">
        <x-filament::icon :icon="$icon" />
    </div>

    <nav class="mp2-object-breadcrumbs" aria-label="Percorso">
        <a href="{{ $indexUrl }}">{{ $indexLabel }}</a>
        <x-filament::icon icon="heroicon-m-chevron-right" aria-hidden="true" />
        <span aria-current="page">{{ $title }}</span>
    </nav>

    <div class="mp2-object-header-main">
        <div class="mp2-object-identity">
            <h1>{{ $title }}</h1>
            <div class="mp2-object-meta">
                {{ $meta }}
            </div>
        </div>

        {{ $actions }}
    </div>

    {{ $slot }}
</header>
