<x-mp2.object-header
    icon="heroicon-o-banknotes"
    icon-kind="expense"
    index-label="Spese"
    :index-url="$expensesUrl"
    :title="$expense->description"
>
    <x-slot:meta>
        <span>Spesa #{{ $expense->id }}</span>
        <span class="mp2-object-meta-separator" aria-hidden="true"></span>
        <span>Esercizio <strong>{{ $expense->exercise->year }}</strong></span>
        <span class="mp2-object-meta-separator" aria-hidden="true"></span>
        <span class="mp2-object-state-badge {{ $expense->isReversed() ? 'mp2-object-state-neutral' : 'mp2-object-state-success' }}">
            {{ $expense->isReversed() ? 'Stornata' : 'Attiva' }}
        </span>
        @if ($reference !== null)
            <span class="mp2-object-meta-separator" aria-hidden="true"></span>
            <a
                href="{{ $reference['url'] }}"
                class="mp2-expense-reference-link"
                aria-label="Apri {{ $reference['label'] }}: {{ $reference['title'] }}"
            >
                <x-filament::icon :icon="$reference['icon']" aria-hidden="true" />
                <span class="mp2-expense-reference-copy">
                    <span>{{ $reference['label'] }}</span>
                    <strong>{{ $reference['title'] }}</strong>
                </span>
                <x-filament::icon icon="heroicon-m-arrow-up-right" class="mp2-expense-reference-arrow" aria-hidden="true" />
            </a>
        @endif
    </x-slot:meta>

    <x-slot:actions>
        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
    </x-slot:actions>

    <dl class="mp2-object-highlights" aria-label="Riepilogo Economico">
        <div>
            <dt>Stima</dt>
            <dd>{{ $money['allocation'] }}</dd>
        </div>
        <div>
            <dt>Effettivo</dt>
            <dd>{{ $money['actual'] }}</dd>
        </div>
        <div>
            <dt>Scostamento</dt>
            <dd>{{ $money['variance'] }}</dd>
        </div>
    </dl>
</x-mp2.object-header>
