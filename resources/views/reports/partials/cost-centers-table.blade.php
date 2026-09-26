<table data-block="{{ $block }}">
    <thead><tr><th>Centro di Costo</th><th class="money">Allocato diretto</th><th class="money">Effettivo diretto</th><th class="money">Allocato ramo</th><th class="money">Effettivo ramo</th><th class="money">Scostamento ramo</th></tr></thead>
    <tbody>
        @foreach ($rows as $row)
            <tr><td><span class="source-name">{{ $row['label'] }}</span></td><td class="money">{{ $money($row['direct_allocation']) }}</td><td class="money">{{ $money($row['direct_actual']) }}</td><td class="money">{{ $money($row['branch_allocation']) }}</td><td class="money">{{ $money($row['branch_actual']) }}</td><td class="money">{{ $money($row['branch_operational_variance']) }}</td></tr>
        @endforeach
    </tbody>
</table>
<p class="definitions">I totali di ramo sono roll-up informativi e non vengono sommati nuovamente al totale aziendale.</p>
