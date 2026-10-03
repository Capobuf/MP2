@if ($error !== null)
    <div class="mp2-proposal-item-messages" role="alert"><p>{{ $error }}</p></div>
@else
    <div class="mp2-proposal-deferral-summary">
        <div class="mp2-budget-subheading">
            <h3>Esercizio {{ $summary['source_year'] }} → {{ $summary['destination_year'] }}</h3>
            <span>{{ $summary['live_mode'] }} → {{ $summary['proposed_mode'] }}</span>
        </div>
        <dl class="mp2-proposal-impact-values">
            <div><dt>Allocato origine</dt><dd>{{ $summary['allocation'] }}</dd></div>
            <div><dt>Effettivo</dt><dd>{{ $summary['actual'] }}</dd></div>
            <div><dt>Residuo</dt><dd>{{ $summary['residual'] }}</dd></div>
            <div><dt>Disponibilità massima</dt><dd>{{ $summary['maximum'] }}</dd></div>
            <div><dt>Stime riducibili</dt><dd>{{ $summary['reducible'] }}</dd></div>
            <div><dt>Riduzioni selezionate</dt><dd>{{ $summary['selected'] }}</dd></div>
        </dl>

        @if ($summary['reprogramming_balance'] !== null)
            <p><strong>Bilanciamento Riprogrammazione:</strong> riduzione origine {{ $summary['reprogramming_balance'] }} = incremento destinazione {{ $summary['reprogramming_balance'] }}.</p>
        @elseif ($summary['proposed_mode'] === 'Riporto')
            <p>Le Stime dell’origine restano invariate; il Riporto viene applicato alla destinazione.</p>
        @else
            <p>Nessun importo viene trasferito tra i due Esercizi.</p>
        @endif

        <p>Le allocazioni indipendenti e i Budget già approvati restano invariati.</p>
        @foreach ($summary['blocks'] as $block)<p class="mp2-proposal-impact-block">{{ $block }}</p>@endforeach
    </div>
@endif
