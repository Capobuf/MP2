<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">

    @php
        use Carbon\CarbonImmutable;
        use Illuminate\Support\Number;

        $orientation = $document['orientation'];
        $portrait = $orientation === 'portrait';
        $contracts = $document['contracts'];
        $selectedKpis = array_values(array_filter(
            $document['kpis'],
            fn (array $kpi): bool => in_array($kpi['id'], $document['selected_blocks'], true),
        ));
        $contractColumns = collect($document['selected_columns'])
            ->filter(fn (string $id): bool => str_starts_with($id, 'column:contracts:'))
            ->map(fn (string $id): string => str($id)->afterLast(':')->toString())
            ->values()
            ->all();
        $availableColumns = collect($document['available_columns'])->keyBy('id');
        $formatDate = static function (mixed $value): string {
            if (! is_string($value) || $value === '') {
                return '—';
            }

            return CarbonImmutable::parse($value)->format('d/m/Y');
        };
        $money = static fn (mixed $value): string => Number::currency(
            (float) $value,
            in: 'EUR',
            locale: 'it',
        );
        $contractChart = collect($document['charts'])->firstWhere('id', 'contract-values');
        $stateChart = collect($document['charts'])->firstWhere('id', 'contract-states');
        $showContractChart = $contractChart && in_array('chart:contract-values', $document['selected_blocks'], true);
        $showStateChart = $stateChart && in_array('chart:contract-states', $document['selected_blocks'], true);
        $showTable = in_array('table:contracts', $document['selected_blocks'], true);
        $showDetails = in_array('details:contracts', $document['selected_blocks'], true);
        $portfolioKpis = array_values(array_filter($selectedKpis, fn (array $kpi): bool => in_array($kpi['id'], ['kpi:specialist_count', 'kpi:contracts_expiring'], true)));
        $economicKpis = array_values(array_filter($selectedKpis, fn (array $kpi): bool => in_array($kpi['id'], ['kpi:specialist_allocation', 'kpi:specialist_actual', 'kpi:specialist_variance'], true)));
        $renderColumns = $portrait
            ? array_values(array_intersect($contractColumns, ['deadline', 'allocation', 'actual', 'operational_variance']))
            : $contractColumns;
        $secondaryPortraitColumns = array_diff($contractColumns, $renderColumns);
        $font = base64_encode(file_get_contents(resource_path('fonts/geist-latin-wght-normal.woff2')));
    @endphp

    <style>
        @font-face {
            font-family: Geist;
            src: url("data:font/woff2;base64,{{ $font }}") format("woff2");
            font-weight: 100 900;
            font-style: normal;
        }
        @page {
            size: A4 {{ $orientation }};
            margin: 12mm 12mm 16mm;
            @bottom-left {
                content: string(company) " · Esercizio {{ $document['header']['exercise_year'] }}";
                font-family: Geist;
                color: #667b7d;
                font-size: 7pt;
                border-top: 0.4pt solid #d6e1df;
                padding-top: 2mm;
            }
            @bottom-right {
                content: "Pagina " counter(page) " di " counter(pages);
                font-family: Geist;
                color: #667b7d;
                font-size: 7pt;
                border-top: 0.4pt solid #d6e1df;
                padding-top: 2mm;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #15323b; font-family: Geist; font-size: 9pt; line-height: 1.35; }
        h1, h2, h3, p, dl, dd { margin: 0; }
        h1 { font-size: 24pt; font-weight: 650; letter-spacing: -0.03em; color: #0b1d25; line-height: 1.1; }
        h2, h3 { break-after: avoid; }
        .report-header { margin-bottom: 5mm; padding-bottom: 3mm; border-bottom: 1.3pt solid #39d5c4; }
        .header-brand { display: table; width: 100%; }
        .header-logo, .header-copy { display: table-cell; vertical-align: middle; }
        .header-logo { width: 27mm; padding-right: 5mm; }
        .header-logo img { display: block; max-width: 22mm; max-height: 15mm; }
        .company-name { string-set: company content(); font-size: 10pt; margin-bottom: 1.5mm; overflow-wrap: anywhere; }
        .header-meta { margin-top: 3mm; font-size: 8pt; color: #526762; }
        .header-meta p + p { margin-top: 1mm; }
        .header-meta strong { font-weight: 600; color: #15323b; }
        .section-title { margin: 5mm 0 2mm; font-size: 12pt; font-weight: 650; color: #0b1d25; }
        .summary { margin-bottom: 4mm; break-inside: avoid; }
        .landscape .report-header { display: table; width: 100%; table-layout: fixed; }
        .landscape .header-identity, .landscape .header-meta { display: table-cell; vertical-align: bottom; }
        .landscape .header-identity { width: 43%; padding-right: 6mm; }
        .landscape .header-meta { text-align: right; }
        .landscape h1 { font-size: 21pt; }
        .landscape .summary { display: table; width: 100%; }
        .landscape .portfolio-group { display: table-cell; width: 30%; padding-right: 6mm; vertical-align: middle; }
        .landscape .portfolio-group:only-child { width: 100%; padding-right: 0; }
        .landscape .portfolio-summary { margin-bottom: 0; }
        .landscape .portfolio-metric dt { display: block; margin: 0; font-size: 9pt; }
        .landscape .portfolio-metric + .portfolio-metric { padding-left: 4mm; }
        .landscape .economic-group { display: table-cell; vertical-align: middle; }
        .portfolio-summary { display: table; width: 100%; margin-bottom: 3mm; table-layout: fixed; }
        .portfolio-metric { display: table-cell; vertical-align: baseline; }
        .portfolio-metric + .portfolio-metric { padding-left: 6mm; }
        .portfolio-metric dd { display: inline; font-size: 25pt; font-weight: 600; font-variant-numeric: tabular-nums; }
        .portfolio-metric dt { display: inline; margin-left: 2mm; font-size: 10pt; }
        .portfolio-metric small { display: block; color: #667b7d; font-size: 8pt; }
        .economic-summary { display: table; width: 100%; table-layout: fixed; padding: 3mm 0; border-top: 0.5pt solid #d6e1df; border-bottom: 0.5pt solid #d6e1df; }
        .economic-metric { display: table-cell; vertical-align: top; padding: 0 4mm; }
        .economic-metric:first-child { padding-left: 0; }
        .economic-metric + .economic-metric { border-left: 0.5pt solid #d6e1df; }
        .economic-metric dt { font-size: 8pt; color: #526762; }
        .economic-metric dd { margin-top: 1mm; font-size: {{ $portrait ? '14' : '17' }}pt; font-weight: 600; font-variant-numeric: tabular-nums; }
        .economic-note { margin-top: 1mm; font-size: 7pt; color: #667b7d; }
        .analysis-panel { margin: 0 0 4mm; break-inside: avoid; }
        .panel-title { font-size: 10pt; font-weight: 650; }
        .chart-description { margin: 0.5mm 0 2mm; color: #667b7d; font-size: 8pt; }
        .chart-image { display: block; width: 100%; height: auto; }
        table.contracts, table.detail-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th, td { padding: 2mm 1.3mm; border-bottom: 0.4pt solid #d6e1df; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { font-size: 7pt; color: #526762; font-weight: 600; border-top: 0.6pt solid #91a3a8; background: #f6f9f8; }
        td { font-size: 8pt; }
        th:first-child, td:first-child { padding-left: 0; }
        th:last-child, td:last-child { padding-right: 0; }
        .col-contract { width: auto; }
        .col-supplier, .col-cost_center { width: 8%; }
        .col-state { width: 7%; }
        .col-deadline, .col-notice_limit_date { width: 9%; }
        .col-renewal { width: 8%; }
        .col-allocation, .col-actual, .col-operational_variance { width: 10%; }
        .portrait .col-deadline { width: 17%; }
        .portrait .col-allocation, .portrait .col-actual, .portrait .col-operational_variance { width: 16%; }
        .contract-name { color: #0b1d25; font-weight: 650; }
        .contract-secondary { display: block; margin-top: 1mm; color: #526762; font-size: 7pt; }
        .contract-secondary > span { display: block; margin-top: 0.5mm; }
        .contract-label { display: block; margin-top: 1mm; padding-left: 1.5mm; border-left: 1.5pt solid #39d5c4; color: #15323b; font-size: 7pt; }
        .money { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .date { font-weight: 600; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .state { font-size: 7pt; white-space: nowrap; }
        .state::before { content: ""; display: inline-block; width: 1.5mm; height: 1.5mm; margin-right: 1mm; background: #15323b; }
        .state-active::before { background: #39d5c4; }
        .state-planned::before { background: #60a5fa; }
        .state-cessated::before { background: #91a3a8; }
        .detail { margin-bottom: 6mm; padding-top: 3mm; border-top: 1pt solid #39d5c4; }
        .detail-opening { break-inside: avoid; }
        .detail-header { display: table; width: 100%; margin-bottom: 1.5mm; break-after: avoid; }
        .detail-title, .detail-state { display: table-cell; vertical-align: top; }
        .detail-title { font-size: 14pt; font-weight: 650; overflow-wrap: anywhere; }
        .detail-state { width: 23mm; text-align: right; }
        .detail-intro { margin-bottom: 3mm; color: #526762; overflow-wrap: anywhere; }
        .detail-grid { display: table; width: 100%; margin-bottom: 3mm; table-layout: fixed; break-inside: avoid; }
        .detail-grid-row { display: table-row; }
        .detail-metric { display: table-cell; padding: 1mm 3mm; border-left: 0.4pt solid #d6e1df; }
        .detail-metric:first-child { padding-left: 0; border-left: 0; }
        .detail-metric-label { display: block; margin-bottom: 1mm; font-size: 8pt; color: #526762; }
        .detail-metric-value { font-size: 11pt; font-weight: 600; font-variant-numeric: tabular-nums; }
        .detail-section-title { margin: 3mm 0 1.5mm; font-size: 9pt; font-weight: 650; }
        .cell-note { display: block; margin-top: 1mm; color: #667b7d; font-size: 7.5pt; }
        .event-timeline { margin-left: 1.5mm; }
        .event { position: relative; border-left: 0.6pt solid #d6e1df; padding: 0 0 3mm 4mm; break-inside: avoid; }
        .event::before { content: ""; position: absolute; left: -1mm; top: 1mm; width: 2mm; height: 2mm; border-radius: 50%; background: #39d5c4; }
        .event time { display: inline-block; width: 24mm; font-variant-numeric: tabular-nums; }
        .event p { margin-top: 1mm; color: #526762; overflow-wrap: anywhere; }
        .empty { color: #667b7d; }
    </style>
</head>

<body class="{{ $portrait ? 'portrait' : 'landscape' }}">
    <header class="report-header">
        <div class="header-identity">
            <div class="header-brand">
                @if ($document['logo'] && in_array('logo', $document['selected_blocks'], true))
                    <div class="header-logo"><img src="{{ $document['logo'] }}" alt="Logo {{ $document['header']['company_name'] }}"></div>
                @endif
                <div class="header-copy">
                    <p class="company-name">{{ $document['header']['company_name'] }}</p>
                    <h1>Report Contratti</h1>
                </div>
            </div>
        </div>
        <div class="header-meta">
            <p><strong>Esercizio {{ $document['header']['exercise_year'] }}</strong> · Data di riferimento <strong>{{ $formatDate($document['header']['reference_date']) }}</strong> · {{ $document['header']['currency'] }} · {{ $document['header']['amount_basis'] }}</p>
            <p>Generato il {{ CarbonImmutable::parse($document['header']['generated_at'])->format('d/m/Y H:i') }}</p>
            @if ($document['header']['date_from'] !== null || $document['header']['date_to'] !== null)
                <p class="selected-interval"><strong>Intervallo selezionato</strong>@if ($document['header']['date_from'] !== null) · dal {{ $formatDate($document['header']['date_from']) }}@endif @if ($document['header']['date_to'] !== null) al {{ $formatDate($document['header']['date_to']) }}@endif</p>
            @endif
            @if ($document['header']['filter_labels'] !== [])
                <p><strong>Filtri</strong> · {{ implode(' · ', $document['header']['filter_labels']) }}</p>
            @endif
        </div>
    </header>

    @if ($selectedKpis !== [])
        <section class="summary">
            @if ($portfolioKpis !== [])
                <div class="portfolio-group">
                    <dl class="portfolio-summary">
                        @foreach ($portfolioKpis as $kpi)
                            <div class="portfolio-metric">
                                <dd>{{ $kpi['formatted'] }}</dd><dt>{{ $kpi['label'] }}</dt>
                                @if ($kpi['description'])<small>{{ $kpi['description'] }}</small>@endif
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
            @if ($economicKpis !== [])
                <div class="economic-group">
                    <dl class="economic-summary">
                        @foreach ($economicKpis as $kpi)
                            <div class="economic-metric"><dt>{{ $kpi['label'] }}</dt><dd>{{ $kpi['formatted'] }}</dd></div>
                        @endforeach
                    </dl>
                    @if (in_array('kpi:specialist_variance', $document['selected_blocks'], true))
                        <p class="economic-note">Scostamento operativo = Effettivo − Allocato</p>
                    @endif
                </div>
            @endif
        </section>
    @endif

    @if ($showStateChart)
        <section class="analysis-panel" data-block="chart:contract-states">
            <h2 class="panel-title">{{ $stateChart['heading'] }}</h2>
            <p class="chart-description">{{ $stateChart['description'] }}</p>
            <img class="chart-image" src="{{ $stateChart['image'] }}" alt="Distribuzione dei contratti nei quattro stati canonici">
        </section>
    @endif
    @if ($showContractChart)
        <section class="analysis-panel" data-block="chart:contract-values">
            <h2 class="panel-title">{{ $contractChart['heading'] }}</h2>
            <p class="chart-description">{{ $contractChart['description'] }}</p>
            <img class="chart-image" src="{{ $contractChart['image'] }}" alt="Allocato ed Effettivo per contratto">
        </section>
    @endif

    @if ($showTable)
        <h2 class="section-title">Registro contratti</h2>

        <table class="contracts">
            <colgroup>
                <col class="col-contract">
                @foreach ($renderColumns as $column)<col class="col-{{ $column }}">@endforeach
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">Contratto</th>
                    @foreach ($renderColumns as $column)
                        <th scope="col" class="{{ in_array($column, ['allocation', 'actual', 'operational_variance'], true) ? 'money' : '' }}">{{ $availableColumns['column:contracts:'.$column]['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($contracts as $contract)
                    <tr>
                        <td>
                            <span class="contract-name">{{ $contract['label'] }}</span>
                            @foreach ($contract['labels'] as $label)<span class="contract-label">{{ $label }}</span>@endforeach
                            @if ($portrait && $secondaryPortraitColumns !== [])
                                <span class="contract-secondary">
                                    @if (in_array('supplier', $contractColumns, true))<span>{{ $contract['supplier'] }}</span>@endif
                                    @if (in_array('state', $contractColumns, true))<span class="state state-{{ $contract['state'] }}">{{ $contract['state_label'] }}</span>@endif
                                    @if (in_array('cost_center', $contractColumns, true))<span>CdC: {{ $contract['cost_center'] }}</span>@endif
                                    @if (in_array('notice_limit_date', $contractColumns, true))<span>Limite preavviso: <strong class="date">{{ $formatDate($contract['notice_limit_date']) }}</strong></span>@endif
                                    @if (in_array('renewal', $contractColumns, true))<span>Rinnovo: {{ $contract['automatic_renewal'] ? 'Automatico' : 'Non automatico' }}</span>@endif
                                </span>
                            @endif
                        </td>

                        @foreach ($renderColumns as $column)
                            @switch($column)
                                @case('supplier')
                                    <td>{{ $contract['supplier'] }}</td>
                                    @break
                                @case('state')
                                    <td><span class="state state-{{ $contract['state'] }}">{{ $contract['state_label'] }}</span></td>
                                    @break
                                @case('cost_center')
                                    <td>{{ $contract['cost_center'] }}</td>
                                    @break
                                @case('deadline')
                                    <td class="date">{{ $formatDate($contract['deadline']) }}</td>
                                    @break
                                @case('notice_limit_date')
                                    <td class="date">{{ $formatDate($contract['notice_limit_date']) }}</td>
                                    @break
                                @case('renewal')
                                    <td>{{ $contract['automatic_renewal'] ? 'Automatico' : 'Non automatico' }}</td>
                                    @break
                                @case('allocation')
                                    <td class="money">{{ $money($contract['allocation']) }}</td>
                                    @break
                                @case('actual')
                                    <td class="money">{{ $money($contract['actual']) }}</td>
                                    @break
                                @case('operational_variance')
                                    <td class="money">{{ $money($contract['operational_variance']) }}</td>
                                    @break
                            @endswitch
                        @endforeach
                    </tr>
                @empty
                    <tr><td class="empty" colspan="{{ count($renderColumns) + 1 }}">Nessun contratto applicabile.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if ($showDetails)
        <h2 class="section-title">Approfondimenti contratti</h2>
        @forelse ($contracts as $contract)
            <article class="detail">
                <div class="detail-opening">
                    <div class="detail-header">
                        <div class="detail-title">{{ $contract['label'] }}</div>
                        <div class="detail-state"><span class="state state-{{ $contract['state'] }}">{{ $contract['state_label'] }}</span></div>
                    </div>

                    <p class="detail-intro">
                        {{ $contract['supplier'] }} · Centro di costo: {{ $contract['cost_center'] }}
                        @if ($contract['summary']) · {{ $contract['summary'] }} @endif
                    </p>

                    <div class="detail-grid">
                        <div class="detail-grid-row">
                            <div class="detail-metric"><span class="detail-metric-label">Scadenza</span><span class="detail-metric-value">{{ $formatDate($contract['deadline']) }}</span></div>
                            <div class="detail-metric"><span class="detail-metric-label">Limite preavviso</span><span class="detail-metric-value">{{ $formatDate($contract['notice_limit_date']) }}</span></div>
                            <div class="detail-metric"><span class="detail-metric-label">Rinnovo</span><span class="detail-metric-value">{{ $contract['automatic_renewal'] ? 'Automatico' : 'Non automatico' }}</span></div>
                        </div>
                    </div>

                    <div class="detail-grid">
                        <div class="detail-grid-row">
                            <div class="detail-metric"><span class="detail-metric-label">Allocato</span><span class="detail-metric-value">{{ $money($contract['allocation']) }}</span></div>
                            <div class="detail-metric"><span class="detail-metric-label">Effettivo</span><span class="detail-metric-value">{{ $money($contract['actual']) }}</span></div>
                            <div class="detail-metric"><span class="detail-metric-label">Scostamento</span><span class="detail-metric-value">{{ $money($contract['operational_variance']) }}</span></div>
                        </div>
                    </div>

                </div>

                @if ($contract['conditions'] !== [])
                    <h3 class="detail-section-title">Condizioni economiche</h3>
                    <table class="detail-table">
                        <thead><tr><th class="money">Importo</th><th>Ciclo</th><th>Attribuzione</th><th>Validità</th></tr></thead>
                        <tbody>
                            @foreach ($contract['conditions'] as $condition)
                                <tr>
                                    <td class="money">{{ $money($condition['amount']) }}</td>
                                    <td>{{ $condition['cycle'] }}</td>
                                    <td>{{ $condition['attribution'] }}</td>
                                    <td>
                                        dal {{ $formatDate($condition['valid_from']) }}@if ($condition['valid_to']) al {{ $formatDate($condition['valid_to']) }}@endif
                                        @if ($condition['reason'])<span class="cell-note">{{ $condition['reason'] }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if ($contract['renewal_configurations'] !== [])
                    <h3 class="detail-section-title">Configurazione rinnovo</h3>
                    <table class="detail-table">
                        <thead><tr><th>Decorrenza</th><th>Automatico</th><th>Scadenza</th><th>Durata</th><th>Preavviso</th></tr></thead>
                        <tbody>
                            @foreach ($contract['renewal_configurations'] as $configuration)
                                <tr>
                                    <td>{{ $formatDate($configuration['effective_from']) }}</td>
                                    <td>{{ $configuration['automatic_renewal'] ? 'Sì' : 'No' }}</td>
                                    <td>{{ $formatDate($configuration['expiry_anchor_date']) }}</td>
                                    <td>{{ $configuration['renewal_duration_months'] === null ? '—' : $configuration['renewal_duration_months'].' mesi' }}</td>
                                    <td>{{ $configuration['notice_days'] === null ? '—' : $configuration['notice_days'].' giorni' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if ($contract['events'] !== [])
                    <h3 class="detail-section-title">Eventi contrattuali</h3>
                    <div class="event-timeline">
                        @foreach ($contract['events'] as $event)
                            <div class="event">
                                <time>{{ $formatDate($event['date']) }}</time><strong>{{ $event['label'] }}</strong>
                                @if ($event['reason'] !== null)<p>{{ $event['reason'] }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($contract['expenses'] !== [])
                    <h3 class="detail-section-title">Spese dell’esercizio</h3>
                    <table class="detail-table">
                        <colgroup><col style="width: 60%"><col style="width: 20%"><col style="width: 20%"></colgroup>
                        <thead><tr><th>Descrizione</th><th class="money">Allocato</th><th class="money">Effettivo</th></tr></thead>
                        <tbody>
                            @foreach ($contract['expenses'] as $expense)
                                <tr><td>{{ $expense['description'] }}</td><td class="money">{{ $money($expense['allocation']) }}</td><td class="money">{{ $money($expense['actual']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </article>
        @empty
            <p class="empty">Nessun dettaglio contratto applicabile.</p>
        @endforelse
    @endif
</body>
</html>
