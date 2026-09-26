<x-filament-panels::page>
    <div class="mp2-report-page">
        @if ($kind === null)
            @include('filament.pages.reporting.chooser')
        @else
            @include('filament.pages.reporting.header')

            @error('kind')
                <p class="mp2-report-field-error" role="alert">{{ $message }}</p>
            @enderror

            <div wire:loading.flex class="mp2-report-loading" role="status" aria-live="polite">
                <x-filament::loading-indicator class="h-4 w-4" />
                <span>Aggiornamento…</span>
            </div>

            @if (! $this->isReportConfigurationComplete())
                <section class="mp2-report-incomplete" aria-labelledby="report-incomplete-title">
                    <div>
                        <p class="mp2-report-kicker">Configurazione Esplicita</p>
                        <h3 id="report-incomplete-title">Completa i Riferimenti</h3>
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
                    @include('filament.pages.reporting.summary')

                    @if ($kind === 'annual_executive')
                        @include('filament.pages.reporting.charts')
                        @include('filament.pages.reporting.cost-centers-table')
                        @if ($report['sources'] !== [])
                            @include('filament.pages.reporting.sources-table', [
                                'kicker' => 'Riconciliazione',
                                'title' => 'Sorgenti Economiche dell’Esercizio',
                            ])
                        @else
                            @include('filament.pages.reporting.empty')
                        @endif
                    @elseif (in_array($kind, ['budget_actual', 'budget_current_allocation', 'budget_versions', 'exercises'], true))
                        @include('filament.pages.reporting.charts')
                        @if ($report['comparisons'] !== [])
                            @include('filament.pages.reporting.comparisons-table')
                        @else
                            @include('filament.pages.reporting.empty')
                        @endif
                    @elseif ($kind === 'operational_variance')
                        @include('filament.pages.reporting.charts')
                        @if ($report['sources'] !== [])
                            @include('filament.pages.reporting.operational-table')
                        @else
                            @include('filament.pages.reporting.empty')
                        @endif
                    @else
                        @include('filament.pages.reporting.charts')
                        @include('filament.pages.reporting.sections')
                    @endif
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
