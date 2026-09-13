<div class="space-y-3 text-sm">
    @if ($error)
        <p class="text-danger-600 dark:text-danger-400">{{ $error }}</p>
    @elseif ($plan)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <p><strong>Prima:</strong> {{ $plan->oldPath }}</p>
            <p><strong>Dopo:</strong> {{ $plan->newPath }}</p>
        </div>

        @forelse ($plan->exerciseImpacts as $impact)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <p class="font-medium">Esercizio {{ $impact['exercise_year'] }} · {{ $impact['source_count'] }} sorgenti</p>
                <p>Dal ramo: {{ $impact['old_branch'] }}</p>
                <p>Al ramo: {{ $impact['new_branch'] }}</p>
                <p>Allocato aggregato: {{ \Illuminate\Support\Number::currency((float) $impact['allocation_moved'], in: 'EUR', locale: 'it') }}</p>
                <p>Effettivo aggregato: {{ \Illuminate\Support\Number::currency((float) $impact['actual_moved'], in: 'EUR', locale: 'it') }}</p>
                <p>Riporto aggregato: {{ \Illuminate\Support\Number::currency((float) $impact['carryover_moved'], in: 'EUR', locale: 'it') }}</p>
            </div>
        @empty
            <p>Nessun Esercizio Aperto contiene sorgenti classificate nel ramo. Budget, Chiusure e importi restano invariati.</p>
        @endforelse
    @endif
</div>
