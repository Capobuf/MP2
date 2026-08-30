<x-filament-panels::page>
    <div class="mp2-report-page">
        @if ($kind === null)
            @include('filament.pages.reporting.chooser')
        @else
            <section class="mp2-report-identity" aria-labelledby="report-kind-title">
                <div>
                    <p class="mp2-report-kicker">Controllo economico</p>
                    <h2 id="report-kind-title">{{ $this->currentKindLabel() }}</h2>
                    <p>{{ $this->reportDescription($kind) }}</p>
                </div>
                <x-filament::button color="gray" outlined wire:click="changeReport">
                    Cambia report
                </x-filament::button>
            </section>

            @error('kind')
                <p class="mp2-report-field-error" role="alert">{{ $message }}</p>
            @enderror

            @include('filament.pages.reporting.context')

            <div wire:loading.flex class="mp2-report-loading" role="status" aria-live="polite">
                <x-filament::loading-indicator class="h-4 w-4" />
                <span>Aggiornamento…</span>
            </div>

            @if (! $this->isReportConfigurationComplete())
                <section class="mp2-report-incomplete" aria-labelledby="report-incomplete-title">
                    <div>
                        <p class="mp2-report-kicker">Configurazione esplicita</p>
                        <h3 id="report-incomplete-title">Completa i riferimenti</h3>
                        @if ($this->dateIntervalIncomplete())
                            <p>Completa entrambe le date dell’intervallo per aggiornare il report.</p>
                        @elseif ($this->missingReferences() !== [])
                            <p>Mancano: {{ implode(' · ', $this->missingReferences()) }}.</p>
                        @endif
                    </div>
                </section>
            @endif

            @if ($report)
                <div class="mp2-report-result" wire:loading.class="mp2-report-result-updating">
                    @include('filament.pages.reporting.header')
                    @include('filament.pages.reporting.summary')
                    @include('filament.pages.reporting.charts')

                    @if ($report['sources'] === [])
                        <section class="mp2-report-empty" aria-labelledby="report-empty-title">
                            <h3 id="report-empty-title">Nessun dato</h3>
                            <p>Nessun dato per i riferimenti e i filtri selezionati.</p>
                        </section>
                    @else
                        @include('filament.pages.reporting.sources-table')
                    @endif

                    @include('filament.pages.reporting.comparisons-table')
                    @include('filament.pages.reporting.sections')
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
