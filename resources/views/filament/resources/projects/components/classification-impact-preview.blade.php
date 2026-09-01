@if ($error !== null)
    <div class="mp2-impact-preview mp2-impact-preview-error" role="alert">
        <strong>Anteprima Non Disponibile</strong>
        <p>{{ $error }}</p>
    </div>
@else
    <div class="mp2-impact-preview">
        <section class="mp2-impact-preview-section" aria-labelledby="project-classification-change-heading">
            <h4 id="project-classification-change-heading">Riclassificazione</h4>
            <div class="mp2-impact-preview-changes">
                <div class="mp2-impact-preview-change">
                    <span>Centro di Costo · Prima</span>
                    <strong>{{ $summary['before'] }}</strong>
                </div>
                <div class="mp2-impact-preview-change {{ $summary['changed'] ? 'is-changed' : '' }}">
                    <span>Centro di Costo · Dopo</span>
                    <strong>{{ $summary['after'] }}</strong>
                </div>
            </div>
        </section>

        <section class="mp2-impact-preview-section" aria-labelledby="project-classification-impact-heading">
            <h4 id="project-classification-impact-heading">Impatto</h4>
            <div class="mp2-impact-preview-exercise">
                <dl>
                    <div>
                        <dt>Spese Coinvolte</dt>
                        <dd>{{ $summary['expense_count'] }}</dd>
                    </div>
                    <div>
                        <dt>Allocato</dt>
                        <dd>{{ $summary['allocation'] }}</dd>
                    </div>
                    <div>
                        <dt>Effettivo</dt>
                        <dd>{{ $summary['actual'] }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <p class="mp2-impact-preview-note">
            Identità e importi delle Spese restano invariati; l’operazione modifica soltanto la classificazione dell’Esercizio.
        </p>
    </div>
@endif
