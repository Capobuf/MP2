<table data-block="{{ $block }}">
    <colgroup><col><col class="col-quantity"><col class="col-quantity"><col class="col-quantity">@if (! $portrait)<col style="width: 30%">@endif</colgroup>
    <thead><tr><th scope="col">Fornitore</th><th scope="col" class="money">Allocato</th><th scope="col" class="money">Effettivo</th><th scope="col" class="money">Scostamento</th>@if (! $portrait)<th scope="col">Sorgenti</th>@endif</tr></thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td><span class="source-name">{{ $row['label'] }}</span>@if ($portrait)<span class="cell-secondary">Sorgenti: {{ implode(' · ', array_unique($row['sources'])) }}</span>@endif</td>
                <td class="money">{{ $money($row['allocation']) }}</td>
                <td class="money">{{ $money($row['actual']) }}</td>
                <td class="money">{{ $money($row['operational_variance']) }}</td>
                @if (! $portrait)<td>{{ implode(' · ', array_unique($row['sources'])) }}</td>@endif
            </tr>
        @endforeach
    </tbody>
</table>
