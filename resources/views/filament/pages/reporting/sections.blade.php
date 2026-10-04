@php
    use Illuminate\Support\Number;
    $money = static fn (string|int|float $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
@endphp

@foreach ($report['sections'] as $section)
    <section class="mp2-report-table-section" aria-labelledby="report-section-{{ $loop->index }}">
        <div class="mp2-report-section-heading">
            <div>
                <p class="mp2-report-kicker">Report Specialistico</p>
                <h3 id="report-section-{{ $loop->index }}">{{ $section['title'] }}</h3>
            </div>
        </div>

        @if ($section['rows'] === [])
            <div class="mp2-report-empty mp2-report-empty-contained"><p>Nessun dato applicabile.</p></div>
        @elseif ($report['header']['kind'] === 'suppliers')
            <div class="mp2-report-table-wrap" tabindex="0">
                <table class="mp2-report-table">
                    <thead><tr><th>Fornitore</th><th class="mp2-report-number">Allocato</th><th class="mp2-report-number">Effettivo</th><th class="mp2-report-number">Scostamento</th><th>Composizione</th></tr></thead>
                    @foreach ($section['rows'] as $row)
                        <tbody x-data="{ expanded: false }" class="mp2-report-row-group">
                            <tr>
                                <th scope="row">@if ($row['url'] !== null)<a href="{{ $row['url'] }}">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif</th>
                                <td class="mp2-report-number">{{ $money($row['allocation']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['actual']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['operational_variance']) }}</td>
                                <td><button type="button" class="mp2-report-drilldown-trigger" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded"><span x-text="expanded ? 'Chiudi Composizione' : 'Apri Composizione'">Apri Composizione</span><x-filament::icon icon="heroicon-m-chevron-down" x-bind:class="{ 'is-expanded': expanded }" /></button></td>
                            </tr>
                            <tr class="mp2-report-detail-row" x-show="expanded" x-cloak>
                                <td colspan="5">
                                    <div class="mp2-report-drilldown-content">
                                        <div class="mp2-report-drilldown-heading"><div><p class="mp2-report-kicker">Composizione del Bucket</p><h4>{{ $row['label'] }}</h4></div></div>
                                        <div class="mp2-report-lines-wrap">
                                            <table class="mp2-report-lines">
                                                <thead><tr><th>Componente</th><th>Contesto</th><th class="mp2-report-number">Allocato</th><th class="mp2-report-number">Effettivo</th></tr></thead>
                                                <tbody>
                                                    @foreach ($row['components'] ?? [] as $component)
                                                        <tr><td>{{ $component['expense_label'] }}</td><td>{{ $this->sourceTypeLabel($component['source_type']) }} · {{ $component['source_label'] }}</td><td class="mp2-report-number">{{ $money($component['allocation']) }}</td><td class="mp2-report-number">{{ $money($component['actual']) }}</td></tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @elseif ($report['header']['kind'] === 'contracts')
            <div class="mp2-report-table-wrap" tabindex="0">
                <table class="mp2-report-table">
                    <thead><tr><th>Contratto</th><th>Stato</th><th class="mp2-report-number">Allocato</th><th class="mp2-report-number">Effettivo</th><th class="mp2-report-number">Scostamento</th><th>Etichette</th><th>Drill-down</th></tr></thead>
                    @foreach ($section['rows'] as $row)
                        <tbody x-data="{ expanded: false }" class="mp2-report-row-group">
                            <tr>
                                <th scope="row">@if ($row['url'] !== null)<a href="{{ $row['url'] }}">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif</th>
                                <td>{{ $this->stateLabel($row['state']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['allocation']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['actual']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['operational_variance']) }}</td>
                                <td><div class="mp2-report-chip-list">@forelse ($row['labels'] as $label)<span>{{ $label }}</span>@empty<span>—</span>@endforelse</div></td>
                                <td>
                                    <button type="button" class="mp2-report-drilldown-trigger" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded">
                                        <span x-text="expanded ? 'Chiudi Dettaglio' : 'Apri Dettaglio'">Apri Dettaglio</span>
                                        <x-filament::icon icon="heroicon-m-chevron-down" x-bind:class="{ 'is-expanded': expanded }" />
                                    </button>
                                </td>
                            </tr>
                            <tr class="mp2-report-detail-row" x-show="expanded" x-cloak>
                                <td colspan="7">
                                    @include('filament.pages.reporting.drilldown', ['source' => [
                                        'source_type' => 'contract', 'label' => $row['label'], 'summary' => null,
                                        'supplier' => null, 'state' => $row['state'], 'allocation' => $row['allocation'],
                                        'actual' => $row['actual'], 'operational_variance' => $row['operational_variance'],
                                        'carryover' => '0.00', 'residual' => '0.00', 'saving' => '0.00', 'unused' => '0.00',
                                        'detail' => $row['detail'], 'corrections' => $row['corrections'], 'annotations' => $row['annotations'],
                                    ]])
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @elseif ($report['header']['kind'] === 'projects')
            <div class="mp2-report-table-wrap" tabindex="0">
                <table class="mp2-report-table mp2-report-projects-table">
                    <thead><tr><th>Progetto</th><th>Stato</th><th class="mp2-report-number">Allocato</th><th class="mp2-report-number">Effettivo</th><th class="mp2-report-number">Scostamento</th><th>Saldo Applicabile</th><th>Rinvio</th><th>Drill-down</th></tr></thead>
                    @foreach ($section['rows'] as $row)
                        @php
                            $balance = match ($row['state']) {
                                'planned', 'open' => ['Residuo', $row['residual']],
                                'closed' => ['Risparmio', $row['saving']],
                                'cancelled' => ['Allocato non utilizzato', $row['unused']],
                                default => [null, null],
                            };
                            $deferrals = is_array(data_get($row, 'detail.deferrals')) ? data_get($row, 'detail.deferrals') : [];
                            $outgoing = collect($deferrals)->firstWhere('source_exercise_id', $report['header']['exercise_id']);
                            $mode = $outgoing['mode'] ?? data_get($row, 'detail.deferral_mode');
                            $modeLabel = ['none' => 'Nessuna', 'carryover' => 'Riporto', 'reprogramming' => 'Riprogrammazione'][$mode] ?? null;
                        @endphp
                        <tbody x-data="{ expanded: false }" class="mp2-report-row-group">
                            <tr>
                                <th scope="row">@if ($row['url'] !== null)<a href="{{ $row['url'] }}">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif</th>
                                <td>{{ $this->stateLabel($row['state']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['allocation']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['actual']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['operational_variance']) }}</td>
                                <td>@if ($balance[0])<span class="mp2-report-cell-label">{{ $balance[0] }}</span><strong class="mp2-report-cell-value">{{ $money($balance[1]) }}</strong>@else — @endif</td>
                                <td>@if ($modeLabel)<span class="mp2-report-cell-label">{{ $modeLabel }}</span>@endif @if ((float) $row['carryover'] !== 0.0)<strong class="mp2-report-cell-value">{{ $money($row['carryover']) }}</strong>@elseif (! $modeLabel) — @endif</td>
                                <td>
                                    <button type="button" class="mp2-report-drilldown-trigger" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded">
                                        <span x-text="expanded ? 'Chiudi Dettaglio' : 'Apri Dettaglio'">Apri Dettaglio</span>
                                        <x-filament::icon icon="heroicon-m-chevron-down" x-bind:class="{ 'is-expanded': expanded }" />
                                    </button>
                                </td>
                            </tr>
                            <tr class="mp2-report-detail-row" x-show="expanded" x-cloak>
                                <td colspan="8">@include('filament.pages.reporting.drilldown', ['source' => $row])</td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @elseif ($report['header']['kind'] === 'carryovers')
            <div class="mp2-report-table-wrap" tabindex="0">
                <table class="mp2-report-table mp2-report-carryovers-table">
                    <thead><tr><th>Progetto</th><th>Passaggio</th><th>Modalità</th><th class="mp2-report-number">Allocato</th><th class="mp2-report-number">Effettivo</th><th class="mp2-report-number">Residuo</th><th class="mp2-report-number">Massimo Riportabile</th><th>Trasferimento</th><th>Drill-down</th></tr></thead>
                    @foreach ($section['rows'] as $row)
                        <tbody x-data="{ expanded: false }" class="mp2-report-row-group">
                            <tr>
                                <th scope="row">@if ($row['url'] !== null)<a href="{{ $row['url'] }}">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif@if ($row['decision_reason'])<small>Motivo: {{ $row['decision_reason'] }}</small>@endif</th>
                                <td>{{ $row['source_exercise_year'] ?? '—' }} → {{ $row['destination_exercise_year'] ?? '—' }}</td>
                                <td>{{ $row['mode_label'] }}</td>
                                <td class="mp2-report-number">{{ $money($row['allocation']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['actual']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['residual']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['maximum_transferable']) }}</td>
                                <td>
                                    @if ((float) $row['reprogrammed_amount'] !== 0.0)<span class="mp2-report-cell-label">Riprogrammato</span><strong class="mp2-report-cell-value">{{ $money($row['reprogrammed_amount']) }}</strong>@endif
                                    @if ($row['provisional_carryover'] !== null)<span class="mp2-report-cell-label">Riporto provvisorio</span><strong class="mp2-report-cell-value">{{ $money($row['provisional_carryover']) }}</strong>@endif
                                    @if ($row['consolidated_carryover'] !== null)<span class="mp2-report-cell-label">Riporto consolidato</span><strong class="mp2-report-cell-value">{{ $money($row['consolidated_carryover']) }}</strong>@endif
                                    @if ((float) $row['reprogrammed_amount'] === 0.0 && $row['provisional_carryover'] === null && $row['consolidated_carryover'] === null) — @endif
                                </td>
                                <td><button type="button" class="mp2-report-drilldown-trigger" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded"><span x-text="expanded ? 'Chiudi Dettaglio' : 'Apri Dettaglio'">Apri Dettaglio</span><x-filament::icon icon="heroicon-m-chevron-down" x-bind:class="{ 'is-expanded': expanded }" /></button></td>
                            </tr>
                            <tr class="mp2-report-detail-row" x-show="expanded" x-cloak>
                                <td colspan="9">
                                    @include('filament.pages.reporting.drilldown', ['source' => $row])
                                    @if ($row['decision'])<section class="mp2-report-detail-group"><h5>Decisione di Chiusura</h5>@include('filament.pages.reporting.key-value', ['data' => $row['decision']])</section>@endif
                                    @if ($row['carryover_difference'] !== null)<section class="mp2-report-detail-group"><h5>Differenza fra Riporto Provvisorio e Consolidato</h5><p>{{ $money($row['carryover_difference']) }}</p></section>@endif
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @else
            <div class="mp2-report-structured-section">
                @include('filament.pages.reporting.key-value', ['data' => $section['rows']])
            </div>
        @endif
    </section>
@endforeach
