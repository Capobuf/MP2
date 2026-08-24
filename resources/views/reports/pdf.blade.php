<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 14mm; }
        body { color: #172126; font-family: "DejaVu Sans", sans-serif; font-size: 9px; }
        h1 { color: #0e655e; font-size: 22px; margin: 0 0 4px; }
        h2 { border-bottom: 1px solid #9db4b1; color: #24413f; font-size: 13px; margin-top: 20px; padding-bottom: 4px; }
        .meta { background: #eef7f5; border: 1px solid #c9dfdb; margin: 12px 0; padding: 10px; }
        .meta span { display: inline-block; margin: 0 18px 5px 0; }
        table { border-collapse: collapse; margin: 8px 0 16px; width: 100%; }
        th, td { border: 1px solid #bdcbc9; padding: 5px; text-align: left; vertical-align: top; }
        th { background: #dbece9; color: #173d39; }
        .money { text-align: right; white-space: nowrap; }
        .muted { color: #526762; }
        .definition { margin: 3px 0; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <h1>{{ $report->header['title'] }}</h1>
    <p class="muted">Report MP2 · EUR · importi netti IVA</p>
    <div class="meta">
        <span><strong>Azienda:</strong> {{ $report->header['company_name'] }}</span>
        <span><strong>Esercizio:</strong> {{ $report->header['exercise_year'] }}</span>
        <span><strong>Riferimento iniziale:</strong> {{ $report->header['initial_reference'] ?? 'Non applicabile' }}</span>
        <span><strong>Riferimento finale:</strong> {{ $report->header['final_reference'] ?? 'Non applicabile' }}</span>
        <span><strong>Data economica:</strong> {{ $report->header['reference_date'] }}</span>
        <span><strong>Budget:</strong> {{ $report->header['budget_version'] ? 'v'.$report->header['budget_version'] : 'Non applicabile' }}</span>
        <span><strong>Tipo di Effettivo:</strong> {{ $report->header['actual_reference'] ?? 'Non applicabile' }}</span>
        <span><strong>Generato il:</strong> {{ $report->header['generated_at'] }}</span>
        <span><strong>Intervallo:</strong> {{ $report->header['date_from'] ?? 'Non applicabile' }} – {{ $report->header['date_to'] ?? 'Non applicabile' }}</span>
        <span><strong>Filtri:</strong> {{ $report->header['filter_labels'] === [] ? 'Nessuno' : implode(' · ', $report->header['filter_labels']) }}</span>
    </div>

    <h2>Definizioni</h2>
    @foreach (App\Domain\Reporting\ComparisonCategory::cases() as $category)
        <p class="definition"><strong>{{ $category->label() }}:</strong> {{ $category->definition() }}</p>
    @endforeach
    <p>Le etichette secondarie possono sovrapporsi e non sono conteggi esclusivi.</p>

    <h2>Riepilogo</h2>
    <table><tbody>@foreach ($report->totals as $label => $value)<tr><th>{{ str_replace('_', ' ', $label) }}</th><td class="money">{{ $value }}</td></tr>@endforeach</tbody></table>

    @if ($report->comparisons !== [])
        <h2>Confronto</h2>
        <table><thead><tr><th>Sorgente</th><th>Iniziale</th><th>Finale</th><th>Delta</th><th>Categoria</th><th>Dimensioni</th><th>Etichette</th></tr></thead><tbody>
        @foreach ($report->comparisons as $row)
            <tr>
                <td>{{ $row['label'] }}<br><small>{{ $row['origin_key'] }}</small></td>
                <td class="money">{{ $row['initial_value'] }}</td>
                <td class="money">{{ $row['final_value'] }}</td>
                <td class="money">{{ $row['delta'] }}</td>
                <td>{{ $row['category']->label() }}</td>
                <td>{{ collect($row['dimensions'])->map->label()->join(', ') }}</td>
                <td>
                    {{ collect($row['labels'])->map->label()->join(', ') }}
                    @if ($row['insufficiently_explained'])
                        <br>Variazione non sufficientemente spiegata
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody></table>
    @endif

    <h2>Dettaglio e riconciliazione</h2>
    <table><thead><tr><th>Sorgente</th><th>Centro di Costo</th><th>Fornitore</th><th>Stato</th><th>Allocato</th><th>Effettivo</th><th>Riporto</th></tr></thead><tbody>
    @forelse ($report->sources as $source)
        <tr><td>{{ $source->label }}<br><small>{{ $source->originKey }}</small></td><td>{{ $source->costCenterLabel ?? 'Non classificato' }}</td><td>{{ $source->supplierLabel ?? 'Senza Fornitore' }}</td><td>{{ $source->state ?? '—' }}</td><td class="money">{{ $source->allocation }}</td><td class="money">{{ $source->actual }}</td><td class="money">{{ $source->carryover }}</td></tr>
        @foreach ($source->corrections as $correction)<tr><td colspan="7">Correzione tardiva {{ $correction['amount'] }} · {{ $correction['reason'] }}</td></tr>@endforeach
        @foreach ($source->annotations as $annotation)<tr><td colspan="7">Annotazione: {{ $annotation['reason'] }} · Nessun impatto economico</td></tr>@endforeach
        <tr><td colspan="7"><strong>Drill-down:</strong> {{ json_encode($source->detail, JSON_UNESCAPED_UNICODE) }}</td></tr>
    @empty
        <tr><td colspan="7">Nessuna sorgente per i riferimenti e filtri selezionati.</td></tr>
    @endforelse
    </tbody></table>

    @foreach ($report->sections as $section)
        <h2>{{ $section['title'] }}</h2>
        @forelse ($section['rows'] as $row)
            <p>{{ json_encode($row instanceof App\Domain\Reporting\ReportSource ? [
                'sorgente' => $row->label,
                'allocato' => $row->allocation,
                'effettivo' => $row->actual,
                'stato' => $row->state,
                'dettaglio' => $row->detail,
            ] : $row, JSON_UNESCAPED_UNICODE) }}</p>
        @empty
            <p>Nessun dato applicabile.</p>
        @endforelse
    @endforeach
</body>
</html>
