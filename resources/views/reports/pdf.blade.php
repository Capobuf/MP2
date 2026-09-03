<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 {{ $document['orientation'] }}; margin: 14mm 12mm 16mm; @bottom-left { content: "MP2 · EUR · importi netti IVA"; color: #667b7d; font-size: 7.5pt; } @bottom-right { content: "Pagina " counter(page) " di " counter(pages); color: #667b7d; font-size: 7.5pt; } }
        * { box-sizing: border-box; }
        body { color: #172126; font-family: sans-serif; font-size: 8.5pt; line-height: 1.35; margin: 0; }
        header { border-bottom: 2px solid #0e8a80; display: table; margin-bottom: 9mm; padding-bottom: 4mm; width: 100%; }
        .header-copy, .header-logo { display: table-cell; vertical-align: middle; }
        .header-logo { text-align: right; width: 35mm; }
        .header-logo img { max-height: 18mm; max-width: 34mm; }
        h1 { color: #0b504c; font-size: 20pt; line-height: 1.1; margin: 0 0 2mm; }
        h2 { border-bottom: 1px solid #bdd0ce; color: #24413f; font-size: 12pt; margin: 8mm 0 3mm; padding-bottom: 2mm; break-after: avoid; }
        h3 { color: #2c5653; font-size: 9.5pt; margin: 4mm 0 1mm; break-after: avoid; }
        p { margin: 1.5mm 0; }
        .muted { color: #526762; }
        .meta { background: #eef7f5; border: 1px solid #c9dfdb; display: table; margin-bottom: 5mm; padding: 3mm; width: 100%; }
        .meta-row { display: table-row; }
        .meta-item { display: table-cell; padding: 1mm 4mm 1mm 0; width: 25%; }
        .meta-label { color: #526762; display: block; font-size: 7pt; text-transform: uppercase; }
        .definitions { color: #405557; font-size: 7.5pt; }
        .definitions p { break-inside: avoid; }
        .kpis { display: table; border-spacing: 3mm; margin: 0 -3mm; table-layout: fixed; width: 100%; }
        .kpi-row { display: table-row; }
        .kpi { background: #f2f7f6; border-left: 3px solid #0e8a80; display: table-cell; padding: 3mm; width: 25%; }
        .kpi-label { color: #526762; font-size: 7pt; text-transform: uppercase; }
        .kpi-value { font-size: 13pt; font-weight: bold; margin-top: 1mm; }
        .chart { border: 1px solid #d6e1df; break-inside: avoid; margin: 4mm 0; padding: 3mm; }
        .chart img { display: block; height: auto; max-height: 125mm; width: 100%; }
        table { border-collapse: collapse; margin: 3mm 0 5mm; table-layout: fixed; width: 100%; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th, td { border: 1px solid #bdcbc9; overflow-wrap: anywhere; padding: 1.7mm; text-align: left; vertical-align: top; }
        th { background: #dbece9; color: #173d39; font-size: 7.5pt; }
        td { font-size: 7.5pt; }
        .money { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
        .source-name { width: 24%; }
        .detail { background: #f8faf9; border: 1px solid #d6e1df; break-inside: avoid; margin: 3mm 0; padding: 3mm; }
        .structured { margin: 1mm 0 1mm 4mm; padding: 0; }
        .structured li { margin-bottom: 1mm; }
        .section { break-before: auto; }
        .empty { color: #667b7d; font-style: italic; }
    </style>
</head>
<body>
    <header>
        <div class="header-copy"><h1>{{ $document['header']['title'] }}</h1><p class="muted">Report MP2 · EUR · importi netti IVA</p></div>
        @if ($document['logo'] && in_array('logo', $document['selected_blocks'], true))
            <div class="header-logo"><img src="{{ $document['logo'] }}" alt="Logo {{ $document['header']['company_name'] }}"></div>
        @endif
    </header>

    <div class="meta">
        <div class="meta-row">
            <div class="meta-item"><span class="meta-label">Azienda</span>{{ $document['header']['company_name'] }}</div>
            <div class="meta-item"><span class="meta-label">Esercizio</span>{{ $document['header']['exercise_year'] }}</div>
            <div class="meta-item"><span class="meta-label">Data economica</span>{{ $document['header']['reference_date'] }}</div>
            <div class="meta-item"><span class="meta-label">Generato il</span>{{ $document['header']['generated_at'] }}</div>
        </div>
        <div class="meta-row">
            <div class="meta-item"><span class="meta-label">Riferimento iniziale</span>{{ $document['header']['initial_reference_label'] ?? $document['header']['initial_reference'] ?? 'Non applicabile' }}</div>
            <div class="meta-item"><span class="meta-label">Riferimento finale</span>{{ $document['header']['final_reference_label'] ?? $document['header']['final_reference'] ?? 'Non applicabile' }}</div>
            <div class="meta-item"><span class="meta-label">Intervallo</span>{{ $document['header']['date_from'] ?? 'Non applicabile' }} – {{ $document['header']['date_to'] ?? 'Non applicabile' }}</div>
            <div class="meta-item"><span class="meta-label">Filtri</span>{{ ($document['header']['filter_labels'] ?? []) === [] ? 'Nessuno' : implode(' · ', $document['header']['filter_labels']) }}</div>
        </div>
    </div>

    <section class="definitions">
        <h2>Definizioni del confronto</h2>
        @foreach ($document['category_definitions'] as $definition)<p><strong>{{ $definition['label'] }}:</strong> {{ $definition['definition'] }}</p>@endforeach
        <p>Le etichette secondarie possono sovrapporsi e non sono conteggi esclusivi.</p>
    </section>

    @php($selectedKpis = array_values(array_filter($document['kpis'], fn (array $kpi): bool => in_array($kpi['id'], $document['selected_blocks'], true))))
    @if ($selectedKpis !== [])
        <h2>Riepilogo</h2><div class="kpis">
        @foreach (array_chunk($selectedKpis, 4) as $row)<div class="kpi-row">
            @foreach ($row as $kpi)<div class="kpi"><div class="kpi-label">{{ $kpi['label'] }}</div><div class="kpi-value">{{ $kpi['formatted'] }}</div></div>@endforeach
            @for ($index = count($row); $index < 4; $index++)<div class="kpi"></div>@endfor
        </div>@endforeach
        </div>
    @endif

    @foreach ($document['charts'] as $chart)
        @if (in_array('chart:'.$chart['id'], $document['selected_blocks'], true))
            <section class="chart"><h2>{{ $chart['heading'] }}</h2><p class="muted">{{ $chart['description'] }}</p><img src="{{ $chart['image'] }}" alt="{{ $chart['heading'] }}"></section>
        @endif
    @endforeach

    @if (in_array('table:comparisons', $document['selected_blocks'], true))
        @php($columns = collect($document['selected_columns'])->filter(fn (string $id): bool => str_starts_with($id, 'column:comparisons:'))->map(fn (string $id): string => str($id)->afterLast(':')->toString())->all())
        <h2>Confronto</h2>
        <table>
            <thead><tr><th class="source-name">Sorgente</th>@foreach ($columns as $column)<th>{{ collect($document['available_columns'])->firstWhere('id', 'column:comparisons:'.$column)['label'] }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($document['comparisons'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}<br><small class="muted">{{ $row['origin_key'] }}</small></td>
                        @foreach ($columns as $column)
                            <td class="{{ in_array($column, ['initial_value', 'final_value', 'delta'], true) ? 'money' : '' }}">
                                @if (in_array($column, ['initial_value', 'final_value', 'delta'], true))
                                    {{ \Illuminate\Support\Number::currency((float) $row[$column], in: 'EUR', locale: 'it') }}
                                @elseif (is_array($row[$column]))
                                    {{ implode(', ', $row[$column]) }}
                                @else
                                    {{ $row[$column] }}
                                @endif
                                @if ($column === 'labels' && $row['insufficiently_explained'])
                                    <br>Variazione non sufficientemente spiegata
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (in_array('table:sources', $document['selected_blocks'], true))
        @php($columns = collect($document['selected_columns'])->filter(fn (string $id): bool => str_starts_with($id, 'column:sources:'))->map(fn (string $id): string => str($id)->afterLast(':')->toString())->all())
        <h2>Dettaglio e riconciliazione</h2>
        <table>
            <thead><tr><th class="source-name">Sorgente</th>@foreach ($columns as $column)<th>{{ collect($document['available_columns'])->firstWhere('id', 'column:sources:'.$column)['label'] }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($document['sources'] as $source)
                    <tr><td>{{ $source['label'] }}<br><small class="muted">{{ $source['origin_key'] }}</small></td>
                        @foreach ($columns as $column)<td class="{{ in_array($column, ['allocation', 'actual', 'operational_variance', 'carryover'], true) ? 'money' : '' }}">{{ in_array($column, ['allocation', 'actual', 'operational_variance', 'carryover'], true) ? \Illuminate\Support\Number::currency((float) $source[$column], in: 'EUR', locale: 'it') : ($source[$column] ?? '—') }}</td>@endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (in_array('details:sources', $document['selected_blocks'], true))
        <h2>Approfondimenti delle sorgenti</h2>
        @foreach ($document['sources'] as $source)
            <article class="detail">
                <h3>{{ $source['label'] }}</h3>
                @if ($source['summary'])<p>{{ $source['summary'] }}</p>@endif
                @include('reports.partials.structured-value', ['value' => ['Dettaglio' => $source['detail'], 'Correzioni tardive' => $source['corrections'], 'Annotazioni' => $source['annotations']]])
            </article>
        @endforeach
    @endif

    @foreach ($document['sections'] as $section)
        @if (in_array($section['id'], $document['selected_blocks'], true))
            <section class="section">
                <h2>{{ $section['title'] }}</h2>
                @forelse ($section['rows'] as $row)
                    <div class="detail">@include('reports.partials.structured-value', ['value' => $row])</div>
                @empty
                    <p class="empty">Nessun dato applicabile.</p>
                @endforelse
            </section>
        @endif
    @endforeach
</body>
</html>
