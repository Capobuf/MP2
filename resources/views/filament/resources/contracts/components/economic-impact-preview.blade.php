@if ($error !== null)
    <div class="mp2-impact-preview mp2-impact-preview-error" role="alert">
        <strong>Anteprima Non Disponibile</strong>
        <p>{{ $error }}</p>
    </div>
@elseif ($summary !== null)
    <div class="mp2-impact-preview">
        <div class="mp2-impact-preview-heading">
            <div>
                <span class="mp2-impact-preview-status is-changed">
                    {{ $summary['operation_kind'] === 'correction' ? 'Correzione da confermare' : 'Modifica da confermare' }}
                </span>
                <p>
                    @if ($summary['operation_kind'] === 'correction')
                        La condizione originaria viene corretta senza creare un nuovo accordo.
                    @else
                        La modifica crea una nuova condizione dalla decorrenza effettiva e conserva lo storico precedente.
                    @endif
                </p>
            </div>
            <span class="mp2-impact-preview-warning">Prorata applicato: {{ $summary['no_prorata'] ? 'no' : 'sì' }}</span>
        </div>

        <section class="mp2-impact-preview-section" aria-labelledby="contract-economic-dates-heading">
            <h4 id="contract-economic-dates-heading">Decorrenza</h4>
            <div class="mp2-impact-preview-changes">
                @if ($summary['requested_date'] !== null)
                    <div class="mp2-impact-preview-change">
                        <span>Data richiesta</span>
                        <strong>{{ $summary['requested_date'] }}</strong>
                    </div>
                    <div class="mp2-impact-preview-change">
                        <span>Data minima richiedibile</span>
                        <strong>{{ $summary['minimum_date'] }}</strong>
                    </div>
                @endif
                <div class="mp2-impact-preview-change is-changed">
                    <span>{{ $summary['operation_kind'] === 'correction' ? 'Decorrenza originaria conservata' : 'Data effettiva applicabile' }}</span>
                    <strong>{{ $summary['effective_date'] }}</strong>
                </div>
            </div>
            <p class="mp2-impact-preview-note">{{ $summary['delay_reason'] }}</p>
        </section>

        <section class="mp2-impact-preview-section" aria-labelledby="contract-economic-terms-heading">
            <h4 id="contract-economic-terms-heading">Termini Economici</h4>
            <div class="mp2-impact-preview-changes">
                @foreach ($summary['terms'] as $term)
                    <div class="mp2-impact-preview-change {{ $term['changed'] ? 'is-changed' : '' }}">
                        <span>{{ $term['label'] }}</span>
                        @if ($term['changed'])
                            <div>
                                <del>{{ $term['before'] }}</del>
                                <b aria-hidden="true">→</b>
                                <strong>{{ $term['after'] }}</strong>
                            </div>
                        @else
                            <strong>{{ $term['after'] }}</strong>
                            <small>Invariato</small>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mp2-impact-preview-section" aria-labelledby="contract-economic-exercises-heading">
            <h4 id="contract-economic-exercises-heading">Impatto sugli Esercizi Aperti</h4>
            @if ($summary['exercise_impacts'] === [])
                <p class="mp2-impact-preview-note">
                    Nessun Esercizio aperto esistente cambia Allocato. Verifica la decorrenza effettiva prima di confermare.
                </p>
            @else
                <div class="mp2-impact-preview-exercises">
                    @foreach ($summary['exercise_impacts'] as $impact)
                        <div class="mp2-impact-preview-exercise">
                            <strong>Esercizio {{ $impact['year'] }}</strong>
                            <dl>
                                <div>
                                    <dt>Allocato</dt>
                                    <dd>
                                        {{ $impact['allocation_before'] }}
                                        <span aria-hidden="true">→</span>
                                        {{ $impact['allocation_after'] }}
                                        <em>{{ $impact['allocation_delta'] }}</em>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endif
