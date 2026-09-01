<div class="mp2-object-overview mp2-proposal-overview">
    <div class="mp2-proposal-overview-grid">
        <section class="mp2-object-workspace-panel mp2-proposal-plan-panel" aria-labelledby="proposal-plan-title">
            <div class="mp2-object-section-heading">
                <span class="mp2-object-section-icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-scale" />
                </span>
                <div>
                    <p class="mp2-object-eyebrow">Piano Proposto</p>
                    <h2 id="proposal-plan-title">Confronto dell’Allocato</h2>
                </div>
            </div>

            <div class="mp2-proposal-allocation-flow">
                <div>
                    <span>Allocato Base</span>
                    <strong>{{ $overview['proposal']['allocation_before'] }}</strong>
                </div>
                <span class="mp2-proposal-flow-arrow" aria-hidden="true">
                    <x-filament::icon icon="heroicon-m-arrow-right" />
                </span>
                <div>
                    <span>Allocato Risultante</span>
                    <strong>{{ $overview['proposal']['allocation_after'] }}</strong>
                </div>
            </div>

            <div class="mp2-proposal-plan-footer">
                <div>
                    <span>Variazione</span>
                    <strong class="mp2-proposal-delta-{{ $overview['proposal']['allocation_delta_tone'] }}">
                        {{ $overview['proposal']['allocation_delta'] }}
                    </strong>
                </div>
                <div>
                    <span>Effettivo · Sola Lettura</span>
                    <strong>{{ $overview['proposal']['actual'] }}</strong>
                </div>
            </div>

            <p class="mp2-proposal-read-only-note">
                Realtà effettiva in sola lettura: gli Effettivi non sono decisioni di piano.
            </p>
        </section>

        <section class="mp2-object-workspace-panel mp2-proposal-context-panel" aria-labelledby="proposal-context-title">
            <div class="mp2-object-section-heading">
                <span class="mp2-object-section-icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-clipboard-document-check" />
                </span>
                <div>
                    <p class="mp2-object-eyebrow">Contesto e Verifica</p>
                    <h2 id="proposal-context-title">Stato della Proposta</h2>
                </div>
            </div>

            <dl class="mp2-proposal-context-facts">
                <div><dt>Esercizio</dt><dd>{{ $overview['proposal']['exercise'] }}</dd></div>
                <div><dt>Finalità</dt><dd>{{ $overview['proposal']['purpose'] }}</dd></div>
                <div><dt>Stato</dt><dd>{{ $overview['proposal']['status'] }}</dd></div>
                <div><dt>Budget di Riferimento</dt><dd>{{ $overview['proposal']['reference_budget'] }}</dd></div>
                <div><dt>Creata da</dt><dd>{{ $overview['proposal']['created_by'] }}</dd></div>
                <div><dt>Creata il</dt><dd>{{ $overview['proposal']['created_at'] }}</dd></div>
                @if ($overview['proposal']['terminal_by'] !== null)
                    <div><dt>{{ $overview['proposal']['status_value'] === 'approved' ? 'Approvata da' : 'Scartata da' }}</dt><dd>{{ $overview['proposal']['terminal_by'] }}</dd></div>
                    <div><dt>{{ $overview['proposal']['status_value'] === 'approved' ? 'Approvata il' : 'Scartata il' }}</dt><dd>{{ $overview['proposal']['terminal_at'] }}</dd></div>
                @endif
            </dl>

            <div @class([
                'mp2-proposal-verification',
                'mp2-proposal-verification-'.$overview['verification']['tone'],
            ])>
                <x-filament::icon :icon="$overview['verification']['icon']" aria-hidden="true" />
                <div>
                    <strong>{{ $overview['verification']['label'] }}</strong>
                    @if ($overview['verification']['blocks'] === [])
                        <p>{{ $overview['verification']['message'] }}</p>
                    @else
                        <ul>
                            @foreach ($overview['verification']['blocks'] as $block)<li>{{ $block }}</li>@endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <p class="mp2-proposal-context-copy">{{ $overview['proposal']['context'] }}</p>
        </section>
    </div>

    <section class="mp2-object-annual-section mp2-proposal-items" aria-labelledby="proposal-items-title">
        <div class="mp2-object-annual-heading mp2-proposal-items-heading">
            <div>
                <p class="mp2-object-eyebrow">Perimetro del Piano</p>
                <h2 id="proposal-items-title">Sorgenti Incluse</h2>
            </div>
            <p>
                {{ $overview['proposal']['item_count'] }}
                {{ $overview['proposal']['item_count'] === 1 ? 'sorgente governata' : 'sorgenti governate' }} dalla Proposta.
            </p>
        </div>

        @if ($overview['source_counts'] !== [])
            <dl class="mp2-budget-source-counts" aria-label="Composizione per Tipo">
                @foreach ($overview['source_counts'] as $count)
                    <div><dt>{{ $count['label'] }}</dt><dd>{{ $count['count'] }}</dd></div>
                @endforeach
            </dl>
        @endif

        <div class="mp2-budget-source-list mp2-proposal-item-list">
            @forelse ($overview['items'] as $item)
                <details class="mp2-budget-source mp2-proposal-item">
                    <summary>
                        <span class="mp2-budget-source-icon" aria-hidden="true">
                            <x-filament::icon :icon="$item['icon']" />
                        </span>

                        <span class="mp2-budget-source-identity">
                            <span class="mp2-budget-source-title-line">
                                <strong>{{ $item['label'] }}</strong>
                                <span class="mp2-budget-source-type">{{ $item['type_label'] }}</span>
                            </span>
                            <span class="mp2-budget-source-context">
                                {{ $item['cost_center'] }}
                                @if ($item['supplier'] !== '—')<span aria-hidden="true">·</span> {{ $item['supplier'] }}@endif
                                <span aria-hidden="true">·</span> <code>{{ $item['origin_key'] }}</code>
                            </span>
                        </span>

                        <span class="mp2-proposal-item-readiness">
                            <small>Verifica</small>
                            <strong data-state="{{ $item['readiness_value'] }}">{{ $item['readiness'] }}</strong>
                        </span>

                        <span class="mp2-proposal-item-allocation">
                            <small>Allocato Base → Risultante</small>
                            <strong>{{ $item['allocation_before'] }} → {{ $item['allocation_after'] }}</strong>
                            <span class="mp2-proposal-delta-{{ $item['allocation_delta_tone'] }}">{{ $item['allocation_delta'] }}</span>
                        </span>

                        <span class="mp2-budget-source-chevron" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-chevron-down" />
                        </span>
                    </summary>

                    <div class="mp2-budget-source-body mp2-proposal-item-body">
                        <dl class="mp2-proposal-item-kpis">
                            <div><dt>Allocato Base</dt><dd>{{ $item['allocation_before'] }}</dd></div>
                            <div><dt>Allocato Risultante</dt><dd>{{ $item['allocation_after'] }}</dd></div>
                            <div><dt>Variazione</dt><dd class="mp2-proposal-delta-{{ $item['allocation_delta_tone'] }}">{{ $item['allocation_delta'] }}</dd></div>
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
                            <div><dt>Centro di Costo</dt><dd>{{ $item['cost_center'] }}</dd></div>
                            <div><dt>Fornitore</dt><dd>{{ $item['supplier'] }}</dd></div>
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
                </details>
            @empty
                <div class="mp2-object-empty-state mp2-budget-source-empty">
                    <x-filament::icon icon="heroicon-o-inbox" aria-hidden="true" />
                    <div>
                        <p>Nessuna sorgente inclusa nella Proposta.</p>
                        <p class="mp2-proposal-empty-history"><strong>Storico Decisioni</strong> · Nessuna decisione specifica registrata.</p>
                    </div>
                </div>
            @endforelse
        </div>
    </section>

    <section class="mp2-object-annual-section mp2-proposal-impacts" aria-labelledby="proposal-impacts-title">
        <div class="mp2-object-annual-heading">
            <div>
                <p class="mp2-object-eyebrow">Verifiche e Impatti</p>
                <h2 id="proposal-impacts-title">Esercizi Interessati</h2>
            </div>
            <p>Confronto fra realtà di base e piano risultante; i Budget già approvati restano invariati.</p>
        </div>

        <div class="mp2-proposal-impact-list">
            @foreach ($overview['impacts'] as $impact)
                <article class="mp2-proposal-impact">
                    <div class="mp2-proposal-impact-heading">
                        <div><span>Esercizio</span><strong>{{ $impact['year'] }}</strong></div>
                        <span class="mp2-object-table-state">{{ $impact['application'] }}</span>
                    </div>
                    <dl class="mp2-proposal-impact-values">
                        <div><dt>Allocato Base</dt><dd>{{ $impact['before'] }}</dd></div>
                        <div><dt>Allocato Risultante</dt><dd>{{ $impact['after'] }}</dd></div>
                        <div><dt>Variazione</dt><dd class="mp2-proposal-delta-{{ $impact['delta_tone'] }}">{{ $impact['delta'] }}</dd></div>
                    </dl>

                    <details class="mp2-budget-nested-details">
                        <summary>Sorgenti Interessate · {{ count($impact['sources']) }}</summary>
                        <div class="mp2-proposal-impact-sources">
                            @foreach ($impact['sources'] as $source)
                                <div>
                                    <span><strong>{{ $source['label'] }}</strong><small>{{ $source['type'] }} · {{ $source['state_before'] }} → {{ $source['state_after'] }}</small></span>
                                    <span>{{ $source['before'] }} → {{ $source['after'] }} <strong>{{ $source['delta'] }}</strong></span>
                                </div>
                            @endforeach
                        </div>
                    </details>

                    <div class="mp2-proposal-impact-notes">
                        <div><span>Budget che Restano Invariati</span><strong>{{ $impact['unchanged_budgets'] === [] ? 'Nessuno' : implode(', ', $impact['unchanged_budgets']) }}</strong></div>
                        <div><span>Altre Proposte da Riallineare</span><strong>{{ $impact['stale_proposals'] === [] ? 'Nessuna' : implode(', ', $impact['stale_proposals']) }}</strong></div>
                    </div>

                    @foreach ($impact['blocks'] as $block)<p class="mp2-proposal-impact-block">{{ $block }}</p>@endforeach
                    @foreach ($impact['warnings'] as $warning)<p class="mp2-proposal-impact-warning">{{ $warning }}</p>@endforeach
                </article>
            @endforeach
        </div>
    </section>
</div>
