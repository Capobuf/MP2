<div class="mp2-proposal-realignment-summary">
    <div class="mp2-budget-subheading">
        <h3>Revisione della sorgente</h3>
        <span>Base {{ $summary['baseline_revision'] }} → corrente {{ $summary['current_revision'] }}</span>
    </div>

    @if ($summary['choice'] === 'reload')
        <p>La realtà corrente sostituirà integralmente la base e il risultato della Proposta. Tutte le decisioni elencate saranno ritirate senza riscriverne lo storico.</p>
        <h4>Decisioni da ritirare</h4>
    @elseif ($summary['choice'] === 'keep')
        <p>La realtà corrente diventerà la nuova base. Tutte le decisioni elencate saranno rivalidate e riapplicate.</p>
        <h4>Decisioni da mantenere</h4>
    @else
        <p>Selezionare sotto le decisioni da mantenere. Le decisioni non selezionate saranno ritirate senza riscriverne lo storico.</p>
        <h4>Decisioni da rivedere</h4>
    @endif

    @if ($summary['actions'] === [])
        <p>Nessuna decisione attiva interessa questa sorgente.</p>
    @else
        <ul>
            @foreach ($summary['actions'] as $action)
                <li>#{{ $action['sequence'] }} · {{ $action['label'] }}</li>
            @endforeach
        </ul>
    @endif
</div>
