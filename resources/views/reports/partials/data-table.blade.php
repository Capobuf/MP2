@php
    $columns = collect($document['selected_columns'])
        ->filter(fn (string $id): bool => str_starts_with($id, 'column:'.$group.':'))
        ->map(fn (string $id): string => str($id)->afterLast(':')->toString())->values()->all();
    $quantities = $group === 'comparisons' ? ['initial_value', 'final_value', 'delta'] : ['allocation', 'actual', 'operational_variance', 'carryover'];
    $renderColumns = $portrait ? array_values(array_intersect($columns, $quantities)) : $columns;
    $secondaryColumns = $portrait ? array_diff($columns, $renderColumns) : [];
    $cell = static function (array $row, string $column) use ($money, $quantities): string {
        if (in_array($column, $quantities, true)) {
            return $money($row[$column]);
        }
        if ($column === 'state') {
            return $row['state_label'];
        }
        return is_array($row[$column]) ? (implode(', ', $row[$column]) ?: '—') : ($row[$column] ?? '—');
    };
@endphp
<table data-block="{{ $block }}">
    <colgroup><col>@foreach ($renderColumns as $column)<col class="{{ in_array($column, $quantities, true) ? 'col-quantity' : 'col-metadata' }}">@endforeach</colgroup>
    <thead><tr><th scope="col">{{ $primaryLabel }}</th>@foreach ($renderColumns as $column)<th scope="col" class="{{ in_array($column, $quantities, true) ? 'money' : '' }}">{{ $availableColumns['column:'.$group.':'.$column]['label'] }}</th>@endforeach</tr></thead>
    @foreach ($rows as $row)
        <tbody>
            @if ($projectBalances)
                {{-- Keep the balances in one physical row: WeasyPrint can split a row group at a page boundary. --}}
                <tr><td class="project-cell" colspan="{{ count($renderColumns) + 1 }}">
                    <table class="project-values">
                        <colgroup><col>@foreach ($renderColumns as $column)<col class="{{ in_array($column, $quantities, true) ? 'col-quantity' : 'col-metadata' }}">@endforeach</colgroup>
                        <tbody><tr>
            @else
                <tr>
            @endif
                <td>
                    <span class="source-name">{{ $row['label'] }}</span>
                    @if ($secondaryColumns !== [])
                        <span class="row-metadata">
                            @foreach ($secondaryColumns as $column)
                                <span class="cell-secondary" data-column="{{ $column }}">{{ $availableColumns['column:'.$group.':'.$column]['label'] }}: {{ $cell($row, $column) }}</span>@unless ($loop->last) · @endunless
                            @endforeach
                        </span>
                    @endif
                    @if ($group === 'comparisons' && in_array('labels', $secondaryColumns, true) && $row['insufficiently_explained'])<span class="cell-secondary">Variazione non sufficientemente spiegata</span>@endif
                </td>
                @foreach ($renderColumns as $column)
                    <td class="{{ in_array($column, $quantities, true) ? 'money' : '' }}" data-column="{{ $column }}">
                        {{ $cell($row, $column) }}
                        @if ($group === 'comparisons' && $column === 'labels' && $row['insufficiently_explained'])<span class="cell-secondary">Variazione non sufficientemente spiegata</span>@endif
                    </td>
                @endforeach
            </tr>
            @if ($projectBalances)
                        </tbody>
                    </table>
                    <p class="cell-secondary project-balances">Residuo: {{ $money($row['residual']) }} · Risparmio: {{ $money($row['saving']) }} · Allocato non utilizzato: {{ $money($row['unused']) }}</p>
                </td></tr>
            @endif
        </tbody>
    @endforeach
</table>
