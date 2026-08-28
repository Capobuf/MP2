<article class="mp2-proposal-expense-plan">
    <div>
        <div>
            <strong>{{ $expense['description'] }}</strong>
            <span>{{ $expense['supplier'] }} · Esercizio {{ $expense['exercise'] }}</span>
        </div>
        <strong>{{ $expense['total'] }}</strong>
    </div>
    @include('filament.resources.budgets.components.estimate-lines', ['lines' => $expense['lines']])
</article>
