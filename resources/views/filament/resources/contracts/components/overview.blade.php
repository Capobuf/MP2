<div class="mp2-contract-overview">
    <div class="mp2-contract-overview-grid">
        <section class="mp2-contract-workspace-panel mp2-contract-agreement" aria-labelledby="contract-agreement-title">
            <div class="mp2-contract-section-heading">
                <span class="mp2-contract-section-icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-document-text" />
                </span>
                <div>
                    <p class="mp2-contract-eyebrow">Accordo corrente</p>
                    <h2 id="contract-agreement-title">Accordo attuale</h2>
                </div>
            </div>

            @if ($overview['condition'] !== null)
                <dl class="mp2-contract-facts">
                    <div class="mp2-contract-fact-primary">
                        <dt>Importo</dt>
                        <dd>{{ $overview['condition']['amount'] }}</dd>
                    </div>
                    <div>
                        <dt>Ciclo</dt>
                        <dd>{{ $overview['condition']['cycle'] }}</dd>
                    </div>
                    <div>
                        <dt>Attribuzione</dt>
                        <dd>{{ $overview['condition']['attribution'] }}</dd>
                    </div>
                    <div>
                        <dt>Valida dal</dt>
                        <dd>{{ $overview['condition']['valid_from'] }}</dd>
                    </div>
                    <div>
                        <dt>Valida fino al</dt>
                        <dd>{{ $overview['condition']['valid_to'] }}</dd>
                    </div>
                    @if ($overview['condition']['note'] !== null)
                        <div class="mp2-contract-fact-note">
                            <dt>Nota</dt>
                            <dd>{{ $overview['condition']['note'] }}</dd>
                        </div>
                    @endif
                </dl>
            @else
                <div class="mp2-contract-empty-state">
                    <x-filament::icon icon="heroicon-o-minus-circle" aria-hidden="true" />
                    <p>Nessuna condizione economica vigente</p>
                </div>
            @endif

            <div class="mp2-contract-terms">
                <div class="mp2-contract-subheading">
                    <x-filament::icon icon="heroicon-o-shield-check" aria-hidden="true" />
                    <h3>Termini contrattuali</h3>
                </div>
                <dl>
                    <div>
                        <dt>Rinnovo automatico</dt>
                        <dd>{{ $overview['terms']['automatic_renewal'] }}</dd>
                    </div>
                    <div>
                        <dt>Durata rinnovo</dt>
                        <dd>{{ $overview['terms']['renewal_duration'] }}</dd>
                    </div>
                    <div>
                        <dt>Preavviso</dt>
                        <dd>{{ $overview['terms']['notice'] }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="mp2-contract-workspace-panel mp2-contract-current-exercise" aria-labelledby="contract-current-exercise-title">
            <div class="mp2-contract-section-heading">
                <span class="mp2-contract-section-icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-chart-bar-square" />
                </span>
                <div>
                    <p class="mp2-contract-eyebrow">Contesto globale</p>
                    <h2 id="contract-current-exercise-title">Situazione Esercizio corrente</h2>
                </div>
            </div>

            @if ($overview['selected'] !== null)
                <dl class="mp2-contract-exercise-context">
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
                    <div class="mp2-contract-cost-center">
                        <dt>Centro di Costo</dt>
                        <dd>{{ $overview['selected']['cost_center'] }}</dd>
                    </div>
                </dl>

                <dl class="mp2-contract-economic-summary">
                    <div>
                        <dt>Allocato</dt>
                        <dd>{{ $overview['selected']['allocation'] }}</dd>
                    </div>
                    <div>
                        <dt>Effettivo</dt>
                        <dd>{{ $overview['selected']['actual'] }}</dd>
                    </div>
                    <div>
                        <dt>Scostamento</dt>
                        <dd>{{ $overview['selected']['variance'] }}</dd>
                    </div>
                </dl>

                <div class="mp2-contract-composition">
                    <div class="mp2-contract-subheading">
                        <h3>Composizione dell’Allocato</h3>
                        <span>{{ $overview['selected']['composition_count'] }} {{ $overview['selected']['composition_count'] === 1 ? 'ciclo compone' : 'cicli compongono' }} l’Allocato</span>
                    </div>
                    @if ($overview['selected']['composition'] !== [])
                        <dl class="mp2-contract-composition-summary">
                            <div>
                                <dt>Primo ciclo incluso</dt>
                                <dd>{{ $overview['selected']['first_cycle_start'] }}</dd>
                            </div>
                            <div>
                                <dt>Ultimo ciclo incluso</dt>
                                <dd>{{ $overview['selected']['last_cycle_start'] }}</dd>
                            </div>
                            <div>
                                <dt>Totale Allocato</dt>
                                <dd>{{ $overview['selected']['allocation'] }}</dd>
                            </div>
                        </dl>

                        <x-mp2.list-preview
                            :has-more="$overview['selected']['has_more_composition']"
                            class="mp2-contract-composition-preview"
                        >
                            <ul>
                                @foreach ($overview['selected']['composition_preview'] as $item)
                                    <li>
                                        <span>
                                            <time>{{ $item['attribution_date'] }}</time>
                                            <small>ciclo dal {{ $item['cycle_start'] }}</small>
                                        </span>
                                        <strong>{{ $item['amount'] }}</strong>
                                    </li>
                                @endforeach
                            </ul>

                            <x-slot:action>
                                {{ ($this->allocationDetailAction)([
                                    'year' => $overview['selected']['year'],
                                    'count' => $overview['selected']['composition_count'],
                                ]) }}
                            </x-slot:action>
                        </x-mp2.list-preview>
                    @else
                        <p class="mp2-contract-muted-copy">Nessun ciclo attribuito a questo Esercizio.</p>
                    @endif
                </div>
            @else
                <div class="mp2-contract-empty-state">
                    <x-filament::icon icon="heroicon-o-calendar-days" aria-hidden="true" />
                    <p>Nessun Esercizio disponibile nel contesto globale.</p>
                </div>
            @endif
        </section>
    </div>

    <section class="mp2-contract-annual-section" aria-labelledby="contract-annual-title">
        <div class="mp2-contract-annual-heading">
            <div>
                <p class="mp2-contract-eyebrow">Andamento pluriennale</p>
                <h2 id="contract-annual-title">Situazioni annuali</h2>
            </div>
            <p>Valori calcolati alla data di riferimento canonica di ciascun Esercizio.</p>
        </div>

        <div class="mp2-contract-annual-table-wrap" tabindex="0" role="region" aria-label="Situazioni annuali del Contratto">
            <table class="mp2-contract-annual-table">
                <thead>
                    <tr>
                        <th scope="col">Esercizio</th>
                        <th scope="col">Riferimento</th>
                        <th scope="col">Stato</th>
                        <th scope="col">Centro di Costo</th>
                        <th scope="col" class="mp2-contract-number">Allocato</th>
                        <th scope="col" class="mp2-contract-number">Effettivo</th>
                        <th scope="col" class="mp2-contract-number">Scostamento</th>
                        <th scope="col" class="mp2-contract-table-action"><span class="fi-sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($overview['annual'] as $row)
                        <tr @class(['mp2-contract-annual-selected' => $row['selected']])>
                            <th scope="row">
                                <span>{{ $row['year'] }}</span>
                                @if ($row['selected'])
                                    <small>Esercizio selezionato</small>
                                @endif
                            </th>
                            <td>{{ $row['reference_date'] }}</td>
                            <td><span class="mp2-contract-table-state">{{ $row['state'] }}</span></td>
                            <td>{{ $row['cost_center'] }}</td>
                            <td class="mp2-contract-number">{{ $row['allocation'] }}</td>
                            <td class="mp2-contract-number">{{ $row['actual'] }}</td>
                            <td class="mp2-contract-number">{{ $row['variance'] }}</td>
                            <td class="mp2-contract-table-action">
                                @if ($row['composition'] !== [])
                                    {{ ($this->allocationDetailAction)(['year' => $row['year']]) }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="mp2-contract-table-empty">Nessuna situazione annuale disponibile.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
