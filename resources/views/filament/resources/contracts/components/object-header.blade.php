<header class="mp2-contract-object-header">
    <div class="mp2-contract-header-artwork" aria-hidden="true">
        <img src="{{ Vite::asset('1.svg') }}" alt="">
    </div>

    <nav class="mp2-contract-breadcrumbs" aria-label="Percorso">
        <a href="{{ $contractsUrl }}">Contratti</a>
        <x-filament::icon icon="heroicon-m-chevron-right" aria-hidden="true" />
        <span aria-current="page">{{ $contract->title }}</span>
    </nav>

    <div class="mp2-contract-header-main">
        <div class="mp2-contract-identity">
            <h1>{{ $contract->title }}</h1>
            <div class="mp2-contract-meta">
                <span>Fornitore: <strong>{{ $contract->supplier->legal_name }}</strong></span>
                <span class="mp2-contract-meta-separator" aria-hidden="true"></span>
                <span class="mp2-contract-state-badge mp2-contract-state-{{ $currentState->value }}">{{ $currentState->label() }}</span>
                @if ($contract->isArchived())
                    <span class="mp2-contract-archive-badge">Archiviato</span>
                @endif
            </div>
        </div>

        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
    </div>

    <div class="mp2-contract-time-position" role="group" aria-label="Posizione temporale del Contratto">
        <div class="mp2-contract-time-line" aria-hidden="true"></div>

        <div class="mp2-contract-time-point mp2-contract-time-start">
            <span class="mp2-contract-time-node" aria-hidden="true">
                <x-filament::icon icon="heroicon-m-check" />
            </span>
            <span class="mp2-contract-time-label">Data di inizio</span>
            <time datetime="{{ $contract->contractualStartDate()->toDateString() }}">
                {{ $contract->contractualStartDate()->format('d/m/Y') }}
            </time>
        </div>

        <div class="mp2-contract-time-point mp2-contract-time-current">
            <span class="mp2-contract-time-node" aria-hidden="true"><span></span></span>
            <span class="mp2-contract-time-label">Oggi / Stato attuale</span>
            <strong>{{ $currentState->label() }}</strong>
            <span class="fi-sr-only">alla data {{ $today->format('d/m/Y') }}</span>
        </div>

        <div class="mp2-contract-time-point mp2-contract-time-end {{ $contract->nextExpiryDate() === null ? 'mp2-contract-time-undefined' : '' }}">
            <span class="mp2-contract-time-node" aria-hidden="true"></span>
            <span class="mp2-contract-time-label">Prossima scadenza</span>
            @if ($contract->nextExpiryDate() !== null)
                <time datetime="{{ $contract->nextExpiryDate()?->toDateString() }}">
                    {{ $contract->nextExpiryDate()?->format('d/m/Y') }}
                </time>
            @else
                <strong>Scadenza non definita</strong>
            @endif
        </div>
    </div>
</header>
