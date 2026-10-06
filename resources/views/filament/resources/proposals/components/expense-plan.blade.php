<article class="mp2-proposal-expense-plan">
    <div>
        <div>
            <strong>{{ $expense['description'] }}</strong>
            <span>{{ $expense['supplier'] }} · Esercizio {{ $expense['exercise'] }}</span>
            @if (isset($expense['status']))<span>{{ $expense['status'] }}</span>@endif
        </div>
        <strong>{{ $expense['total'] }}</strong>
    </div>
    @include('filament.resources.budgets.components.estimate-lines', ['lines' => $expense['lines']])
</article>
