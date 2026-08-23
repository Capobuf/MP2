<div class="mp2-allocation-detail">
    <dl class="mp2-allocation-detail-summary">
        <div>
            <dt>Cicli inclusi</dt>
            <dd>{{ $detail['composition_count'] }}</dd>
        </div>
        <div>
            <dt>Primo ciclo incluso</dt>
            <dd>{{ $detail['first_cycle_start'] ?? '—' }}</dd>
        </div>
        <div>
            <dt>Ultimo ciclo incluso</dt>
            <dd>{{ $detail['last_cycle_start'] ?? '—' }}</dd>
        </div>
        <div>
            <dt>Totale Allocato</dt>
            <dd>{{ $detail['allocation'] }}</dd>
        </div>
    </dl>

    @if ($detail['composition'] !== [])
        <div class="mp2-allocation-detail-table-wrap">
            <table class="mp2-allocation-detail-table">
                <thead>
                    <tr>
                        <th scope="col">Inizio ciclo</th>
                        <th scope="col">Data di attribuzione</th>
                        <th scope="col" class="mp2-object-number">Importo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($detail['composition'] as $item)
                        <tr>
                            <td>{{ $item['cycle_start'] }}</td>
                            <td>{{ $item['attribution_date'] }}</td>
                            <td class="mp2-object-number">{{ $item['amount'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="mp2-contract-muted-copy">Nessun ciclo attribuito a questo Esercizio.</p>
    @endif
</div>
