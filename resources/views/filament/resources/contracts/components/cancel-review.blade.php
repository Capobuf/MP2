<div class="space-y-3">
    @php
        $money = fn ($value) => \Illuminate\Support\Number::currency((float) $value, 'EUR', locale: 'it');
        $lines = collect($preview['candidates'] ?? []);
    @endphp
    @foreach ($preview['exercises'] ?? [] as $year)
        @if ($year['open'])
            @php
                $removed = \App\Domain\Expenses\Decimal::sum($lines->whereIn('line_id', $selected)->where('exercise_id', $year['id'])->pluck('amount'));
                $remaining = \App\Domain\Expenses\Decimal::subtract($year['manual_estimates'], $removed);
            @endphp
            <p><strong>{{ $year['year'] }}</strong> · Totale previsto {{ $money($year['allocation']) }} → {{ $money($remaining) }}.
                Costi aggiuntivi mantenuti: {{ $money($remaining) }}; annullati: {{ $money($removed) }}.</p>
        @endif
    @endforeach
    @foreach ($preview['readonly'] ?? [] as $line)
        <p>{{ $line['year'] }} · {{ $line['description'] }} · {{ $money($line['amount']) }}: Esercizio Chiuso, invariato.</p>
    @endforeach
    @if ($lines->contains('can_update', false))
        <p>Non hai il permesso di annullare alcune Righe: puoi mantenerle o selezionare soltanto quelle modificabili.</p>
    @endif
    <p>Annullare una previsione non cancella i costi effettivi già registrati. I Budget approvati e gli anni chiusi restano invariati.</p>
</div>
