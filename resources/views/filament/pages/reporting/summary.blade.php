@php
    use Illuminate\Support\Number;

    $money = static fn (string|int|float $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
    $availability = $report['header']['availability'];
    $kind = $report['header']['kind'];
@endphp

<section class="mp2-report-summary" aria-labelledby="report-summary-title">
    <div class="mp2-report-section-heading">
        <div>
            <p class="mp2-report-kicker">Sintesi</p>
            <h3 id="report-summary-title">Indicatori del Report</h3>
        </div>
    </div>

    @if ($kind === 'annual_executive')
        <dl class="mp2-report-primary-kpis">
            <div class="mp2-report-primary-kpi mp2-report-kpi-budget">
                <dt>Budget Approvato Corrente</dt>
                <dd>{{ $availability['current_budget'] ? $money($report['totals']['current_budget']) : 'Non disponibile' }}</dd>
                <dd class="mp2-report-kpi-note">
                    @if ($availability['initial_budget'])
                        Budget iniziale {{ $money($report['totals']['initial_budget']) }}
                    @else
                        Nessun Budget approvato disponibile
                    @endif
                </dd>
            </div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-allocation">
                <dt>Allocato Corrente</dt>
                <dd>{{ $money($report['totals']['current_allocation']) }}</dd>
                <dd class="mp2-report-kpi-note">Situazione Corrente</dd>
            </div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-actual">
                <dt>{{ $report['header']['actual_reference'] }}</dt>
                <dd>{{ $money($report['totals']['selected_actual']) }}</dd>
                <dd class="mp2-report-kpi-note">Riferimento Effettivo Selezionato</dd>
            </div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-variance">
                <dt>Scostamento Operativo</dt>
                <dd>{{ $money($report['totals']['current_operational_variance']) }}</dd>
                <dd class="mp2-report-kpi-note">Effettivo Corrente − Allocato Corrente</dd>
            </div>
        </dl>

        <dl class="mp2-report-secondary-kpis">
            <div><dt>Effettivo Corrente</dt><dd>{{ $money($report['totals']['current_actual']) }}</dd></div>
            @if ($availability['selected_budget'])
                <div><dt>Variazione Allocato vs Budget Selezionato</dt><dd>{{ $money($report['totals']['allocation_vs_selected_budget']) }}</dd></div>
                <div><dt>Varianza Budget vs Actual Selezionato</dt><dd>{{ $money($report['totals']['selected_budget_actual_variance']) }}</dd></div>
            @endif
            @if ($availability['closing'])
                <div><dt>Effettivo alla Chiusura</dt><dd>{{ $money($report['totals']['closing_actual']) }}</dd></div>
                <div><dt>Correzioni Tardive Positive</dt><dd>{{ $money($report['totals']['late_corrections_positive']) }}</dd></div>
                <div><dt>Correzioni Tardive Negative</dt><dd>{{ $money($report['totals']['late_corrections_negative']) }}</dd></div>
                <div><dt>Correzioni Tardive Nette</dt><dd>{{ $money($report['totals']['late_corrections_net']) }}</dd></div>
                <div><dt>Effettivo a Conoscenza Corrente</dt><dd>{{ $money($report['totals']['current_knowledge_actual']) }}</dd></div>
            @endif
            <div><dt>Non Classificato</dt><dd>{{ $money($report['totals']['unclassified']) }}</dd></div>
            <div><dt>Sorgenti Primarie</dt><dd>{{ $report['totals']['source_count'] }}</dd></div>
            <div><dt>Annotazioni di Errore Storico</dt><dd>{{ $report['totals']['annotation_count'] }}</dd></div>
        </dl>
    @elseif (in_array($kind, ['budget_actual', 'budget_current_allocation', 'budget_versions', 'exercises'], true))
        @php
            $deltaLabel = match ($kind) {
                'budget_actual' => 'Varianza Budget vs Actual',
                'budget_current_allocation' => 'Variazione Allocato vs Budget',
                'budget_versions' => 'Variazione fra Budget',
                default => 'Delta Complessivo',
            };
        @endphp
        <dl class="mp2-report-primary-kpis mp2-report-primary-kpis-compact">
            <div class="mp2-report-primary-kpi mp2-report-kpi-allocation">
                <dt>{{ $report['header']['initial_reference_label'] }}</dt>
                <dd>{{ $money($report['comparison_totals']['initial']) }}</dd>
                <dd class="mp2-report-kpi-note">Riferimento Iniziale</dd>
            </div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-actual">
                <dt>{{ $report['header']['final_reference_label'] }}</dt>
                <dd>{{ $money($report['comparison_totals']['final']) }}</dd>
                <dd class="mp2-report-kpi-note">Riferimento Finale</dd>
            </div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-variance">
                <dt>{{ $deltaLabel }}</dt><dd>{{ $money($report['comparison_totals']['delta']) }}</dd>
                @if ($kind === 'exercises')
                    <dd class="mp2-report-kpi-note">Misura: {{ $this->exerciseMeasureOptions()[$exerciseMeasure] ?? $exerciseMeasure }}</dd>
                @endif
            </div>
            <div class="mp2-report-primary-kpi">
                <dt>Sorgenti Confrontate</dt><dd>{{ $report['comparison_totals']['source_count'] }}</dd>
            </div>
        </dl>
    @elseif ($kind === 'operational_variance')
        <dl class="mp2-report-primary-kpis mp2-report-primary-kpis-compact">
            <div class="mp2-report-primary-kpi mp2-report-kpi-allocation"><dt>Allocato Corrente</dt><dd>{{ $money($report['totals']['allocation']) }}</dd></div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-actual"><dt>Effettivo Corrente</dt><dd>{{ $money($report['totals']['actual']) }}</dd></div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-variance"><dt>Scostamento Operativo</dt><dd>{{ $money($report['totals']['operational_variance']) }}</dd></div>
            <div class="mp2-report-primary-kpi"><dt>Sorgenti Primarie</dt><dd>{{ $report['totals']['source_count'] }}</dd></div>
        </dl>
    @elseif ($kind === 'suppliers')
        <dl class="mp2-report-primary-kpis mp2-report-primary-kpis-compact">
            <div class="mp2-report-primary-kpi mp2-report-kpi-allocation"><dt>Allocato Aggregato</dt><dd>{{ $money($report['specialist_totals']['allocation']) }}</dd></div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-actual"><dt>Effettivo Aggregato</dt><dd>{{ $money($report['specialist_totals']['actual']) }}</dd></div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-variance"><dt>Scostamento Operativo</dt><dd>{{ $money($report['specialist_totals']['operational_variance']) }}</dd></div>
            <div class="mp2-report-primary-kpi"><dt>Bucket Fornitore</dt><dd>{{ $report['specialist_totals']['item_count'] }}</dd></div>
        </dl>
    @elseif (in_array($kind, ['projects', 'contracts'], true))
        <dl class="mp2-report-primary-kpis mp2-report-primary-kpis-compact">
            <div class="mp2-report-primary-kpi mp2-report-kpi-allocation"><dt>Allocato</dt><dd>{{ $money($report['specialist_totals']['allocation']) }}</dd></div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-actual"><dt>Effettivo</dt><dd>{{ $money($report['specialist_totals']['actual']) }}</dd></div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-variance"><dt>Scostamento Operativo</dt><dd>{{ $money($report['specialist_totals']['operational_variance']) }}</dd></div>
            <div class="mp2-report-primary-kpi"><dt>{{ $kind === 'projects' ? 'Progetti' : 'Contratti' }}</dt><dd>{{ $report['specialist_totals']['item_count'] }}</dd></div>
        </dl>
    @elseif ($kind === 'carryovers')
        <dl class="mp2-report-primary-kpis mp2-report-primary-kpis-compact">
            <div class="mp2-report-primary-kpi"><dt>Riporto</dt><dd>{{ $money($report['specialist_totals']['carryover']) }}</dd></div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-allocation"><dt>Allocato</dt><dd>{{ $money($report['specialist_totals']['allocation']) }}</dd></div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-actual"><dt>Effettivo</dt><dd>{{ $money($report['specialist_totals']['actual']) }}</dd></div>
            <div class="mp2-report-primary-kpi"><dt>Progetti con Riporto</dt><dd>{{ $report['specialist_totals']['item_count'] }}</dd></div>
        </dl>
    @endif

    @if ($report['category_items'] !== [] || $report['label_items'] !== [])
        <div class="mp2-report-classification-summary">
            @if ($report['category_items'] !== [])
                <div>
                    <h4>Categorie Primarie</h4>
                    <ul>
                        @foreach ($report['category_items'] as $item)
                            <li><span class="mp2-report-category" data-category="{{ $item['key'] }}">{{ $item['label'] }}</span><strong>{{ $item['count'] }}</strong></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($report['label_items'] !== [])
                <div>
                    <h4>Etichette Secondarie</h4>
                    <ul>
                        @foreach ($report['label_items'] as $item)
                            <li><span>{{ $item['label'] }}</span><strong>{{ $item['count'] }}</strong></li>
                        @endforeach
                    </ul>
                    <p>Le etichette possono sovrapporsi e non sono categorie esclusive.</p>
                </div>
            @endif
        </div>
    @endif
</section>
