@php
    $user = auth()->user();
    $isPlatformPanel = \Filament\Facades\Filament::getCurrentPanel()?->getId() === 'platform';
    $adminPanel = \Filament\Facades\Filament::getPanel('admin');
    $platformPanel = \Filament\Facades\Filament::getPanel('platform');
    $canSwitchPanel = $user instanceof \App\Models\User
        && $user->canAccessPanel($platformPanel)
        && (! $isPlatformPanel || $user->canAccessPanel($adminPanel));
    $panelSwitchLabel = $isPlatformPanel ? 'Vai alle Aziende' : 'Piattaforma';
    $panelSwitchUrl = $isPlatformPanel ? route('filament.admin.tenant') : $platformPanel->getUrl();
    $panelSwitchIcon = $isPlatformPanel ? 'heroicon-m-building-office-2' : 'heroicon-m-wrench-screwdriver';
@endphp

@if ($canSwitchPanel)
    <div class="mp2-sidebar-platform">
        <a
            class="mp2-sidebar-platform-link"
            href="{{ $panelSwitchUrl }}"
            aria-label="{{ $panelSwitchLabel }}"
        >
            <x-filament::icon :icon="$panelSwitchIcon" class="mp2-sidebar-platform-icon" />
            <span x-cloak x-show="$store.sidebar.isOpen">{{ $panelSwitchLabel }}</span>
        </a>
    </div>
@endif

<div @class(['mp2-sidebar-collapse', 'mp2-sidebar-collapse-after-platform' => $canSwitchPanel])>
    <button
        type="button"
        class="mp2-sidebar-collapse-button"
        aria-controls="fi-main-sidebar"
        x-bind:aria-expanded="$store.sidebar.isOpen"
        x-bind:aria-label="$store.sidebar.isOpen ? 'Riduci Menu' : 'Espandi Menu'"
        x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
    >
        <x-filament::icon
            icon="heroicon-m-chevron-left"
            class="mp2-sidebar-collapse-icon"
            x-cloak
            x-show="$store.sidebar.isOpen"
        />
        <x-filament::icon
            icon="heroicon-m-chevron-right"
            class="mp2-sidebar-collapse-icon"
            x-cloak
            x-show="! $store.sidebar.isOpen"
        />
        <span x-cloak x-show="$store.sidebar.isOpen">Riduci Menu</span>
    </button>
</div>
