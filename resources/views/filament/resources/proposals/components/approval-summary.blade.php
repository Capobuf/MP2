<div class="mp2-proposal-approval-summary">
    <p>La conferma rivalida e applica atomicamente il piano descritto di seguito.</p>
    <div class="mp2-budget-subheading">
        <h3>Budget {{ $summary['version'] }} da creare</h3>
        <span>Gli Effettivi restano invariati</span>
    </div>

    @foreach ($summary['impacts'] as $impact)
        <section class="mp2-proposal-impact">
            <div class="mp2-proposal-impact-heading">
                <div><span>Esercizio</span><strong>{{ $impact['year'] }}</strong></div>
                <span class="mp2-object-table-state">{{ $impact['application'] }}</span>
            </div>
            <dl class="mp2-proposal-impact-values">
                <div><dt>Allocato prima della Proposta</dt><dd>{{ $impact['before'] }}</dd></div>
                <div><dt>Piano proposto</dt><dd>{{ $impact['after'] }}</dd></div>
                <div><dt>Variazione</dt><dd>{{ $impact['delta'] }}</dd></div>
            </dl>
            <p>Budget già approvati invariati: {{ $impact['unchanged_budgets'] === [] ? 'nessuno presente' : implode(', ', $impact['unchanged_budgets']) }}.</p>
            @foreach ($impact['warnings'] as $warning)<p class="mp2-proposal-impact-warning">Avviso: {{ $warning }}</p>@endforeach
            @foreach ($impact['blocks'] as $block)<p class="mp2-proposal-impact-block">Blocco: {{ $block }}</p>@endforeach
        </section>
    @endforeach

    @if ($summary['blocks'] !== [])
        <div class="mp2-proposal-item-messages" role="alert">
            @foreach ($summary['blocks'] as $block)<p>{{ $block }}</p>@endforeach
        </div>
    @endif
</div>
