<table data-block="{{ $block }}">
    <thead><tr><th>Progetto</th><th>Stato</th><th class="money">Allocato</th><th class="money">Effettivo</th><th class="money">Scostamento</th><th>Saldo applicabile</th><th>Rinvio</th></tr></thead>
    <tbody>
        @foreach ($rows as $row)
            @php
                $balance = match ($row['state']) {
                    'planned', 'open' => ['Residuo', $row['residual']],
                    'closed' => ['Risparmio', $row['saving']],
                    'cancelled' => ['Allocato non utilizzato', $row['unused']],
                    default => [null, null],
                };
                $deferrals = is_array(data_get($row, 'detail.deferrals')) ? data_get($row, 'detail.deferrals') : [];
                $outgoing = collect($deferrals)->firstWhere('source_exercise_id', $exerciseId);
                $mode = $outgoing['mode'] ?? data_get($row, 'detail.deferral_mode');
                $modeLabel = ['none' => 'Nessuna', 'carryover' => 'Riporto', 'reprogramming' => 'Riprogrammazione'][$mode] ?? '—';
            @endphp
            <tr>
                <td><span class="source-name">{{ $row['label'] }}</span></td><td>{{ $row['state_label'] }}</td><td class="money">{{ $money($row['allocation']) }}</td><td class="money">{{ $money($row['actual']) }}</td><td class="money">{{ $money($row['operational_variance']) }}</td>
                <td>@if ($balance[0])<span class="cell-secondary">{{ $balance[0] }}</span>{{ $money($balance[1]) }}@else — @endif</td>
                <td>{{ $modeLabel }}@if ((float) $row['carryover'] !== 0.0)<span class="cell-secondary">{{ $money($row['carryover']) }}</span>@endif</td>
            </tr>
        @endforeach
    </tbody>
</table>
