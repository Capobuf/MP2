<div class="mp2-context-selector" role="group" aria-label="Contesto Globale">
    <x-filament.tenant-menu />
    @unless (request()->routeIs(
        'filament.admin.resources.exercises.view',
        'filament.admin.resources.proposals.view',
        'filament.admin.resources.proposals.pdf',
        'filament.admin.resources.budgets.view',
    ))
        <livewire:exercise-context-selector />
    @endunless
</div>
