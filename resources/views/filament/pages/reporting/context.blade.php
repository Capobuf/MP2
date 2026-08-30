<div class="mp2-report-context" aria-label="Riferimenti e filtri del report">
    <div class="mp2-report-context-heading">
        <p class="mp2-report-kicker">Riferimenti e filtri</p>
        <x-filament::button color="gray" outlined wire:click="toggleFilters" icon="heroicon-m-funnel">
            Filtri @if ($this->activeFilterCount() > 0) ({{ $this->activeFilterCount() }}) @endif
        </x-filament::button>
    </div>

    <div class="mp2-report-reference-form">
        {{ $this->references }}
    </div>

    @if ($this->activeFilterLabels() !== [])
        <div class="mp2-report-active-filters" role="list" aria-label="Filtri attivi">
            @foreach ($this->activeFilterLabels() as $label)
                <span role="listitem">{{ $label }}</span>
            @endforeach
            <button type="button" wire:click="clearFilters">Azzera filtri</button>
        </div>
    @endif

    @if ($filtersOpen)
        <div class="mp2-report-filter-panel">
            <div class="mp2-report-filter-panel-heading">
                <div>
                    <h4>Filtri opzionali</h4>
                    <p>Ogni modifica aggiorna automaticamente il report.</p>
                </div>
                @if ($this->activeFilterCount() > 0)
                    <x-filament::button color="gray" size="sm" wire:click="clearFilters">
                        Azzera filtri
                    </x-filament::button>
                @endif
            </div>
            {{ $this->filters }}
            @if ($this->dateIntervalIncomplete())
                <p class="mp2-report-field-error" role="status">Completa entrambe le date dell’intervallo.</p>
            @endif
        </div>
    @endif
</div>
