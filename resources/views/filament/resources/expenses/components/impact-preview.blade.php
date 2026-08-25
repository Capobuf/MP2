@if ($error !== null)
    <div class="mp2-impact-preview mp2-impact-preview-error" role="alert">
        <strong>Anteprima non disponibile</strong>
        <p>{{ $error }}</p>
    </div>
@else
    <div class="mp2-impact-preview">
        <div class="mp2-impact-preview-heading">
            <div>
                <span class="mp2-impact-preview-status {{ $summary['changed'] ? 'is-changed' : 'is-unchanged' }}">
                    {{ $summary['changed'] ? 'Modifiche da confermare' : 'Nessuna variazione selezionata' }}
                </span>
                <p>{{ $summary['identity'] }}</p>
            </div>
            @if ($summary['opens_project'])
                <span class="mp2-impact-preview-warning">Il Progetto verrà aperto nella stessa operazione</span>
            @endif
        </div>

        <div class="mp2-impact-preview-changes">
            @foreach ($summary['changes'] as $change)
                <div class="mp2-impact-preview-change {{ $change['changed'] ? 'is-changed' : '' }}">
                    <span>{{ $change['label'] }}</span>
                    @if ($change['changed'])
                        <div><del>{{ $change['from'] }}</del><b aria-hidden="true">→</b><strong>{{ $change['to'] }}</strong></div>
                    @else
                        <strong>{{ $change['to'] }}</strong>
                        <small>Invariato</small>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($summary['exercise_totals_change'])
            <div class="mp2-impact-preview-section">
                <h4>Impatto sugli Esercizi</h4>
                <div class="mp2-impact-preview-exercises">
                    @foreach ($summary['exercise_impacts'] as $impact)
                        <div class="mp2-impact-preview-exercise">
                            <strong>Esercizio {{ $impact['year'] }}</strong>
                            <dl>
                                <div>
                                    <dt>Allocato</dt>
                                    <dd>{{ $impact['allocation_before'] }} <span aria-hidden="true">→</span> {{ $impact['allocation_after'] }} <em>{{ $impact['allocation_delta'] }}</em></dd>
                                </div>
                                <div>
                                    <dt>Effettivo</dt>
                                    <dd>{{ $impact['actual_before'] }} <span aria-hidden="true">→</span> {{ $impact['actual_after'] }} <em>{{ $impact['actual_delta'] }}</em></dd>
                                </div>
                            </dl>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($summary['changed'])
            <p class="mp2-impact-preview-note">I totali dell’Esercizio restano invariati: cambia soltanto la loro attribuzione.</p>
        @endif

        @if ($summary['project_impacts'] !== [])
            <div class="mp2-impact-preview-section">
                <h4>Impatto sui Progetti</h4>
                @foreach ($summary['project_impacts'] as $impact)
                    <div class="mp2-impact-preview-project">
                        <strong>{{ $impact['label'] }}</strong>
                        <p>Allocato: {{ $impact['allocation_before'] }} → {{ $impact['allocation_after'] }}</p>
                        <p>Effettivo: {{ $impact['actual_before'] }} → {{ $impact['actual_after'] }}</p>
                        <p>Scostamento operativo: {{ $impact['variance_before'] }} → {{ $impact['variance_after'] }}</p>
                        @if ($impact['warning'] !== null)
                            <span class="mp2-impact-preview-warning">{{ $impact['warning'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
