@php
    use Illuminate\Support\Number;
    $money = static fn (string|int|float $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
@endphp

<section class="mp2-report-table-section" aria-labelledby="report-sources-title">
    <div class="mp2-report-section-heading">
        <div>
            <p class="mp2-report-kicker">Dettaglio</p>
            <h3 id="report-sources-title">Sorgenti Economiche</h3>
        </div>
        <p>{{ count($report['sources']) }} sorgenti</p>
    </div>
    <div class="mp2-report-table-wrap" tabindex="0">
        <table class="mp2-report-table">
            <thead>
                <tr>
                    <th scope="col">Sorgente</th>
                    <th scope="col">Tipo</th>
                    <th scope="col">Centro di Costo</th>
                    <th scope="col">Fornitore</th>
                    <th scope="col" class="mp2-report-number">Allocato</th>
                    <th scope="col" class="mp2-report-number">Effettivo</th>
                    <th scope="col" class="mp2-report-number">Scostamento</th>
                    <th scope="col">Stato</th>
                    <th scope="col">Drill-down</th>
                </tr>
            </thead>
            @foreach ($report['sources'] as $source)
                <tbody x-data="{ expanded: false }" class="mp2-report-row-group">
                    <tr>
                        <th scope="row">{{ $source['label'] }}</th>
                        <td>{{ $this->sourceTypeLabel($source['source_type']) }}</td>
                        <td>{{ $source['cost_center'] ?? 'Non classificato' }}</td>
                        <td>{{ $source['supplier'] ?? '—' }}</td>
                        <td class="mp2-report-number">{{ $money($source['allocation']) }}</td>
                        <td class="mp2-report-number">{{ $money($source['actual']) }}</td>
                        <td class="mp2-report-number">{{ $money($source['operational_variance']) }}</td>
                        <td><span class="mp2-report-state">{{ $this->stateLabel($source['state']) }}</span></td>
                        <td>
                            <button
                                type="button"
                                class="mp2-report-drilldown-trigger"
                                x-on:click="expanded = ! expanded"
                                x-bind:aria-expanded="expanded"
                            >
                                <span x-text="expanded ? 'Chiudi Dettaglio' : 'Apri Dettaglio'">Apri Dettaglio</span>
                                <x-filament::icon icon="heroicon-m-chevron-down" x-bind:class="{ 'is-expanded': expanded }" />
                            </button>
                        </td>
                    </tr>
                    <tr class="mp2-report-detail-row" x-show="expanded" x-cloak>
                        <td colspan="9">
                            @include('filament.pages.reporting.drilldown', ['source' => $source])
                        </td>
                    </tr>
                </tbody>
            @endforeach
        </table>
    </div>
</section>
