@php
    use Illuminate\Support\Number;
    $money = static fn (string|int|float $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
@endphp

<section class="mp2-report-table-section" aria-labelledby="report-operational-title">
    <div class="mp2-report-section-heading">
        <div>
            <p class="mp2-report-kicker">Situazione Corrente</p>
            <h3 id="report-operational-title">Scostamento per Sorgente</h3>
        </div>
        <p>Effettivo Corrente − Allocato Corrente</p>
    </div>
    <div class="mp2-report-table-wrap" tabindex="0">
        <table class="mp2-report-table mp2-report-operational-table">
            <thead><tr><th>Sorgente</th><th class="mp2-report-number">Allocato Corrente</th><th class="mp2-report-number">Effettivo Corrente</th><th class="mp2-report-number">Scostamento Operativo</th><th>Stato</th><th>Drill-down</th></tr></thead>
            @foreach ($report['sources'] as $source)
                <tbody x-data="{ expanded: false }" class="mp2-report-row-group">
                    <tr>
                        <th scope="row">{{ $source['label'] }}<small>{{ $this->sourceTypeLabel($source['source_type']) }}@if ($source['cost_center']) · {{ $source['cost_center'] }}@endif</small></th>
                        <td class="mp2-report-number">{{ $money($source['allocation']) }}</td>
                        <td class="mp2-report-number">{{ $money($source['actual']) }}</td>
                        <td class="mp2-report-number">{{ $money($source['operational_variance']) }}</td>
                        <td><span class="mp2-report-state">{{ $this->stateLabel($source['state']) }}</span></td>
                        <td><button type="button" class="mp2-report-drilldown-trigger" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded"><span x-text="expanded ? 'Chiudi Dettaglio' : 'Apri Dettaglio'">Apri Dettaglio</span><x-filament::icon icon="heroicon-m-chevron-down" x-bind:class="{ 'is-expanded': expanded }" /></button></td>
                    </tr>
                    <tr class="mp2-report-detail-row" x-show="expanded" x-cloak><td colspan="6">@include('filament.pages.reporting.drilldown', ['source' => $source])</td></tr>
                </tbody>
            @endforeach
        </table>
    </div>
</section>
