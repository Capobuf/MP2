<div class="mp2-sidebar-collapse">
    <button
        type="button"
        class="mp2-sidebar-collapse-button"
        aria-controls="fi-main-sidebar"
        x-bind:aria-expanded="$store.sidebar.isOpen"
        x-bind:aria-label="$store.sidebar.isOpen ? 'Riduci menu' : 'Espandi menu'"
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
        <span x-cloak x-show="$store.sidebar.isOpen">Riduci menu</span>
    </button>
</div>
