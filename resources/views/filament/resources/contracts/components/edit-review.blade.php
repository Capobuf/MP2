@if ($review)
    @php
        $date = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->format('d/m/Y') : 'Non definita';
        $money = fn ($value) => \Illuminate\Support\Number::currency((float) $value, 'EUR', locale: 'it');
        $hierarchy = \App\Domain\CostCenters\CostCenterHierarchy::forCompany($contract->company_id);
        $center = fn ($id) => $id === null ? 'Non classificato' : $hierarchy->path($id);
        $plan = $review['plan'];
    @endphp
    <div class="space-y-4">
        @if ($review['changes']['details'])
            <section>
                <h3 class="font-semibold">Dati Principali</h3>
                @foreach ($review['changes']['details'] as $field => $value)
                    <p>{{ ['title' => 'Titolo', 'notes' => 'Note', 'supplier_id' => 'Fornitore'][$field] }}:
                        @if ($field === 'supplier_id')
                            {{ $contract->supplier->legal_name }} → {{ \App\Models\Supplier::query()->where('company_id', $contract->company_id)->find($value)?->legal_name }}
                        @else
                            {{ $original[$field] ?: '—' }} → {{ $value ?: '—' }}
                        @endif
                    </p>
                @endforeach
            </section>
        @endif
        @if (in_array($review['kind'], ['change', 'correction'], true))
            <section class="space-y-2">
                <h3 class="font-semibold">Condizioni Economiche · {{ $review['kind'] === 'change' ? 'L’accordo è cambiato' : 'Correzione del dato precedente' }}</h3>
                <p>{{ $money($plan['oldTerms']['amount']) }} → {{ $money($plan['newTerms']['amount']) }}</p>
                <p>Frequenza: {{ \App\Domain\Contracts\ContractCycleType::from($plan['oldTerms']['cycle'])->label() }} → {{ \App\Domain\Contracts\ContractCycleType::from($plan['newTerms']['cycle'])->label() }}</p>
                <p>Attribuzione: {{ \App\Domain\Contracts\ContractAttributionMode::from($plan['oldTerms']['attribution_mode'])->label() }} → {{ \App\Domain\Contracts\ContractAttributionMode::from($plan['newTerms']['attribution_mode'])->label() }}</p>
                @if ($review['kind'] === 'change')
                    <p>Data richiesta: {{ $date($plan['requestedDate']) }} · Data minima: {{ $date($plan['minimumDate']) }}</p>
                @endif
                <p><strong>Decorrenza effettiva: {{ $date($plan['effectiveDate']) }}</strong></p>
                <p>{{ $plan['delayReason'] }}</p>
                <p>Prorata applicato: no</p>
                @foreach ($plan['exerciseImpacts'] as $impact)
                    <p>Impatto {{ $impact['year'] }}: {{ $money($impact['allocation_before']) }} → {{ $money($impact['allocation_after']) }} ({{ $money($impact['allocation_delta']) }})</p>
                @endforeach
            </section>
        @elseif ($review['kind'] === 'classification')
            @foreach ($review['plan'] as $plan)
                <section class="space-y-2">
                    <h3 class="font-semibold">Centro di Costo · {{ collect($review['exercises'])->firstWhere('id', $plan['exerciseId'])['year'] }}</h3>
                    <p>{{ $center($plan['oldCostCenterId']) }} → {{ $center($plan['newCostCenterId']) }}</p>
                    <p>L’intero Esercizio viene riclassificato, compresi gli Effettivi.</p>
                    <p>Allocato: {{ $money($plan['allocation']) }} · Effettivo: {{ $money($plan['actual']) }}</p>
                    <p>Spese interessate: {{ count($plan['expenseIds']) }}</p>
                    @foreach ($contract->expenses()->whereIn('id', $plan['expenseIds'])->get() as $expense)
                        <p>{{ $expense->description }}</p>
                    @endforeach
                </section>
            @endforeach
        @elseif ($review['kind'] === 'renewal')
            <section class="space-y-2">
                <h3 class="font-semibold">Termini Contrattuali</h3>
                <p>Efficaci dal: {{ $date($review['input']['effective_from']) }}</p>
                <p>Scadenza: {{ $date($original['next_expiry_date']) }} → {{ $date($review['input']['expiry_anchor_date']) }}</p>
                <p>Rinnovo automatico: {{ $original['automatic_renewal'] ? 'Sì' : 'No' }} → {{ $review['input']['automatic_renewal'] ? 'Sì' : 'No' }}</p>
                <p>Durata del rinnovo: {{ $original['renewal_duration_months'] ?? '—' }} → {{ $review['input']['renewal_duration_months'] ?? '—' }} mesi</p>
                <p>Preavviso: {{ $original['notice_days'] ?? '—' }} → {{ $review['input']['notice_days'] ?? '—' }} giorni</p>
                <p>Esercizi Aperti interessati: {{ collect($review['exercises'])->where('open', true)->pluck('year')->implode(', ') ?: 'Nessuno' }}</p>
                @foreach ($plan as $impact)
                    <p>Impatto {{ $impact['year'] }}: {{ $money($impact['allocation_before']) }} → {{ $money($impact['allocation_after']) }} ({{ $money($impact['allocation_delta']) }})</p>
                @endforeach
                <p>Le scadenze già materializzate non vengono riscritte.</p>
            </section>
        @endif
        <p>Budget approvati e Snapshot restano invariati. Le Proposte coinvolte saranno da riallineare. Gli Esercizi Chiusi non vengono ricalcolati.</p>
    </div>
@endif
