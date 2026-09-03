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
        $secondaryPortraitColumns = array_intersect($contractColumns, ['cost_center', 'notice_limit_date', 'renewal']);
    @endphp

    <style>
        @page {
            size: A4 {{ $orientation }};
            margin: {{ $portrait ? '10mm 9mm 14mm' : '9mm 10mm 13mm' }};

            @bottom-left {
                content: "{{ $document['header']['company_name'] }} · Esercizio {{ $document['header']['exercise_year'] }}";
                color: #667b7d;
                font-size: 6.5pt;
                border-top: 0.4pt solid #d6e1df;
                padding-top: 2mm;
            }

            @bottom-right {
                content: "Pagina " counter(page) " di " counter(pages);
                color: #667b7d;
                font-size: 6.5pt;
                border-top: 0.4pt solid #d6e1df;
                padding-top: 2mm;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #ffffff;
            color: #172126;
            font-family: "Geist Sans", "Geist", sans-serif;
            font-size: 7.5pt;
            line-height: 1.25;
        }

        .report-header {
            display: table;
            width: 100%;
            margin-bottom: 3mm;
            padding-bottom: 2.5mm;
            border-bottom: 1.1pt solid #39d5c4;
            table-layout: fixed;
        }

        .header-logo,
        .header-copy,
        .header-meta {
            display: table-cell;
            vertical-align: middle;
        }

        .header-logo { width: 27mm; padding-right: 4mm; }
        .header-logo img { display: block; max-width: 23mm; max-height: 14mm; }
        .header-copy { padding-right: 4mm; }
        .header-meta { width: {{ $portrait ? '65mm' : '86mm' }}; text-align: right; }

        h1 {
            margin: 0;
            color: #0b1d25;
            font-size: 15pt;
            line-height: 1;
            letter-spacing: 0.025em;
            text-transform: uppercase;
        }

        .report-subtitle {
            margin: 1mm 0 0;
            color: #526762;
            font-size: 7pt;
        }

        .meta-line { margin-top: 0.8mm; color: #526762; font-size: 6.4pt; }
        .meta-line:first-child { margin-top: 0; }
        .meta-label {
            color: #0e8a80;
            font-size: 5.8pt;
            font-weight: 700;
            letter-spacing: 0.045em;
            text-transform: uppercase;
        }

        .section-title {
            margin: 3.2mm 0 1.6mm;
            color: #15323b;
            font-size: 8.7pt;
            line-height: 1.15;
            break-after: avoid;
        }

        .kpi-row {
            display: table;
            width: calc(100% + 2mm);
            margin: 0 -1mm 1.2mm;
            table-layout: fixed;
            border-spacing: 1mm 0;
        }

        .kpi {
            display: table-cell;
            width: 20%;
            height: 18mm;
            padding: 2mm 2.2mm;
            border: 0.55pt solid #d6e1df;
            border-radius: 1.4mm;
            background: #ffffff;
            vertical-align: top;
        }

        .portrait .kpi { width: 33.333%; height: 18.5mm; }
        .portrait .kpi-row.count-2 .kpi { width: 50%; }

        .kpi-icon {
            float: right;
            width: 4.4mm;
            height: 4.4mm;
            color: #0e8a80;
        }

        .kpi-icon svg { display: block; width: 100%; height: 100%; }

        .kpi-label {
            max-width: calc(100% - 6mm);
            color: #667b7d;
            font-size: 5.6pt;
            font-weight: 700;
            letter-spacing: 0.035em;
            line-height: 1.15;
            text-transform: uppercase;
        }

        .kpi-value {
            clear: both;
            margin-top: 1.2mm;
            color: #0b1d25;
            font-size: 10.8pt;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .kpi-description { margin-top: 0.5mm; color: #667b7d; font-size: 5.8pt; }

        .analysis-grid {
            display: table;
            width: calc(100% + 2mm);
            margin: 0 -1mm;
            table-layout: fixed;
            border-spacing: 1mm 0;
        }

        .analysis-panel {
            display: table-cell;
            padding: 2mm 2.4mm;
            border: 0.55pt solid #d6e1df;
            border-radius: 1.4mm;
            background: #ffffff;
            vertical-align: top;
            break-inside: avoid;
        }

        .analysis-panel.bars { width: 61%; }
        .analysis-panel.donut { width: 39%; }
        .analysis-panel.only { width: 100%; }

        .portrait .analysis-grid { display: block; width: 100%; margin: 0; }
        .portrait .analysis-panel { display: block; width: 100%; margin-bottom: 1.8mm; }

        .panel-title {
            margin: 0 0 1mm;
            color: #15323b;
            font-size: 7.2pt;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .chart-image { display: block; width: 100%; object-fit: contain; }
        .landscape .bars .chart-image { height: 50mm; }
        .landscape .donut .chart-image { height: 50mm; }
        .portrait .donut .chart-image { height: 43mm; }
        .portrait .bars .chart-image { height: 39mm; }

        table.contracts,
        table.detail-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.contracts thead,
        table.detail-table thead { display: table-header-group; }

        table.contracts tr,
        table.detail-table tr { break-inside: avoid; }

        table.contracts th,
        table.contracts td {
            padding: 1.15mm 1mm;
            border-bottom: 0.4pt solid #d6e1df;
            text-align: left;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        table.contracts th {
            color: #405557;
            background: #f6f9f8;
            font-size: 5.6pt;
            font-weight: 700;
            letter-spacing: 0.025em;
            text-transform: uppercase;
        }

        table.contracts td { font-size: 6.35pt; }
        .portrait table.contracts td { font-size: 5.9pt; }

        .col-contract { width: 13%; }
        .col-supplier { width: 10%; }
        .col-state { width: 7%; }
        .col-cost_center { width: 10%; }
        .col-deadline,
        .col-notice_limit_date { width: 8%; }
        .col-renewal { width: 9%; }
        .col-allocation,
        .col-actual,
        .col-operational_variance { width: 10%; }

        .portrait .col-contract { width: 23%; }
        .portrait .col-supplier { width: 14%; }
        .portrait .col-state { width: 10%; }
        .portrait .col-deadline { width: 11%; }
        .portrait .col-allocation,
        .portrait .col-actual,
        .portrait .col-operational_variance { width: 14%; }

        .contract-name { color: #0b1d25; font-weight: 700; }
        .contract-secondary { margin-top: 0.55mm; color: #667b7d; font-size: 5.25pt; line-height: 1.25; }
        .contract-secondary span { display: block; margin-top: 0.3mm; }

        .money {
            text-align: right !important;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .state {
            display: inline-block;
            padding: 0.35mm 1mm;
            border: 0.55pt solid #91a3a8;
            border-radius: 4mm;
            color: #33484b;
            font-size: 5.4pt;
            font-weight: 700;
            white-space: nowrap;
        }

        .state-active { border-color: #39d5c4; }
        .state-planned { border-style: dashed; border-color: #60a5fa; }
        .state-cessated { border-color: #91a3a8; color: #667b7d; }
        .state-cancelled { border-style: double; border-color: #ef4444; }

        .detail {
            margin: 0 0 3mm;
            padding: 2.5mm;
            border: 0.55pt solid #d6e1df;
            border-left: 1.6pt solid #39d5c4;
        }

        .detail-header {
            display: table;
            width: 100%;
            margin-bottom: 1.5mm;
            break-after: avoid;
        }

        .detail-title,
        .detail-state { display: table-cell; vertical-align: middle; }
        .detail-title { color: #0b1d25; font-size: 8.5pt; font-weight: 700; }
        .detail-state { text-align: right; }
        .detail-intro { margin: 0 0 1.8mm; color: #526762; font-size: 6.4pt; }

        .detail-grid {
            display: table;
            width: calc(100% + 1.4mm);
            margin: 0 -0.7mm 1.8mm;
            table-layout: fixed;
            border-spacing: 0.7mm 0;
        }

        .detail-grid-row { display: table-row; }
        .detail-metric {
            display: table-cell;
            padding: 1.2mm 1.5mm;
            border: 0.45pt solid #e0e8e6;
            vertical-align: top;
        }

        .detail-metric-label {
            display: block;
            margin-bottom: 0.4mm;
            color: #667b7d;
            font-size: 5.2pt;
            font-weight: 700;
            letter-spacing: 0.035em;
            text-transform: uppercase;
        }

        .detail-metric-value { color: #15323b; font-size: 6.5pt; font-weight: 600; font-variant-numeric: tabular-nums; }

        .detail-section-title {
            margin: 2mm 0 0.8mm;
            color: #0e8a80;
            font-size: 6.1pt;
            font-weight: 700;
            letter-spacing: 0.055em;
            text-transform: uppercase;
            break-after: avoid;
        }

        table.detail-table th,
        table.detail-table td {
            padding: 0.9mm 1mm;
            border-bottom: 0.35pt solid #e0e8e6;
            text-align: left;
            vertical-align: top;
        }

        table.detail-table th { color: #667b7d; background: #f8faf9; font-size: 5.25pt; text-transform: uppercase; }
        table.detail-table td { font-size: 6.1pt; }
        .cell-note { display: block; margin-top: 0.3mm; color: #667b7d; font-size: 5.5pt; }
        .empty { color: #667b7d; font-style: italic; }
    </style>
</head>

<body class="{{ $portrait ? 'portrait' : 'landscape' }}">
    <header class="report-header">
        @if ($document['logo'] && in_array('logo', $document['selected_blocks'], true))
            <div class="header-logo">
                <img src="{{ $document['logo'] }}" alt="Logo {{ $document['header']['company_name'] }}">
            </div>
        @endif

        <div class="header-copy">
            <h1>Report Contratti</h1>
            <p class="report-subtitle">Quadro economico e operativo dei contratti</p>
        </div>

        <div class="header-meta">
            <div class="meta-line"><span class="meta-label">Azienda</span> · {{ $document['header']['company_name'] }}</div>
            <div class="meta-line"><span class="meta-label">Esercizio</span> · {{ $document['header']['exercise_year'] }} &nbsp; <span class="meta-label">Data economica</span> · {{ $formatDate($document['header']['reference_date']) }}</div>
            <div class="meta-line"><span class="meta-label">Generato</span> · {{ CarbonImmutable::parse($document['header']['generated_at'])->format('d/m/Y H:i') }} &nbsp; · EUR · {{ $document['header']['amount_basis'] }}</div>
            @if (($document['header']['filter_labels'] ?? []) !== [])
                <div class="meta-line"><span class="meta-label">Filtri</span> · {{ implode(' · ', $document['header']['filter_labels']) }}</div>
            @endif
        </div>
    </header>

    @if ($selectedKpis !== [])
        @foreach (array_chunk($selectedKpis, $portrait ? 3 : 5) as $row)
            <div class="kpi-row count-{{ count($row) }}">
                @foreach ($row as $kpi)
                    <div class="kpi">
                        <span class="kpi-icon" aria-hidden="true">
                            @switch($kpi['id'])
                                @case('kpi:specialist_count')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M7 3.5h10a2 2 0 0 1 2 2v15H5v-15a2 2 0 0 1 2-2Z"/><path d="M8.5 8h7M8.5 12h7M8.5 16h4"/></svg>
                                    @break
                                @case('kpi:specialist_allocation')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 7.5 12 3l8 4.5-8 4.5-8-4.5Z"/><path d="m4 12 8 4.5 8-4.5M4 16.5 12 21l8-4.5"/></svg>
                                    @break
                                @case('kpi:specialist_actual')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16.5 8"/></svg>
                                    @break
                                @case('kpi:specialist_variance')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 17h16M6 14l4-4 3 3 5-6"/><path d="M15 7h3v3"/></svg>
                                    @break
                                @default
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="5.5" width="16" height="14" rx="2"/><path d="M8 3v5M16 3v5M4 10h16M12 13v3M10.5 14.5h3"/></svg>
                            @endswitch
                        </span>
                        <div class="kpi-label">{{ $kpi['label'] }}</div>
                        <div class="kpi-value">{{ $kpi['formatted'] }}</div>
                        @if ($kpi['description'])<div class="kpi-description">{{ $kpi['description'] }}</div>@endif
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif

    @if ($showStateChart || $showContractChart)
        @if (! $portrait)
            <div class="analysis-grid">
                @if ($showContractChart)
                    <section class="analysis-panel bars {{ ! $showStateChart ? 'only' : '' }}">
                        <p class="panel-title">Allocato vs Effettivo per contratto</p>
                        <img class="chart-image" src="{{ $contractChart['image'] }}" alt="Grafico Allocato vs Effettivo per contratto">
                    </section>
                @endif
                @if ($showStateChart)
                    <section class="analysis-panel donut {{ ! $showContractChart ? 'only' : '' }}">
                        <p class="panel-title">Distribuzione per stato</p>
                        <img class="chart-image" src="{{ $stateChart['image'] }}" alt="Grafico a ciambella della distribuzione per stato">
                    </section>
                @endif
            </div>
        @else
            <div class="analysis-grid">
                @if ($showStateChart)
                    <section class="analysis-panel donut">
                        <p class="panel-title">Distribuzione per stato</p>
                        <img class="chart-image" src="{{ $stateChart['image'] }}" alt="Grafico a ciambella della distribuzione per stato">
                    </section>
                @endif
                @if ($showContractChart)
                    <section class="analysis-panel bars">
                        <p class="panel-title">Allocato vs Effettivo per contratto</p>
                        <img class="chart-image" src="{{ $contractChart['image'] }}" alt="Grafico Allocato vs Effettivo per contratto">
                    </section>
                @endif
            </div>
        @endif
    @endif

    @if ($showTable)
        <h2 class="section-title">Elenco contratti</h2>

        <table class="contracts">
            <colgroup>
                <col class="col-contract">
                @if (! $portrait)
                    @foreach ($contractColumns as $column)<col class="col-{{ $column }}">@endforeach
                @else
                    @foreach (['supplier', 'state', 'deadline', 'allocation', 'actual', 'operational_variance'] as $column)
                        @if (in_array($column, $contractColumns, true))<col class="col-{{ $column }}">@endif
                    @endforeach
                @endif
            </colgroup>
            <thead>
                <tr>
                    <th>Contratto</th>
                    @if (! $portrait)
                        @foreach ($contractColumns as $column)
                            <th class="{{ in_array($column, ['allocation', 'actual', 'operational_variance'], true) ? 'money' : '' }}">
                                {{ $availableColumns['column:contracts:'.$column]['label'] }}
                            </th>
                        @endforeach
                    @else
                        @foreach (['supplier', 'state', 'deadline', 'allocation', 'actual', 'operational_variance'] as $column)
                            @if (in_array($column, $contractColumns, true))
                                <th class="{{ in_array($column, ['allocation', 'actual', 'operational_variance'], true) ? 'money' : '' }}">
                                    {{ $availableColumns['column:contracts:'.$column]['label'] }}
                                </th>
                            @endif
                        @endforeach
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($contracts as $contract)
                    <tr>
                        <td>
                            <span class="contract-name">{{ $contract['label'] }}</span>
                            @if ($portrait && $secondaryPortraitColumns !== [])
                                <span class="contract-secondary">
                                    @if (in_array('cost_center', $contractColumns, true))<span>CdC: {{ $contract['cost_center'] }}</span>@endif
                                    @if (in_array('notice_limit_date', $contractColumns, true))<span>Preavviso: {{ $formatDate($contract['notice_limit_date']) }}</span>@endif
                                    @if (in_array('renewal', $contractColumns, true))<span>Rinnovo: {{ $contract['automatic_renewal'] ? 'Automatico' : 'Non automatico' }}</span>@endif
                                </span>
                            @endif
                        </td>

                        @php($renderColumns = $portrait ? array_values(array_intersect(['supplier', 'state', 'deadline', 'allocation', 'actual', 'operational_variance'], $contractColumns)) : $contractColumns)
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
                                    <td>{{ $formatDate($contract['deadline']) }}</td>
                                    @break
                                @case('notice_limit_date')
                                    <td>{{ $formatDate($contract['notice_limit_date']) }}</td>
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
                    <tr><td class="empty" colspan="10">Nessun contratto applicabile.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if ($showDetails)
        <h2 class="section-title">Approfondimenti contratti</h2>
        @forelse ($contracts as $contract)
            <article class="detail">
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

                @if ($contract['conditions'] !== [])
                    <h3 class="detail-section-title">Condizioni economiche</h3>
                    <table class="detail-table">
                        <thead><tr><th>Importo</th><th>Ciclo</th><th>Attribuzione</th><th>Validità</th></tr></thead>
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
                    <table class="detail-table">
                        <thead><tr><th>Data</th><th>Evento</th><th>Nota</th></tr></thead>
                        <tbody>
                            @foreach ($contract['events'] as $event)
                                <tr><td>{{ $formatDate($event['date']) }}</td><td>{{ $event['label'] }}</td><td>{{ $event['reason'] ?? '—' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if ($contract['expenses'] !== [])
                    <h3 class="detail-section-title">Spese dell’esercizio</h3>
                    <table class="detail-table">
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
