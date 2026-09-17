<div class="mp2-budget-source-body mp2-proposal-item-body">
    <dl class="mp2-budget-inline-facts">
        <div><dt>Effettivo (Sola Lettura)</dt><dd>{{ $item['actual'] }}</dd></div>
    </dl>

    @if ($item['has_actuals'])
        <div class="mp2-proposal-read-only-banner">
            <x-filament::icon icon="heroicon-o-eye" aria-hidden="true" />
            <p>Questa sorgente possiede Effettivi. Sono mostrati come contesto e non vengono modificati dalla Proposta.</p>
        </div>
    @endif

    @if ($item['readiness_reasons'] !== [])
        <div class="mp2-proposal-item-messages" role="alert">
            @foreach ($item['readiness_reasons'] as $reason)<p>{{ $reason }}</p>@endforeach
        </div>
    @endif

    <dl class="mp2-budget-source-facts mp2-proposal-source-facts">
        <div><dt>Stato Base</dt><dd>{{ $item['state_before'] }}</dd></div>
        <div><dt>Stato Risultante</dt><dd>{{ $item['state_after'] }}</dd></div>
        <div><dt>Archivio</dt><dd>{{ $item['archive'] }}</dd></div>
    </dl>

    <section class="mp2-budget-source-section" aria-label="Piano Risultante">
        <div class="mp2-budget-subheading">
            <h3>Piano Risultante</h3>
            <span>Dati Leggibili della Sorgente</span>
        </div>

        @if ($item['details']['kind'] === 'expense')
            <dl class="mp2-budget-inline-facts mp2-proposal-result-facts">
                <div><dt>Esercizio</dt><dd>{{ $item['details']['exercise'] }}</dd></div>
                <div><dt>Contenitore</dt><dd>{{ $item['details']['owner'] }}</dd></div>
                <div><dt>Stato</dt><dd>{{ $item['details']['state'] }}</dd></div>
            </dl>
            @if (filled($item['details']['notes']))<p class="mp2-budget-source-description">{{ $item['details']['notes'] }}</p>@endif
            <div class="mp2-proposal-detail-block">
                <h4>Righe Stima Risultanti</h4>
                @include('filament.resources.budgets.components.estimate-lines', ['lines' => $item['details']['lines']])
            </div>
        @endif

        @if ($item['details']['kind'] === 'project')
            <dl class="mp2-budget-inline-facts mp2-proposal-result-facts">
                <div><dt>Stato Iniziale</dt><dd>{{ $item['details']['initial_state'] }}</dd></div>
                <div><dt>Efficacia Iniziale</dt><dd>{{ $item['details']['initial_date'] }}</dd></div>
                <div><dt>Modalità Rinvio</dt><dd>{{ $item['details']['deferral_mode'] }}</dd></div>
                <div><dt>Riporto Risultante</dt><dd>{{ $item['details']['carryover'] }}</dd></div>
                <div><dt>Riprogrammato Risultante</dt><dd>{{ $item['details']['reprogrammed'] }}</dd></div>
            </dl>
            @if (filled($item['details']['description']))<p class="mp2-budget-source-description">{{ $item['details']['description'] }}</p>@endif
            @if ($item['details']['transitions'] !== [])
                <div class="mp2-budget-transition-list">
                    <h4>Transizioni Risultanti</h4>
                    @foreach ($item['details']['transitions'] as $transition)
                        <div>
                            <strong>{{ $transition['from'] }} → {{ $transition['to'] }}</strong>
                            <span>{{ $transition['date'] }} · {{ $transition['status'] }}</span>
                            @if (filled($transition['reason']))<p>{{ $transition['reason'] }}</p>@endif
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="mp2-proposal-child-expenses">
                <h4>Spese Pianificate</h4>
                @forelse ($item['details']['expenses'] as $expense)
                    @include('filament.resources.proposals.components.expense-plan', ['expense' => $expense])
                @empty
                    <p class="mp2-budget-muted">Nessuna Spesa pianificata.</p>
                @endforelse
            </div>
        @endif

        @if ($item['details']['kind'] === 'contract')
            <dl class="mp2-budget-contract-facts">
                <div><dt>Data di Inizio</dt><dd>{{ $item['details']['start_date'] }}</dd></div>
                <div><dt>Prossima Scadenza</dt><dd>{{ $item['details']['expiry_date'] }}</dd></div>
                <div><dt>Rinnovo Automatico</dt><dd>{{ $item['details']['automatic_renewal'] }}</dd></div>
                <div><dt>Durata Rinnovo</dt><dd>{{ $item['details']['renewal_duration'] }}</dd></div>
                <div><dt>Preavviso</dt><dd>{{ $item['details']['notice'] }}</dd></div>
            </dl>

            <div class="mp2-budget-condition-list">
                <h4>Condizioni Economiche Risultanti</h4>
                @forelse ($item['details']['conditions'] as $condition)
                    <article>
                        <div class="mp2-budget-condition-heading">
                            <div><strong>{{ $condition['amount'] }}</strong><span>{{ $condition['cycle'] }} · attribuzione a {{ strtolower($condition['attribution']) }}</span></div>
                            <span class="mp2-object-table-state">{{ $condition['origin'] }} · {{ $condition['status'] }}</span>
                        </div>
                        <dl><div><dt>Valida dal</dt><dd>{{ $condition['valid_from'] }}</dd></div><div><dt>Valida fino al</dt><dd>{{ $condition['valid_to'] }}</dd></div></dl>
                        @if (filled($condition['reason']))<p>{{ $condition['reason'] }}</p>@endif
                    </article>
                @empty
                    <p class="mp2-budget-muted">Nessuna condizione economica risultante.</p>
                @endforelse
            </div>

            @if ($item['details']['condition_changes'] !== [])
                <div class="mp2-proposal-change-list">
                    <h4>Modifiche Economiche Pianificate</h4>
                    @foreach ($item['details']['condition_changes'] as $change)
                        <article>
                            <strong>{{ $change['amount'] }} · {{ $change['cycle'] }}</strong>
                            <span>{{ $change['condition'] }} dal {{ $change['effective_date'] }} · {{ $change['attribution'] }}</span>
                            @if (filled($change['reason']))<p>{{ $change['reason'] }}</p>@endif
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($item['details']['lifecycle'] !== [])
                <div class="mp2-proposal-lifecycle-list">
                    <h4>Ciclo di Vita Risultante</h4>
                    @foreach ($item['details']['lifecycle'] as $fact)
                        <article>
                            <strong>{{ $fact['type'] }}</strong>
                            <span>Data dichiarata {{ $fact['declared_date'] }} · efficacia {{ $fact['effective_date'] }} · {{ $fact['status'] }}</span>
                            @if (filled($fact['reason']))<p>{{ $fact['reason'] }}</p>@endif
                        </article>
                    @endforeach
                </div>
            @endif

            <div class="mp2-proposal-child-expenses">
                <h4>Spese che Compongono l’Allocato</h4>
                @forelse ($item['details']['expenses'] as $expense)
                    @include('filament.resources.proposals.components.expense-plan', ['expense' => $expense])
                @empty
                    <p class="mp2-budget-muted">Nessuna Spesa pianificata.</p>
                @endforelse
            </div>
        @endif
    </section>

    <section class="mp2-budget-source-section" aria-label="Storico Decisioni">
        <div class="mp2-budget-subheading">
            <h3>Storico Decisioni</h3>
            <span>{{ count($item['actions']) }}</span>
        </div>
        <div class="mp2-proposal-action-list">
            @forelse ($item['actions'] as $action)
                <article>
                    <div><span>Sequenza {{ $action['sequence'] }}</span><strong>{{ $action['label'] }}</strong></div>
                    <span class="mp2-object-table-state">{{ $action['status'] }}</span>
                    @if (filled($action['reason']))<p>{{ $action['reason'] }}</p>@endif
                    <small>Registrata da {{ $action['created_by'] }}</small>
                    @if ($action['status'] === 'Ritirata')
                        <small>Ritirata il {{ $action['withdrawn_at'] }} da {{ $action['withdrawn_by'] ?? '—' }}@if (filled($action['withdraw_reason'])) · {{ $action['withdraw_reason'] }}@endif</small>
                    @endif
                </article>
            @empty
                <p class="mp2-budget-muted">Nessuna decisione specifica registrata: il piano risultante coincide con la base acquisita.</p>
            @endforelse
        </div>
    </section>

    <details class="mp2-budget-traceability mp2-proposal-traceability">
        <summary>Riferimenti e Tracciabilità Tecnica</summary>
        <dl>
            <div><dt>OriginKey</dt><dd><code>{{ $item['origin_key'] }}</code></dd></div>
            <div><dt>ProposalItemID</dt><dd><code>{{ $item['proposal_item_id'] }}</code></dd></div>
            <div><dt>Lineage</dt><dd><code>{{ $item['copied_from_origin_key'] ?? 'Sorgente viva' }}</code></dd></div>
            <div><dt>Revisione Base</dt><dd>{{ $item['baseline_revision'] }}</dd></div>
            <div><dt>Ultimo Allineamento</dt><dd>{{ $item['last_aligned_at'] }}</dd></div>
        </dl>
    </details>
</div>
