@php
    use Illuminate\Support\Number;
    $money = static fn (string|int|float $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
@endphp

@foreach ($report['sections'] as $section)
    <section class="mp2-report-table-section" aria-labelledby="report-section-{{ $loop->index }}">
        <div class="mp2-report-section-heading">
            <div>
                <p class="mp2-report-kicker">Report specialistico</p>
                <h3 id="report-section-{{ $loop->index }}">{{ $section['title'] }}</h3>
            </div>
        </div>

        @if ($section['rows'] === [])
            <div class="mp2-report-empty mp2-report-empty-contained"><p>Nessun dato applicabile.</p></div>
        @elseif ($report['header']['kind'] === 'suppliers')
            <div class="mp2-report-table-wrap" tabindex="0">
                <table class="mp2-report-table">
                    <thead><tr><th>Fornitore</th><th class="mp2-report-number">Allocato</th><th class="mp2-report-number">Effettivo</th><th class="mp2-report-number">Scostamento</th><th>Sorgenti</th></tr></thead>
                    <tbody>
                        @foreach ($section['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                <td class="mp2-report-number">{{ $money($row['allocation']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['actual']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['operational_variance']) }}</td>
                                <td><div class="mp2-report-chip-list">@foreach (array_unique($row['sources']) as $source)<span>{{ $source }}</span>@endforeach</div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($report['header']['kind'] === 'contracts')
            <div class="mp2-report-table-wrap" tabindex="0">
                <table class="mp2-report-table">
                    <thead><tr><th>Contratto</th><th>Stato</th><th class="mp2-report-number">Allocato</th><th class="mp2-report-number">Effettivo</th><th class="mp2-report-number">Scostamento</th><th>Etichette</th><th>Drill-down</th></tr></thead>
                    <tbody>
                        @foreach ($section['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                <td>{{ $this->stateLabel($row['state']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['allocation']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['actual']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['operational_variance']) }}</td>
                                <td><div class="mp2-report-chip-list">@forelse ($row['labels'] as $label)<span>{{ $label }}</span>@empty<span>—</span>@endforelse</div></td>
                                <td>
                                    <details class="mp2-report-drilldown">
                                        <summary>Apri dettaglio</summary>
                                        @include('filament.pages.reporting.drilldown', ['source' => [
                                            'source_type' => 'contract', 'label' => $row['label'], 'summary' => null,
                                            'supplier' => null, 'state' => $row['state'], 'allocation' => $row['allocation'],
                                            'actual' => $row['actual'], 'operational_variance' => $row['operational_variance'],
                                            'carryover' => '0.00', 'residual' => '0.00', 'saving' => '0.00', 'unused' => '0.00',
                                            'detail' => $row['detail'], 'corrections' => [], 'annotations' => [],
                                        ]])
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif (in_array($report['header']['kind'], ['projects', 'carryovers'], true))
            <div class="mp2-report-table-wrap" tabindex="0">
                <table class="mp2-report-table">
                    <thead><tr><th>Progetto</th><th>Stato</th><th class="mp2-report-number">Allocato</th><th class="mp2-report-number">Effettivo</th><th class="mp2-report-number">Residuo</th><th class="mp2-report-number">Risparmio</th><th class="mp2-report-number">Non utilizzato</th><th class="mp2-report-number">Riporto</th><th>Drill-down</th></tr></thead>
                    <tbody>
                        @foreach ($section['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                <td>{{ $this->stateLabel($row['state']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['allocation']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['actual']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['residual']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['saving']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['unused']) }}</td>
                                <td class="mp2-report-number">{{ $money($row['carryover']) }}</td>
                                <td><details class="mp2-report-drilldown"><summary>Apri dettaglio</summary>@include('filament.pages.reporting.drilldown', ['source' => $row])</details></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="mp2-report-structured-section">
                @include('filament.pages.reporting.key-value', ['data' => $section['rows']])
            </div>
        @endif
    </section>
@endforeach
