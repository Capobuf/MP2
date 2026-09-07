<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    @php
        use Carbon\CarbonImmutable;
        use Illuminate\Support\Number;

        $portrait = $document['orientation'] === 'portrait';
        $header = $document['header'];
        $money = static fn (mixed $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
        $date = static fn (string $value): string => CarbonImmutable::parse($value)->format('d/m/Y');
        $selectedKpis = collect($document['kpis'])->filter(fn (array $kpi): bool => in_array($kpi['id'], $document['selected_blocks'], true))->groupBy('group');
        $availableColumns = collect($document['available_columns'])->keyBy('id');
        $font = base64_encode(file_get_contents(resource_path('fonts/geist-latin-wght-normal.woff2')));
    @endphp
    <style>
        @font-face { font-family: Geist; src: url("data:font/woff2;base64,{{ $font }}") format("woff2"); font-weight: 100 900; font-style: normal; }
        @page {
            size: A4 {{ $document['orientation'] }};
            margin: 12mm 12mm 16mm;
            @bottom-left {
                content: "Esercizio {{ $header['exercise_year'] }} · Data di riferimento {{ $date($header['reference_date']) }} · EUR · Importi netti IVA\A Generato il {{ CarbonImmutable::parse($header['generated_at'])->format('d/m/Y H:i') }}";
                font-family: Geist; font-size: 7pt; color: #667b7d; white-space: pre;
                border-top: 0.4pt solid #d6e1df; padding-top: 2mm;
            }
            @bottom-right {
                content: "Pagina " counter(page) " di " counter(pages);
                font-family: Geist; font-size: 7pt; color: #667b7d;
                border-top: 0.4pt solid #d6e1df; padding-top: 2mm;
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
        .landscape h1 { font-size: 21pt; }
        .company-name { font-size: 10pt; margin-bottom: 1.5mm; overflow-wrap: anywhere; }
        .header-meta { margin-top: 3mm; font-size: 8pt; color: #526762; }
        .header-meta p + p { margin-top: 1mm; }
        .header-meta strong { font-weight: 600; color: #15323b; }
        .summary { margin-bottom: 4mm; }
        .kpi-group { display: flex; flex-wrap: wrap; margin-bottom: 3mm; }
        .metric { flex: 1 1 {{ $portrait ? '46' : '55' }}mm; margin: 0 5mm 3mm 0; break-inside: avoid; }
        .metric dt { font-size: 8pt; color: #526762; }
        .metric dd { margin-top: 1mm; font-size: {{ $portrait ? '16' : '18' }}pt; font-weight: 600; font-variant-numeric: tabular-nums; }
        .context { border-bottom: 0.5pt solid #d6e1df; padding-bottom: 2mm; }
        .context .metric { flex: 0 1 auto; margin-right: 7mm; margin-bottom: 0; }
        .context dd { display: inline; font-size: 21pt; }
        .context dt { display: inline; margin-left: 2mm; }
        .secondary { border-top: 0.5pt solid #d6e1df; padding-top: 2mm; }
        .secondary dd { font-size: 12pt; }
        .landscape .secondary .metric { flex-basis: 45mm; }
        .section-title { margin: 5mm 0 2mm; font-size: 12pt; font-weight: 650; color: #0b1d25; }
        .analysis-panel { margin: 0 0 4mm; break-inside: avoid; }
        .panel-title { font-size: 10pt; font-weight: 650; }
        .chart-description { margin: 0.5mm 0 2mm; color: #667b7d; font-size: 8pt; }
        .chart-image { display: block; width: 100%; height: auto; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 4mm; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th, td { padding: 2mm 1.3mm; border-bottom: 0.4pt solid #d6e1df; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { font-size: 7.5pt; color: #526762; font-weight: 600; border-top: 0.6pt solid #91a3a8; background: #f6f9f8; }
        td { font-size: 8pt; }
        .landscape th, .landscape td { padding-top: 1mm; padding-bottom: 1mm; }
        th:first-child, td:first-child { padding-left: 0; }
        th:last-child, td:last-child { padding-right: 0; }
        .col-quantity { width: {{ $portrait ? '17' : '11' }}%; }
        .col-metadata { width: 9%; }
        td.project-cell { padding: 0; }
        .project-values { margin: 0; }
        .project-values td { border-bottom: 0; padding-bottom: 0; }
        .project-balances { padding-bottom: 2mm; }
        .source-name { color: #0b1d25; font-weight: 650; }
        .cell-secondary { display: block; margin-top: 1mm; color: #526762; font-size: 7.5pt; }
        .row-metadata { display: block; margin-top: 1mm; color: #526762; font-size: 7.5pt; }
        .row-metadata .cell-secondary { display: inline; margin: 0; }
        .money { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .definitions { margin: 2mm 0 4mm; color: #526762; font-size: 7.5pt; }
        .definitions p { margin-top: 1mm; break-inside: avoid; }
        .detail { margin-bottom: 5mm; padding-top: 3mm; border-top: 0.6pt solid #d6e1df; }
        .detail h3 { font-size: 12pt; margin-bottom: 2mm; }
        .structured { margin: 1mm 0 2mm 4mm; padding: 0; }
        .structured li { margin-bottom: 1mm; overflow-wrap: anywhere; }
        .muted, .empty { color: #667b7d; }
    </style>
</head>
<body class="{{ $portrait ? 'portrait' : 'landscape' }}">
    <header class="report-header">
        <div class="header-brand">
            @if ($document['logo'] && in_array('logo', $document['selected_blocks'], true))
                <div class="header-logo"><img src="{{ $document['logo'] }}" alt="Logo {{ $header['company_name'] }}"></div>
            @endif
            <div class="header-copy"><p class="company-name">{{ $header['company_name'] }}</p><h1>{{ $header['title'] }}</h1></div>
        </div>
        @if ($header['initial_reference_label'] || $header['final_reference_label'] || $header['actual_reference'] || $header['date_from'] || $header['filter_labels'] !== [])
            <div class="header-meta">
                @if ($header['initial_reference_label'])<p><strong>Riferimento iniziale</strong> · {{ $header['initial_reference_label'] }}</p>@endif
                @if ($header['final_reference_label'])<p><strong>Riferimento finale</strong> · {{ $header['final_reference_label'] }}</p>@endif
                @if ($header['actual_reference'])<p><strong>Effettivo</strong> · {{ $header['actual_reference'] }}</p>@endif
                @if ($header['date_from'])<p><strong>Intervallo selezionato</strong> · dal {{ $date($header['date_from']) }} al {{ $date($header['date_to']) }}</p>@endif
                @if ($header['filter_labels'] !== [])<p><strong>Filtri</strong> · {{ implode(' · ', $header['filter_labels']) }}</p>@endif
            </div>
        @endif
    </header>

    @if ($selectedKpis->isNotEmpty())
        <section class="summary">
            @foreach (['context', 'economic', 'secondary'] as $group)
                @if ($selectedKpis->has($group))
                    <dl class="kpi-group {{ $group }}">
                        @foreach ($selectedKpis[$group] as $kpi)
                            <div class="metric" data-block="{{ $kpi['id'] }}">
                                @if ($group === 'context')<dd>{{ $kpi['formatted'] }}</dd><dt>{{ $kpi['label'] }}</dt>
                                @else<dt>{{ $kpi['label'] }}</dt><dd>{{ $kpi['formatted'] }}</dd>@endif
                                @if ($kpi['description'])<small class="muted">{{ $kpi['description'] }}</small>@endif
                            </div>
                        @endforeach
                    </dl>
                @endif
            @endforeach
        </section>
    @endif

    @foreach ($document['charts'] as $chart)
        @if (in_array('chart:'.$chart['id'], $document['selected_blocks'], true))
            <section class="analysis-panel" data-block="chart:{{ $chart['id'] }}">
                <h2 class="panel-title">{{ $chart['heading'] }}</h2>
                <p class="chart-description">{{ $chart['description'] }}</p>
                <img class="chart-image" src="{{ $chart['image'] }}" alt="{{ $chart['heading'] }}">
            </section>
        @endif
    @endforeach

    @if ($document['category_definitions'] !== [])
        <section class="definitions">
            <h2 class="panel-title">Definizioni del confronto</h2>
            @foreach ($document['category_definitions'] as $definition)<p><strong>{{ $definition['label'] }}:</strong> {{ $definition['definition'] }}</p>@endforeach
            <p>Le etichette secondarie possono sovrapporsi e non sono conteggi esclusivi.</p>
        </section>
    @endif

    @if (in_array('table:comparisons', $document['selected_blocks'], true))
        <h2 class="section-title">Confronto</h2>
        @include('reports.partials.data-table', ['rows' => $document['comparisons'], 'group' => 'comparisons', 'block' => 'table:comparisons', 'primaryLabel' => 'Sorgente', 'projectBalances' => false])
    @endif

    @foreach ($document['sections'] as $section)
        @if (in_array($section['id'], $document['selected_blocks'], true))
            <h2 class="section-title">{{ $section['title'] }}</h2>
            @if ($header['kind'] === 'suppliers')
                @include('reports.partials.suppliers-table', ['rows' => $section['rows'], 'block' => $section['id']])
            @else
                @include('reports.partials.data-table', ['rows' => $section['rows'], 'group' => 'sources', 'block' => $section['id'], 'primaryLabel' => 'Progetto', 'projectBalances' => true])
            @endif
        @endif
    @endforeach

    @if (in_array('table:sources', $document['selected_blocks'], true))
        <h2 class="section-title">Dettaglio e riconciliazione</h2>
        @include('reports.partials.data-table', ['rows' => $document['sources'], 'group' => 'sources', 'block' => 'table:sources', 'primaryLabel' => 'Sorgente', 'projectBalances' => false])
    @endif

    @if (in_array('details:sources', $document['selected_blocks'], true))
        <h2 class="section-title">Approfondimenti delle sorgenti</h2>
        @foreach ($document['sources'] as $source)
            <article class="detail">
                <h3>{{ $source['label'] }}</h3>
                @if ($source['summary'])<p>{{ $source['summary'] }}</p>@endif
                @include('reports.partials.structured-value', ['value' => ['Dettaglio' => $source['detail'], 'Correzioni tardive' => $source['corrections'], 'Annotazioni' => $source['annotations']]])
            </article>
        @endforeach
    @endif
</body>
</html>
