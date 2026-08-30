@php
    use Illuminate\Support\Number;
    $money = static fn (string|int|float $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
@endphp

<section class="mp2-report-table-section" aria-labelledby="report-sources-title">
    <div class="mp2-report-section-heading">
        <div>
            <p class="mp2-report-kicker">Dettaglio</p>
            <h3 id="report-sources-title">Sorgenti economiche</h3>
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
            <tbody>
                @foreach ($report['sources'] as $source)
                    <tr>
                        <th scope="row">
                            {{ $source['label'] }}
                            <small>{{ $source['origin_key'] }}</small>
                        </th>
                        <td>{{ $this->sourceTypeLabel($source['source_type']) }}</td>
                        <td>{{ $source['cost_center'] ?? 'Non classificato' }}</td>
                        <td>{{ $source['supplier'] ?? '—' }}</td>
                        <td class="mp2-report-number">{{ $money($source['allocation']) }}</td>
                        <td class="mp2-report-number">{{ $money($source['actual']) }}</td>
                        <td class="mp2-report-number">{{ $money($source['operational_variance']) }}</td>
                        <td><span class="mp2-report-state">{{ $this->stateLabel($source['state']) }}</span></td>
                        <td>
                            <details class="mp2-report-drilldown">
                                <summary>Apri dettaglio</summary>
                                @include('filament.pages.reporting.drilldown', ['source' => $source])
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
