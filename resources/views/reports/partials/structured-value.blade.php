@php
    $detailLabels = [
        'expenses' => 'Spese', 'lines' => 'Righe', 'source' => 'Descrizione', 'description' => 'Descrizione',
        'supplier_label' => 'Fornitore', 'allocation' => 'Allocato', 'actual' => 'Effettivo', 'amount' => 'Importo',
        'operational_variance' => 'Scostamento Operativo', 'carryover' => 'Riporto', 'residual' => 'Residuo',
        'saving' => 'Risparmio', 'unused_allocation' => 'Allocato non utilizzato', 'consolidated_carryover' => 'Riporto consolidato',
        'reason' => 'Motivo', 'note' => 'Nota', 'name' => 'Nome', 'attachments' => 'Allegati', 'type' => 'Tipo',
        'has_actuals' => 'Ha Effettivi', 'annulled' => 'Annullata', 'annulled_at' => 'Annullata il',
        'transitions' => 'Transizioni', 'transitions_in_exercise' => 'Transizioni', 'deferrals' => 'Rinvii',
        'from_state' => 'Stato iniziale', 'to_state' => 'Stato finale', 'effective_date' => 'Data efficace',
        'mode' => 'Modalità', 'carryover_amount' => 'Riporto', 'reprogrammed_amount' => 'Importo Riprogrammato',
        'source_exercise_id' => 'Esercizio origine (ID)', 'destination_exercise_id' => 'Esercizio destinazione (ID)',
        'conditions' => 'Condizioni economiche', 'cycles' => 'Configurazioni di rinnovo', 'events' => 'Eventi contrattuali',
        'cycle' => 'Ciclo', 'attribution_mode' => 'Attribuzione', 'valid_from' => 'Valida dal', 'valid_to' => 'Valida fino al',
        'deadline' => 'Scadenza', 'automatic_renewal' => 'Rinnovo automatico', 'notice_days' => 'Preavviso (giorni)',
        'notice_limit_date' => 'Limite preavviso', 'renewal_duration_months' => 'Durata rinnovo (mesi)',
        'effective_from' => 'Decorrenza', 'expiry_anchor_date' => 'Scadenza di riferimento',
        'state_change_date' => 'Data cambio stato', 'declared_contractual_date' => 'Data contrattuale dichiarata',
        'renewed_expiry_date' => 'Scadenza rinnovata', 'created_at' => 'Registrato il', 'source_label' => 'Sorgente',
        'economic_impact' => 'Impatto economico', 'affected_sources' => 'Sorgenti interessate', 'label' => 'Sorgente',
        'deferred' => 'Presenza di rinvio', 'archived_or_reversed' => 'Archivio o Storno',
    ];
    $detailMoneyKeys = ['allocation', 'actual', 'amount', 'operational_variance', 'carryover', 'residual', 'saving', 'unused_allocation', 'consolidated_carryover', 'carryover_amount', 'reprogrammed_amount', 'economic_impact'];
@endphp
@if (is_array($value))
    @php
        $visible = array_filter($value, static fn (mixed $item, int|string $key): bool => ! in_array($key, [
            'id', 'company_id', 'project_id', 'contract_id', 'expense_id', 'supplier_id', 'cost_center_id',
            'created_by_id', 'updated_by_id', 'annulled_by_id', 'origin_id', 'origin_key', 'copied_from_origin_key',
            'updated_at',
        ], true) && $item !== [] && $item !== null && $item !== '', ARRAY_FILTER_USE_BOTH);
    @endphp
    @if ($visible !== [])
        <ul class="structured">
            @foreach ($visible as $key => $item)
                <li>
                    @if (! is_int($key))<strong>{{ $detailLabels[$key] ?? $key }}:</strong>@endif
                    @if (in_array($key, $detailMoneyKeys, true) && is_numeric($item))
                        {{ \Illuminate\Support\Number::currency((float) $item, in: 'EUR', locale: 'it') }}
                    @elseif (in_array($key, ['from_state', 'to_state'], true) && is_string($item))
                        {{ \App\Domain\Projects\ProjectState::tryFrom($item)?->label() ?? $item }}
                    @elseif ($key === 'type' && is_string($item) && in_array($item, ['estimate', 'actual'], true))
                        {{ \App\Domain\Expenses\ExpenseLineType::from($item)->label() }}
                    @elseif ($key === 'mode' && is_string($item))
                        {{ \App\Domain\Projects\ProjectDeferralMode::tryFrom($item)?->label() ?? $item }}
                    @elseif ($key === 'cycle' && is_string($item))
                        {{ \App\Domain\Contracts\ContractCycleType::tryFrom($item)?->label() ?? $item }}
                    @elseif ($key === 'attribution_mode' && is_string($item))
                        {{ \App\Domain\Contracts\ContractAttributionMode::tryFrom($item)?->label() ?? $item }}
                    @else
                        @include('reports.partials.structured-value', ['value' => $item])
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
@elseif (is_bool($value))
    {{ $value ? 'Sì' : 'No' }}
@else
    {{ $value }}
@endif
