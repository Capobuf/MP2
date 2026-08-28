<x-mp2.object-header
    icon="heroicon-o-clipboard-document-list"
    icon-kind="proposal"
    index-label="Proposte"
    :index-url="$proposalsUrl"
    :title="'Proposta '.$proposal->exercise->year.' · #'.$proposal->id"
>
    <x-slot:meta>
        <span>{{ $proposal->purpose->label() }}</span>
        <span class="mp2-object-meta-separator" aria-hidden="true"></span>
        <span>Creata il <strong>{{ $proposal->created_at->timezone($proposal->company->timezone)->format('d/m/Y') }}</strong></span>
        <span @class([
            'mp2-object-state-badge',
            'mp2-object-state-success' => $proposal->status->value === 'approved',
            'mp2-object-state-info' => $proposal->status->value === 'draft',
            'mp2-object-state-neutral' => $proposal->status->value === 'discarded',
        ])>{{ $proposal->status->label() }}</span>
        @if ($proposal->referenceBudget !== null)
            <span class="mp2-object-state-badge mp2-object-state-neutral">Riferimento · Budget v{{ $proposal->referenceBudget->version }}</span>
        @endif
    </x-slot:meta>

    <x-slot:actions>
        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
    </x-slot:actions>
</x-mp2.object-header>
