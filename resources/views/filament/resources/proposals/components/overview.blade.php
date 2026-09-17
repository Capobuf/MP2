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

        {{ $this->table }}
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
