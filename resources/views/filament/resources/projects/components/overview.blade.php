<div class="mp2-object-overview mp2-project-overview">
    <div class="mp2-object-overview-grid">
        <section class="mp2-object-workspace-panel" aria-labelledby="project-profile-title">
            <div class="mp2-object-section-heading">
                <span class="mp2-object-section-icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-briefcase" />
                </span>
                <div>
                    <p class="mp2-object-eyebrow">Profilo operativo</p>
                    <h2 id="project-profile-title">Dati del Progetto</h2>
                </div>
            </div>

            <dl class="mp2-project-profile">
                <div class="mp2-project-profile-wide">
                    <dt>Descrizione</dt>
                    <dd>{{ $overview['profile']['description'] }}</dd>
                </div>
                <div>
                    <dt>Stato iniziale</dt>
                    <dd>{{ $overview['profile']['initial_state'] }}</dd>
                </div>
                <div>
                    <dt>Data efficacia iniziale</dt>
                    <dd>{{ $overview['profile']['initial_effective_date'] }}</dd>
                </div>
                <div>
                    <dt>Stato attuale</dt>
                    <dd>{{ $overview['profile']['current_state'] }}</dd>
                </div>
                <div>
                    <dt>Visibilità</dt>
                    <dd>{{ $overview['profile']['visibility'] }}</dd>
                </div>
                <div class="mp2-project-profile-wide">
                    <dt>OriginKey</dt>
                    <dd class="mp2-object-technical-value">{{ $overview['profile']['origin_key'] }}</dd>
                </div>
                @if ($overview['profile']['notes'] !== '—')
                    <div class="mp2-project-profile-wide">
                        <dt>Note</dt>
                        <dd>{{ $overview['profile']['notes'] }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="mp2-object-workspace-panel" aria-labelledby="project-current-exercise-title">
            <div class="mp2-object-section-heading">
                <span class="mp2-object-section-icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-chart-bar-square" />
                </span>
                <div>
                    <p class="mp2-object-eyebrow">Contesto Annuale</p>
                    <h2 id="project-current-exercise-title">Situazione Esercizio Corrente</h2>
                </div>
            </div>

            @if ($overview['selected'] !== null)
                <dl class="mp2-object-exercise-context">
                    <div>
                        <dt>Esercizio</dt>
                        <dd>{{ $overview['selected']['year'] }}</dd>
                    </div>
                    <div>
                        <dt>Data di riferimento</dt>
                        <dd>{{ $overview['selected']['reference_date'] }}</dd>
                    </div>
                    <div>
                        <dt>Stato alla data</dt>
                        <dd>{{ $overview['selected']['state'] }}</dd>
                    </div>
                    <div class="mp2-object-cost-center">
                        <dt>Centro di Costo</dt>
                        <dd>{{ $overview['selected']['cost_center'] }}</dd>
                    </div>
                </dl>

                <dl class="mp2-object-economic-summary">
                    <div>
                        <dt>Stime</dt>
                        <dd>{{ $overview['selected']['estimates'] }}</dd>
                    </div>
                    <div>
                        <dt>Riporto ricevuto</dt>
                        <dd>{{ $overview['selected']['received_carryover'] }}</dd>
                    </div>
                    <div>
                        <dt>Allocato corrente</dt>
                        <dd>{{ $overview['selected']['allocation'] }}</dd>
                    </div>
                    <div>
                        <dt>Effettivo</dt>
                        <dd>{{ $overview['selected']['actual'] }}</dd>
                    </div>
                    <div>
                        <dt>Scostamento operativo</dt>
                        <dd>{{ $overview['selected']['variance'] }}</dd>
                    </div>
                    <div>
                        <dt>Residuo</dt>
                        <dd>{{ $overview['selected']['residual'] }}</dd>
                    </div>
                    <div>
                        <dt>Disponibilità massima riportabile</dt>
                        <dd>{{ $overview['selected']['maximum_transferable'] }}</dd>
                    </div>
                    <div>
                        <dt>Rinvio ricevuto</dt>
                        <dd>{{ $overview['selected']['incoming_deferral_mode'] }} · {{ $overview['selected']['incoming_deferral_amount'] }}</dd>
                    </div>
                </dl>
                @if ($overview['selected']['carryover_above_current_maximum'])
                    <p role="alert" class="mp2-object-warning">Riporto provvisorio superiore al massimo corrente</p>
                @endif
            @else
                <div class="mp2-object-empty-state">
                    <x-filament::icon icon="heroicon-o-calendar-days" aria-hidden="true" />
                    <p>Nessun Esercizio disponibile nel contesto globale.</p>
                </div>
            @endif
        </section>
    </div>

    <section class="mp2-object-annual-section" aria-labelledby="project-annual-title">
        <div class="mp2-object-annual-heading">
            <div>
                <p class="mp2-object-eyebrow">Andamento pluriennale</p>
                <h2 id="project-annual-title">Situazioni annuali</h2>
            </div>
            <p>Stato, classificazione e valori calcolati alla data di riferimento di ciascun Esercizio.</p>
        </div>

        <div class="mp2-object-annual-table-wrap" tabindex="0" role="region" aria-label="Situazioni annuali del Progetto">
            <table class="mp2-object-annual-table mp2-project-annual-table">
                <thead>
                    <tr>
                        <th scope="col">Esercizio</th>
                        <th scope="col">Riferimento</th>
                        <th scope="col">Stato</th>
                        <th scope="col">Centro di Costo</th>
                        <th scope="col" class="mp2-object-number">Stime</th>
                        <th scope="col" class="mp2-object-number">Riporto ricevuto</th>
                        <th scope="col" class="mp2-object-number">Allocato corrente</th>
                        <th scope="col" class="mp2-object-number">Effettivo</th>
                        <th scope="col" class="mp2-object-number">Scostamento operativo</th>
                        <th scope="col" class="mp2-object-number">Residuo</th>
                        <th scope="col" class="mp2-object-number">Massimo riportabile</th>
                        <th scope="col">Rinvio ricevuto</th>
                        <th scope="col">Transizioni pianificate</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($overview['annual'] as $row)
                        <tr @class(['mp2-object-annual-selected' => $row['selected']])>
                            <th scope="row">
                                <span>{{ $row['year'] }}</span>
                                @if ($row['selected'])
                                    <small>Esercizio selezionato</small>
                                @endif
                            </th>
                            <td>
                                {{ $row['reference_date'] }}
                                <small class="mp2-object-table-secondary">{{ $row['reference_rule'] }}</small>
                            </td>
                            <td><span class="mp2-object-table-state">{{ $row['state'] }}</span></td>
                            <td>{{ $row['cost_center'] }}</td>
                            <td class="mp2-object-number">{{ $row['estimates'] }}</td>
                            <td class="mp2-object-number">{{ $row['received_carryover'] }}</td>
                            <td class="mp2-object-number">{{ $row['allocation'] }}</td>
                            <td class="mp2-object-number">{{ $row['actual'] }}</td>
                            <td class="mp2-object-number">{{ $row['variance'] }}</td>
                            <td class="mp2-object-number">{{ $row['residual'] }}</td>
                            <td class="mp2-object-number">{{ $row['maximum_transferable'] }}</td>
                            <td>
                                {{ $row['incoming_deferral_mode'] }} · {{ $row['incoming_deferral_amount'] }}
                                @if ($row['carryover_above_current_maximum'])
                                    <small class="mp2-object-table-secondary">Riporto provvisorio superiore al massimo corrente</small>
                                @endif
                            </td>
                            <td>{{ $row['future_transitions'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="13" class="mp2-object-table-empty">Nessuna situazione annuale disponibile.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
