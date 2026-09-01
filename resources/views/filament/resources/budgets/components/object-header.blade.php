<x-mp2.object-header
    icon="heroicon-o-banknotes"
    icon-kind="budget"
    index-label="Budget"
    :index-url="$budgetsUrl"
    :title="'Budget '.$budget->exercise->year.' · v'.$budget->version"
>
    <x-slot:meta>
        <span>{{ $budget->purpose->label() }}</span>
        <span class="mp2-object-meta-separator" aria-hidden="true"></span>
        <span>Approvato il <strong>{{ $budget->approved_at->timezone($budget->company->timezone)->format('d/m/Y') }}</strong></span>
        <span class="mp2-object-state-badge mp2-object-state-success">Approvato</span>
        <span class="mp2-object-state-badge mp2-object-state-neutral">Snapshot Immutabile</span>
    </x-slot:meta>

    <x-slot:actions>
        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
    </x-slot:actions>
</x-mp2.object-header>
