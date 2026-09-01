<div class="mp2-object-overview mp2-budget-overview">
    <div class="mp2-budget-overview-grid">
        <section class="mp2-object-workspace-panel mp2-budget-total-panel" aria-labelledby="budget-total-title">
            <div class="mp2-object-section-heading">
                <span class="mp2-object-section-icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-lock-closed" />
                </span>
                <div>
                    <p class="mp2-object-eyebrow">Budget Immutabile</p>
                    <h2 id="budget-total-title">Versione Approvata</h2>
                </div>
            </div>

            <div class="mp2-budget-total">
                <span>Allocato Approvato</span>
                <strong>{{ $overview['budget']['total'] }}</strong>
                <p>Piano formalmente approvato per l’Esercizio {{ $overview['budget']['exercise'] }}.</p>
            </div>

            <dl class="mp2-budget-version-facts">
                <div>
                    <dt>Versione</dt>
                    <dd>{{ $overview['budget']['version'] }}</dd>
                </div>
                <div>
                    <dt>Precedente</dt>
                    <dd>{{ $overview['budget']['previous_version'] }}</dd>
                </div>
                <div>
                    <dt>Finalità</dt>
                    <dd>{{ $overview['budget']['purpose'] }}</dd>
                </div>
            </dl>
        </section>

        <section class="mp2-object-workspace-panel mp2-budget-approval-panel" aria-labelledby="budget-approval-title">
            <div class="mp2-object-section-heading">
                <span class="mp2-object-section-icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-check-badge" />
                </span>
                <div>
                    <p class="mp2-object-eyebrow">Tracciabilità</p>
                    <h2 id="budget-approval-title">Approvazione</h2>
                </div>
            </div>

            <dl class="mp2-budget-approval-facts">
                <div>
                    <dt>Data e Ora</dt>
                    <dd>{{ $overview['budget']['approved_at'] }}</dd>
                </div>
                <div>
                    <dt>Approvato da</dt>
                    <dd>{{ $overview['budget']['approver'] }}</dd>
                </div>
                <div>
                    <dt>Proposta di Origine</dt>
                    <dd>{{ $overview['budget']['proposal'] }}</dd>
                </div>
                <div>
                    <dt>Azienda</dt>
                    <dd>{{ $overview['budget']['company'] }}</dd>
                </div>
            </dl>

            <div class="mp2-budget-affected-exercises">
                <span>Esercizi Interessati Contestualmente</span>
                @if ($overview['budget']['affected_exercises'] !== [])
                    <div>
                        @foreach ($overview['budget']['affected_exercises'] as $year)
                            <span>{{ $year }}</span>
                        @endforeach
                    </div>
                @else
                    <strong>—</strong>
                @endif
            </div>
        </section>
    </div>

    <section class="mp2-object-annual-section mp2-budget-sources" aria-labelledby="budget-sources-title">
        <div class="mp2-object-annual-heading mp2-budget-sources-heading">
            <div>
                <p class="mp2-object-eyebrow">Piano Approvato</p>
                <h2 id="budget-sources-title">Sorgenti Materializzate</h2>
            </div>
            <p>
                {{ $overview['budget']['source_count'] }}
                {{ $overview['budget']['source_count'] === 1 ? 'Sorgente Inclusa' : 'Sorgenti Incluse' }}
                in {{ $overview['budget']['version'] }}.
            </p>
        </div>

        @if ($overview['source_counts'] !== [])
            <dl class="mp2-budget-source-counts" aria-label="Composizione per Tipo">
                @foreach ($overview['source_counts'] as $count)
                    <div>
                        <dt>{{ $count['label'] }}</dt>
                        <dd>{{ $count['count'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        <div class="mp2-budget-source-list">
            @forelse ($overview['sources'] as $source)
                <details class="mp2-budget-source">
                    <summary>
                        <span class="mp2-budget-source-icon" aria-hidden="true">
                            <x-filament::icon :icon="$source['icon']" />
                        </span>

                        <span class="mp2-budget-source-identity">
                            <span class="mp2-budget-source-title-line">
                                <strong>{{ $source['label'] }}</strong>
                                <span class="mp2-budget-source-type">{{ $source['type_label'] }}</span>
                            </span>
                            <span class="mp2-budget-source-context">
                                {{ $source['cost_center'] }}
                                @if ($source['supplier'] !== null)
                                    <span aria-hidden="true">·</span> {{ $source['supplier'] }}
                                @endif
                                <span aria-hidden="true">·</span>
                                <code>{{ $source['origin_key'] }}</code>
                            </span>
                        </span>

                        <span class="mp2-budget-source-state">
                            <small>Stato</small>
                            <strong>{{ $source['start_state'] }} → {{ $source['end_state'] }}</strong>
                        </span>

                        <span class="mp2-budget-source-amount">
                            <small>Allocato Approvato</small>
                            <strong>{{ $source['allocation'] }}</strong>
                        </span>

                        <span class="mp2-budget-source-chevron" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-chevron-down" />
                        </span>
                    </summary>

                    <div class="mp2-budget-source-body">
                        @if (filled($source['summary']))
                            <p class="mp2-budget-source-description">{{ $source['summary'] }}</p>
                        @endif

                        <dl class="mp2-budget-source-facts">
                            <div>
                                <dt>Centro di Costo</dt>
                                <dd>{{ $source['cost_center'] }}</dd>
                            </div>
                            <div>
                                <dt>Fornitore</dt>
                                <dd>{{ $source['supplier'] ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt>Stime Approvate</dt>
                                <dd>{{ $source['estimates'] }}</dd>
                            </div>
                            @if ($source['type_value'] === 'project')
                                <div>
                                    <dt>Riporto Approvato</dt>
                                    <dd>{{ $source['carryover'] }}</dd>
                                </div>
                                <div>
                                    <dt>Stato Riporto</dt>
                                    <dd>{{ $source['carryover_state'] }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt>Stato al 1° Gennaio</dt>
                                <dd>{{ $source['start_state'] }}</dd>
                            </div>
                            <div>
                                <dt>Stato al 31 Dicembre</dt>
                                <dd>{{ $source['end_state'] }}</dd>
                            </div>
                        </dl>

                        @if ($source['expense'] !== null)
                            <section class="mp2-budget-source-section" aria-label="Dettaglio Spesa">
                                <div class="mp2-budget-subheading">
                                    <h3>Dettaglio Spesa</h3>
                                    <span>{{ count($source['expense']['lines']) }} {{ count($source['expense']['lines']) === 1 ? 'Riga Stima Attiva' : 'Righe Stima Attive' }}</span>
                                </div>
                                <dl class="mp2-budget-inline-facts">
                                    <div><dt>Origine</dt><dd>{{ $source['expense']['origin'] }}</dd></div>
                                    <div><dt>Contenitore</dt><dd>{{ $source['expense']['owner'] }}</dd></div>
                                    <div><dt>Stato</dt><dd>{{ $source['expense']['state'] }}</dd></div>
                                </dl>
                                @include('filament.resources.budgets.components.estimate-lines', ['lines' => $source['expense']['lines']])
                            </section>
                        @endif

                        @if ($source['project'] !== null)
                            <section class="mp2-budget-source-section" aria-label="Dettaglio Progetto">
                                <div class="mp2-budget-subheading">
                                    <h3>Dettaglio Progetto</h3>
                                    <span>{{ count($source['project']['expenses']) }} {{ count($source['project']['expenses']) === 1 ? 'Spesa Figlia' : 'Spese Figlie' }}</span>
                                </div>
                                <dl class="mp2-budget-inline-facts">
                                    <div><dt>Modalità Rinvio</dt><dd>{{ $source['project']['deferral_mode'] }}</dd></div>
                                    <div><dt>Riprogrammato Approvato</dt><dd>{{ $source['project']['reprogrammed'] }}</dd></div>
                                </dl>

                                @if ($source['project']['transitions'] !== [])
                                    <div class="mp2-budget-transition-list">
                                        <h4>Transizioni Approvate</h4>
                                        @foreach ($source['project']['transitions'] as $transition)
                                            <div>
                                                <strong>{{ $transition['from'] }} → {{ $transition['to'] }}</strong>
                                                <span>{{ $transition['date'] }}</span>
                                                @if (filled($transition['reason']))<p>{{ $transition['reason'] }}</p>@endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="mp2-budget-child-expenses">
                                    @forelse ($source['project']['expenses'] as $expense)
                                        <article>
                                            <div>
                                                <strong>{{ $expense['description'] }}</strong>
                                                <span>{{ $expense['supplier'] }}</span>
                                            </div>
                                            <strong>{{ $expense['total'] }}</strong>
                                            @include('filament.resources.budgets.components.estimate-lines', ['lines' => $expense['lines']])
                                        </article>
                                    @empty
                                        <p class="mp2-budget-muted">Nessuna Spesa figlia materializzata.</p>
                                    @endforelse
                                </div>
                            </section>
                        @endif

                        @if ($source['contract'] !== null)
                            <section class="mp2-budget-source-section" aria-label="Dettaglio Contratto">
                                <div class="mp2-budget-subheading">
                                    <h3>Dettaglio Contratto</h3>
                                    <span>{{ count($source['contract']['conditions']) }} {{ count($source['contract']['conditions']) === 1 ? 'condizione economica' : 'condizioni economiche' }}</span>
                                </div>
                                <dl class="mp2-budget-contract-facts">
                                    <div><dt>Data di Inizio</dt><dd>{{ $source['contract']['start_date'] }}</dd></div>
                                    <div><dt>Prossima Scadenza</dt><dd>{{ $source['contract']['expiry_date'] }}</dd></div>
                                    <div><dt>Rinnovo Automatico</dt><dd>{{ $source['contract']['automatic_renewal'] }}</dd></div>
                                    <div><dt>Durata Rinnovo</dt><dd>{{ $source['contract']['renewal_duration'] }}</dd></div>
                                    <div><dt>Preavviso</dt><dd>{{ $source['contract']['notice'] }}</dd></div>
                                    <div><dt>Data Limite Disdetta</dt><dd>{{ $source['contract']['cancellation_deadline'] }}</dd></div>
                                </dl>

                                <div class="mp2-budget-condition-list">
                                    @forelse ($source['contract']['conditions'] as $condition)
                                        <article>
                                            <div class="mp2-budget-condition-heading">
                                                <div>
                                                    <strong>{{ $condition['amount'] }}</strong>
                                                    <span>{{ $condition['cycle'] }} · attribuzione a {{ strtolower($condition['attribution']) }}</span>
                                                </div>
                                                <span class="mp2-object-table-state">{{ $condition['state'] }}</span>
                                            </div>
                                            <dl>
                                                <div><dt>Valida dal</dt><dd>{{ $condition['valid_from'] }}</dd></div>
                                                <div><dt>Valida fino al</dt><dd>{{ $condition['valid_to'] }}</dd></div>
                                            </dl>
                                            @if (filled($condition['reason']))
                                                <p>{{ $condition['reason'] }}</p>
                                            @endif
                                            @if ($condition['composition'] !== [])
                                                <details class="mp2-budget-nested-details">
                                                    <summary>Composizione annuale · {{ count($condition['composition']) }} {{ count($condition['composition']) === 1 ? 'ciclo' : 'cicli' }}</summary>
                                                    <div class="mp2-budget-table-wrap" tabindex="0" role="region" aria-label="Composizione Annuale della Condizione">
                                                        <table class="mp2-budget-detail-table">
                                                            <thead><tr><th>Ciclo dal</th><th>Data di Attribuzione</th><th class="mp2-object-number">Importo</th></tr></thead>
                                                            <tbody>
                                                                @foreach ($condition['composition'] as $item)
                                                                    <tr><td>{{ $item['cycle_start'] }}</td><td>{{ $item['attribution_date'] }}</td><td class="mp2-object-number">{{ $item['amount'] }}</td></tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </details>
                                            @endif
                                        </article>
                                    @empty
                                        <p class="mp2-budget-muted">Nessuna condizione economica materializzata.</p>
                                    @endforelse
                                </div>
                            </section>
                        @endif

                        <div class="mp2-budget-secondary-grid">
                            <section class="mp2-budget-source-section">
                                <div class="mp2-budget-subheading">
                                    <h3>Azioni e Motivazioni Approvate</h3>
                                    <span>{{ count($source['actions']) }}</span>
                                </div>
                                @forelse ($source['actions'] as $action)
                                    <article class="mp2-budget-action">
                                        <div>
                                            <span>Sequenza {{ $action['sequence'] ?? '—' }}</span>
                                            <strong>{{ $action['label'] }}</strong>
                                        </div>
                                        @if (filled($action['reason']))<p>{{ $action['reason'] }}</p>@endif
                                        <details class="mp2-budget-nested-details">
                                            <summary>Dati dell’Azione</summary>
                                            <pre>{{ $action['payload'] }}</pre>
                                        </details>
                                    </article>
                                @empty
                                    <p class="mp2-budget-muted">Nessuna azione specifica registrata per questa sorgente.</p>
                                @endforelse
                            </section>

                            <section class="mp2-budget-source-section">
                                <div class="mp2-budget-subheading">
                                    <h3>Relazioni Informative</h3>
                                    <span>{{ count($source['relations']) }}</span>
                                </div>
                                @forelse ($source['relations'] as $relation)
                                    <article class="mp2-budget-relation">
                                        <strong>{{ $relation['project'] }} ↔ {{ $relation['contract'] }}</strong>
                                        <span>{{ $relation['project_key'] }} · {{ $relation['contract_key'] }}</span>
                                        @if (filled($relation['note']))<p>{{ $relation['note'] }}</p>@endif
                                    </article>
                                @empty
                                    <p class="mp2-budget-muted">Nessuna relazione informativa materializzata.</p>
                                @endforelse
                            </section>
                        </div>

                        <details class="mp2-budget-traceability">
                            <summary>Riferimenti e Tracciabilità Tecnica</summary>
                            <dl>
                                <div><dt>OriginKey</dt><dd><code>{{ $source['origin_key'] }}</code></dd></div>
                                <div><dt>ProposalItemID</dt><dd><code>{{ $source['proposal_item_id'] }}</code></dd></div>
                                <div><dt>CopiedFromOriginKey</dt><dd><code>{{ $source['copied_from_origin_key'] ?? '—' }}</code></dd></div>
                                <div><dt>Versione Schema Dettaglio</dt><dd>{{ $source['detail_version'] }}</dd></div>
                                <div><dt>Riferimenti Eventi di Approvazione</dt><dd>{{ $source['event_sequences'] === [] ? '—' : implode(', ', $source['event_sequences']) }}</dd></div>
                            </dl>
                        </details>
                    </div>
                </details>
            @empty
                <div class="mp2-object-empty-state mp2-budget-source-empty">
                    <x-filament::icon icon="heroicon-o-inbox" aria-hidden="true" />
                    <p>Nessuna sorgente materializzata in questa versione.</p>
                </div>
            @endforelse
        </div>
    </section>

    <section class="mp2-object-workspace-panel mp2-budget-evidence" aria-labelledby="budget-evidence-title">
        <div class="mp2-object-section-heading">
            <span class="mp2-object-section-icon" aria-hidden="true">
                <x-filament::icon icon="heroicon-o-paper-clip" />
            </span>
            <div>
                <p class="mp2-object-eyebrow">Approvazione Esterna</p>
                <h2 id="budget-evidence-title">Evidenza di Approvazione</h2>
            </div>
        </div>

        @if ($overview['evidence'] === [])
            <div class="mp2-budget-evidence-empty">
                <x-filament::icon icon="heroicon-o-document" aria-hidden="true" />
                <div>
                    <strong>Nessuna Evidenza Esterna o Allegato</strong>
                    <p>Autore e data dell’approvazione restano registrati nella Snapshot.</p>
                </div>
            </div>
        @else
            <div class="mp2-budget-evidence-list">
                @foreach ($overview['evidence'] as $evidence)
                    <article>
                        <dl>
                            @if (filled($evidence['subject']))<div><dt>Soggetto Esterno</dt><dd>{{ $evidence['subject'] }}</dd></div>@endif
                            @if (filled($evidence['venue']))<div><dt>Sede/Verbale</dt><dd>{{ $evidence['venue'] }}</dd></div>@endif
                            @if (filled($evidence['reason']))<div class="mp2-budget-evidence-wide"><dt>Nota</dt><dd>{{ $evidence['reason'] }}</dd></div>@endif
                            @if (filled($evidence['attachment']))
                                <div class="mp2-budget-evidence-wide">
                                    <dt>Allegato</dt>
                                    <dd>
                                        @if ($evidence['download_url'] !== null)
                                            <a href="{{ $evidence['download_url'] }}">{{ $evidence['attachment'] }}</a>
                                        @else
                                            {{ $evidence['attachment'] }}
                                        @endif
                                        @if ($evidence['size'] !== null)<small>{{ $evidence['size'] }}</small>@endif
                                    </dd>
                                </div>
                            @endif
                            @if (filled($evidence['sha256']))<div class="mp2-budget-evidence-wide"><dt>SHA-256</dt><dd><code>{{ $evidence['sha256'] }}</code></dd></div>@endif
                        </dl>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>
