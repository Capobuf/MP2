<table data-block="{{ $block }}">
    <thead><tr><th>Progetto</th><th>Passaggio</th><th>Modalità</th><th class="money">Allocato</th><th class="money">Effettivo</th><th class="money">Residuo</th><th class="money">Massimo riportabile</th><th>Trasferimento</th></tr></thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td><span class="source-name">{{ $row['label'] }}</span>@if ($row['decision_reason'] ?? null)<span class="cell-secondary">Motivo: {{ $row['decision_reason'] }}</span>@endif</td>
                <td>{{ $row['source_exercise_year'] ?? '—' }} → {{ $row['destination_exercise_year'] ?? '—' }}</td><td>{{ $row['mode_label'] ?? '—' }}</td>
                <td class="money">{{ $money($row['allocation']) }}</td><td class="money">{{ $money($row['actual']) }}</td><td class="money">{{ $money($row['residual']) }}</td><td class="money">{{ $money($row['maximum_transferable'] ?? $row['residual']) }}</td>
                <td>
                    @if ((float) ($row['reprogrammed_amount'] ?? 0) !== 0.0)<span class="cell-secondary">Riprogrammato: {{ $money($row['reprogrammed_amount']) }}</span>@endif
                    @if (($row['provisional_carryover'] ?? null) !== null)<span class="cell-secondary">Provvisorio: {{ $money($row['provisional_carryover']) }}</span>@endif
                    @if (($row['consolidated_carryover'] ?? null) !== null)<span class="cell-secondary">Consolidato: {{ $money($row['consolidated_carryover']) }}</span>@endif
                    @if ((float) ($row['reprogrammed_amount'] ?? 0) === 0.0 && ($row['provisional_carryover'] ?? null) === null && ($row['consolidated_carryover'] ?? null) === null) — @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
