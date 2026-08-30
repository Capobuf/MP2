@php
    use Illuminate\Support\Number;

    $money = static fn (string|int|float $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
    $availability = $report['header']['availability'];
@endphp

<section class="mp2-report-summary" aria-labelledby="report-summary-title">
    <div class="mp2-report-section-heading">
        <div>
            <p class="mp2-report-kicker">Sintesi</p>
            <h3 id="report-summary-title">Indicatori del report</h3>
        </div>
    </div>

    @if ($report['header']['kind'] === 'annual_executive')
        <dl class="mp2-report-primary-kpis">
            <div class="mp2-report-primary-kpi mp2-report-kpi-budget">
                <dt>Budget approvato corrente</dt>
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
                <dd class="mp2-report-kpi-note">Riferimento Effettivo selezionato</dd>
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
                <div><dt>Variazione Allocato vs Budget selezionato</dt><dd>{{ $money($report['totals']['allocation_vs_selected_budget']) }}</dd></div>
                <div><dt>Varianza Budget vs Actual selezionato</dt><dd>{{ $money($report['totals']['selected_budget_actual_variance']) }}</dd></div>
            @endif
            @if ($availability['closing'])
                <div><dt>Effettivo alla Chiusura</dt><dd>{{ $money($report['totals']['closing_actual']) }}</dd></div>
                <div><dt>Correzioni tardive positive</dt><dd>{{ $money($report['totals']['late_corrections_positive']) }}</dd></div>
                <div><dt>Correzioni tardive negative</dt><dd>{{ $money($report['totals']['late_corrections_negative']) }}</dd></div>
                <div><dt>Correzioni tardive nette</dt><dd>{{ $money($report['totals']['late_corrections_net']) }}</dd></div>
                <div><dt>Effettivo a Conoscenza Corrente</dt><dd>{{ $money($report['totals']['current_knowledge_actual']) }}</dd></div>
            @endif
            <div><dt>Non classificato</dt><dd>{{ $money($report['totals']['unclassified']) }}</dd></div>
            <div><dt>Sorgenti primarie</dt><dd>{{ $report['totals']['source_count'] }}</dd></div>
            <div><dt>Annotazioni di errore storico</dt><dd>{{ $report['totals']['annotation_count'] }}</dd></div>
        </dl>
    @else
        <dl class="mp2-report-primary-kpis mp2-report-primary-kpis-compact">
            <div class="mp2-report-primary-kpi mp2-report-kpi-allocation">
                <dt>Allocato del riferimento</dt><dd>{{ $money($report['totals']['allocation']) }}</dd>
            </div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-actual">
                <dt>Effettivo del riferimento</dt><dd>{{ $money($report['totals']['actual']) }}</dd>
            </div>
            <div class="mp2-report-primary-kpi mp2-report-kpi-variance">
                <dt>Scostamento Operativo</dt><dd>{{ $money($report['totals']['operational_variance']) }}</dd>
                <dd class="mp2-report-kpi-note">Effettivo − Allocato</dd>
            </div>
            <div class="mp2-report-primary-kpi">
                <dt>Sorgenti primarie</dt><dd>{{ $report['totals']['source_count'] }}</dd>
            </div>
        </dl>
    @endif

    @if ($report['category_items'] !== [] || $report['label_items'] !== [])
        <div class="mp2-report-classification-summary">
            @if ($report['category_items'] !== [])
                <div>
                    <h4>Categorie primarie</h4>
                    <ul>
                        @foreach ($report['category_items'] as $item)
                            <li><span class="mp2-report-category" data-category="{{ $item['key'] }}">{{ $item['label'] }}</span><strong>{{ $item['count'] }}</strong></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($report['label_items'] !== [])
                <div>
                    <h4>Etichette secondarie</h4>
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
