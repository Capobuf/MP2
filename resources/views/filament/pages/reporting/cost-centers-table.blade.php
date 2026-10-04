@php
    use Illuminate\Support\Number;
    $money = static fn (string|int|float $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
@endphp

@if ($report['cost_centers'] !== [])
    <section class="mp2-report-table-section" aria-labelledby="report-cost-centers-title">
        <div class="mp2-report-section-heading">
            <div>
                <p class="mp2-report-kicker">Spiegazione dei Numeri</p>
                <h3 id="report-cost-centers-title">Analisi per Centro di Costo</h3>
            </div>
            <p>I totali di ramo non vengono sommati nuovamente al totale aziendale.</p>
        </div>
        <div class="mp2-report-table-wrap" tabindex="0">
            <table class="mp2-report-table mp2-report-cost-centers-table">
                <thead><tr><th>Centro di Costo</th><th class="mp2-report-number">Allocato Diretto</th><th class="mp2-report-number">Effettivo Diretto</th><th class="mp2-report-number">Allocato di Ramo</th><th class="mp2-report-number">Effettivo di Ramo</th><th class="mp2-report-number">Scostamento di Ramo</th></tr></thead>
                <tbody>
                    @foreach ($report['cost_centers'] as $row)
                        <tr>
                            <th scope="row"><a href="{{ $row['url'] }}">{{ $row['label'] }}</a></th>
                            <td class="mp2-report-number">{{ $money($row['direct_allocation']) }}</td>
                            <td class="mp2-report-number">{{ $money($row['direct_actual']) }}</td>
                            <td class="mp2-report-number">{{ $money($row['branch_allocation']) }}</td>
                            <td class="mp2-report-number">{{ $money($row['branch_actual']) }}</td>
                            <td class="mp2-report-number">{{ $money($row['branch_operational_variance']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
