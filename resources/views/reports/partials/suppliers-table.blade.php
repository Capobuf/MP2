<table data-block="{{ $block }}">
    <colgroup><col><col class="col-quantity"><col class="col-quantity"><col class="col-quantity">@if (! $portrait)<col style="width: 30%">@endif</colgroup>
    <thead><tr><th scope="col">Fornitore</th><th scope="col" class="money">Allocato</th><th scope="col" class="money">Effettivo</th><th scope="col" class="money">Scostamento</th>@if (! $portrait)<th scope="col">Composizione</th>@endif</tr></thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td><span class="source-name">{{ $row['label'] }}</span>@if ($portrait)<span class="cell-secondary">Composizione: {{ implode(' · ', array_unique($row['sources'])) }}</span>@endif</td>
                <td class="money">{{ $money($row['allocation']) }}</td>
                <td class="money">{{ $money($row['actual']) }}</td>
                <td class="money">{{ $money($row['operational_variance']) }}</td>
                @if (! $portrait)<td>@foreach ($row['components'] ?? [] as $component)<span class="cell-secondary">{{ $component['expense_label'] }} · {{ $component['source_label'] }}</span>@endforeach</td>@endif
            </tr>
        @endforeach
    </tbody>
</table>
