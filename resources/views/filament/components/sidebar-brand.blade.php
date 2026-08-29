<div class="mp2-sidebar-brand">
    <a
        class="mp2-sidebar-brand-link"
        href="{{ \Filament\Facades\Filament::getCurrentPanel()->getUrl(\Filament\Facades\Filament::getTenant()) }}"
        aria-label="Vai alla pagina principale"
        wire:navigate
    >
        @include('filament.components.brand-logo')
    </a>
</div>
