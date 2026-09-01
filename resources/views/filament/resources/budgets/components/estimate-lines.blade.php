@if ($lines !== [])
    <div class="mp2-budget-table-wrap" tabindex="0" role="region" aria-label="Righe Stima Attive">
        <table class="mp2-budget-detail-table">
            <thead>
                <tr>
                    <th class="mp2-object-number">Importo</th>
                    <th class="mp2-object-number">Quantità</th>
                    <th class="mp2-object-number">Importo Unitario</th>
                    <th>Unità di Misura</th>
                    <th>Nota</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td class="mp2-object-number">{{ $line['amount'] }}</td>
                        <td class="mp2-object-number">{{ $line['quantity'] }}</td>
                        <td class="mp2-object-number">{{ $line['unit_amount'] }}</td>
                        <td>{{ $line['unit_of_measure'] }}</td>
                        <td>{{ $line['note'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p class="mp2-budget-muted">Nessuna Riga Stima Attiva.</p>
@endif
