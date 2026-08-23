@php
    $stateTone = match ($currentState?->value) {
        'open' => 'success',
        'planned' => 'info',
        default => 'neutral',
    };
@endphp

<x-mp2.object-header
    icon="heroicon-o-briefcase"
    icon-kind="project"
    index-label="Progetti"
    :index-url="$projectsUrl"
    :title="$project->title"
>
    <x-slot:meta>
        <span>Progetto</span>
        <span class="mp2-object-meta-separator" aria-hidden="true"></span>
        <span class="mp2-object-state-badge mp2-object-state-{{ $stateTone }}">
            {{ $currentState?->label() ?? 'Assente alla data' }}
        </span>
        @if ($project->isArchived())
            <span class="mp2-object-archive-badge">Archiviato</span>
        @endif
    </x-slot:meta>

    <x-slot:actions>
        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
    </x-slot:actions>

    <div class="mp2-object-time-position" role="group" aria-label="Posizione temporale del Progetto">
        <div class="mp2-object-time-line" aria-hidden="true"></div>

        <div class="mp2-object-time-point mp2-object-time-start">
            <span class="mp2-object-time-node" aria-hidden="true">
                <x-filament::icon icon="heroicon-m-check" />
            </span>
            <span class="mp2-object-time-label">Data di efficacia iniziale</span>
            <time datetime="{{ $project->initialEffectiveDate()->toDateString() }}">
                {{ $project->initialEffectiveDate()->format('d/m/Y') }}
            </time>
        </div>

        <div class="mp2-object-time-point mp2-object-time-current">
            <span class="mp2-object-time-node" aria-hidden="true"><span></span></span>
            <span class="mp2-object-time-label">Oggi / Stato attuale</span>
            <strong>{{ $currentState?->label() ?? 'Assente alla data' }}</strong>
            <span class="fi-sr-only">alla data {{ $today->format('d/m/Y') }}</span>
        </div>

        <div class="mp2-object-time-point mp2-object-time-end {{ $nextTransition === null ? 'mp2-object-time-undefined' : '' }}">
            <span class="mp2-object-time-node" aria-hidden="true"></span>
            <span class="mp2-object-time-label">Prossima transizione</span>
            @if ($nextTransition !== null)
                <time datetime="{{ $nextTransition->effectiveDate()->toDateString() }}">
                    {{ $nextTransition->effectiveDate()->format('d/m/Y') }} · {{ $nextTransition->to_state->label() }}
                </time>
            @else
                <strong>Nessuna pianificata</strong>
            @endif
        </div>
    </div>
</x-mp2.object-header>
