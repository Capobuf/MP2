@php
    $stateTone = match ($currentState->value) {
        'active' => 'success',
        'planned' => 'info',
        default => 'neutral',
    };
@endphp

<x-mp2.object-header
    icon="heroicon-o-document-text"
    icon-kind="contract"
    index-label="Contratti"
    :index-url="$contractsUrl"
    :title="$contract->title"
>
    <x-slot:meta>
        <span>Fornitore: <strong>{{ $contract->supplier->legal_name }}</strong></span>
        <span class="mp2-object-meta-separator" aria-hidden="true"></span>
        <span class="mp2-object-state-badge mp2-object-state-{{ $stateTone }}">{{ $currentState->label() }}</span>
        @if ($contract->isArchived())
            <span class="mp2-object-archive-badge">Archiviato</span>
        @endif
    </x-slot:meta>

    <x-slot:actions>
        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
    </x-slot:actions>

    <div class="mp2-object-time-position" role="group" aria-label="Posizione temporale del Contratto">
        <div class="mp2-object-time-line" aria-hidden="true"></div>

        <div class="mp2-object-time-point mp2-object-time-start">
            <span class="mp2-object-time-node" aria-hidden="true">
                <x-filament::icon icon="heroicon-m-check" />
            </span>
            <span class="mp2-object-time-label">Data di inizio</span>
            <time datetime="{{ $contract->contractualStartDate()->toDateString() }}">
                {{ $contract->contractualStartDate()->format('d/m/Y') }}
            </time>
        </div>

        <div class="mp2-object-time-point mp2-object-time-current">
            <span class="mp2-object-time-node" aria-hidden="true"><span></span></span>
            <span class="mp2-object-time-label">Oggi / Stato attuale</span>
            <strong>{{ $currentState->label() }}</strong>
            <span class="fi-sr-only">alla data {{ $today->format('d/m/Y') }}</span>
        </div>

        <div class="mp2-object-time-point mp2-object-time-end {{ $contract->nextExpiryDate() === null ? 'mp2-object-time-undefined' : '' }}">
            <span class="mp2-object-time-node" aria-hidden="true"></span>
            <span class="mp2-object-time-label">Prossima scadenza</span>
            @if ($contract->nextExpiryDate() !== null)
                <time datetime="{{ $contract->nextExpiryDate()?->toDateString() }}">
                    {{ $contract->nextExpiryDate()?->format('d/m/Y') }}
                </time>
            @else
                <strong>Scadenza non definita</strong>
            @endif
        </div>
    </div>
</x-mp2.object-header>
