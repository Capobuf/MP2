<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    @php
        use Carbon\CarbonImmutable;

        $overview = $document['overview'];
        $proposal = $overview['proposal'];
        $items = collect($overview['items']);
        $selected = static fn (string $block): bool => in_array($block, $document['selected_blocks'], true);
        $font = base64_encode(file_get_contents(resource_path('fonts/geist-latin-wght-normal.woff2')));
    @endphp
    <style>
        @font-face { font-family: Geist; src: url("data:font/woff2;base64,{{ $font }}") format("woff2"); font-weight: 100 900; font-style: normal; }
        @page {
            size: A4 {{ $document['orientation'] }};
            margin: 12mm 12mm 16mm;
            @bottom-left {
                content: "Proposta #{{ $document['proposal_id'] }} · Esercizio {{ $proposal['exercise'] }} · Generato il {{ CarbonImmutable::parse($document['generated_at'])->format('d/m/Y H:i') }}";
                font-family: Geist; font-size: 7pt; color: #667b7d; border-top: 0.4pt solid #d6e1df; padding-top: 2mm;
            }
            @bottom-right {
                content: "Pagina " counter(page) " di " counter(pages);
                font-family: Geist; font-size: 7pt; color: #667b7d; border-top: 0.4pt solid #d6e1df; padding-top: 2mm;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; color: #15323b; background: #fff; font-family: Geist; font-size: 9pt; line-height: 1.35; }
        h1, h2, h3, h4, p, dl, dd { margin: 0; }
        h1 { color: #0b1d25; font-size: 23pt; line-height: 1.1; letter-spacing: -0.03em; }
        h2 { margin: 6mm 0 2mm; color: #0b1d25; font-size: 13pt; break-after: avoid; }
        h3 { color: #0b1d25; font-size: 11pt; break-after: avoid; }
        h4 { margin: 3mm 0 1mm; font-size: 9pt; break-after: avoid; }
        .document-header { display: table; width: 100%; margin-bottom: 5mm; padding-bottom: 3mm; border-bottom: 1.3pt solid #39d5c4; }
        .header-logo, .header-copy { display: table-cell; vertical-align: middle; }
        .header-logo { width: 27mm; padding-right: 5mm; }
        .header-logo img { display: block; max-width: 22mm; max-height: 15mm; }
        .company { margin-bottom: 1mm; font-size: 10pt; }
        .header-meta { margin-top: 2mm; color: #526762; font-size: 8pt; }
        .facts { display: flex; flex-wrap: wrap; gap: 3mm 6mm; margin-bottom: 3mm; }
        .facts div { min-width: 32mm; break-inside: avoid; }
        .facts dt { color: #667b7d; font-size: 7.5pt; }
        .facts dd { margin-top: 0.5mm; color: #15323b; font-weight: 600; }
        .metrics div { min-width: 42mm; }
        .metrics dd { font-size: 13pt; font-variant-numeric: tabular-nums; }
        .notice { margin: 2mm 0; padding: 2.5mm 3mm; border-left: 1.2mm solid #39d5c4; background: #f6f9f8; break-inside: avoid; }
        .notice.attention { border-left-color: #f59e0b; }
        .muted { color: #667b7d; }
        table { width: 100%; margin-bottom: 4mm; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th, td { padding: 1.5mm 1.2mm; border-bottom: 0.4pt solid #d6e1df; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { border-top: 0.6pt solid #91a3a8; background: #f6f9f8; color: #526762; font-size: 7.3pt; font-weight: 600; }
        td { font-size: 8pt; }
        .money { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .source { margin-bottom: 5mm; padding-top: 3mm; border-top: 0.6pt solid #91a3a8; }
        .source-heading { display: table; width: 100%; margin-bottom: 2mm; }
        .source-heading h3, .source-heading p { display: table-cell; }
        .source-heading p { text-align: right; color: #526762; }
        .source-summary { margin: 1mm 0 2mm; color: #526762; }
        .compact-list { margin: 1mm 0 3mm; padding-left: 5mm; }
        .compact-list li { margin-bottom: 1mm; }
        .decision { margin-bottom: 2mm; padding: 2mm 2.5mm; border: 0.4pt solid #d6e1df; break-inside: avoid; }
        .decision strong { display: block; }
        .page-break { break-before: page; }
        .keep { break-inside: avoid; }
        .landscape h1 { font-size: 20pt; }
        .landscape th, .landscape td { padding-top: 1mm; padding-bottom: 1mm; }
    </style>
</head>
<body class="{{ $document['orientation'] }}">
    <header class="document-header">
        @if ($document['logo'] !== null && $selected('logo'))
            <div class="header-logo"><img src="{{ $document['logo'] }}" alt="Logo {{ $document['company']['name'] }}"></div>
        @endif
        <div class="header-copy">
            <p class="company">{{ $document['company']['name'] }}</p>
            <h1>Proposta #{{ $document['proposal_id'] }}</h1>
            <p class="header-meta">Esercizio {{ $proposal['exercise'] }} · {{ $proposal['purpose'] }} · {{ $proposal['status'] }}</p>
        </div>
    </header>

    @if ($selected('summary'))
        <section data-block="summary">
            <h2>Riepilogo della Proposta</h2>
            <dl class="facts">
                <div><dt>Esercizio</dt><dd>{{ $proposal['exercise'] }}</dd></div>
                <div><dt>Finalità</dt><dd>{{ $proposal['purpose'] }}</dd></div>
                <div><dt>Stato</dt><dd>{{ $proposal['status'] }}</dd></div>
                <div><dt>Budget di riferimento</dt><dd>{{ $proposal['reference_budget'] }}</dd></div>
                @if ($proposal['budget'] !== null)
                    <div><dt>Budget materializzato</dt><dd>{{ $proposal['budget'] }}</dd></div>
                @else
                    <div><dt>Budget proposto</dt><dd>{{ $proposal['result_budget'] }}</dd></div>
                @endif
                <div><dt>Creata da</dt><dd>{{ $proposal['created_by'] }} · {{ $proposal['created_at'] }}</dd></div>
                @if ($proposal['terminal_by'] !== null)<div><dt>Conclusa da</dt><dd>{{ $proposal['terminal_by'] }} · {{ $proposal['terminal_at'] }}</dd></div>@endif
            </dl>
            <dl class="facts metrics">
                <div><dt>Allocato di base</dt><dd>{{ $proposal['allocation_before'] }}</dd></div>
                <div><dt>Allocato pianificato</dt><dd>{{ $proposal['allocation_after'] }}</dd></div>
                <div><dt>Variazione</dt><dd>{{ $proposal['allocation_delta'] }}</dd></div>
                <div><dt>Effettivo di contesto</dt><dd>{{ $proposal['actual'] }}</dd></div>
            </dl>
            <p class="muted">{{ $proposal['context'] }}</p>
        </section>
    @endif

    @if ($selected('verification'))
        <section data-block="verification">
            <h2>Verifiche e Readiness</h2>
            <div class="notice {{ $overview['verification']['ready'] ? '' : 'attention' }}">
                <strong>{{ $overview['verification']['label'] }}</strong>
                @if (filled($overview['verification']['message']))<p>{{ $overview['verification']['message'] }}</p>@endif
                @if ($overview['verification']['blocks'] !== [])
                    <ul class="compact-list">@foreach ($overview['verification']['blocks'] as $block)<li>{{ $block }}</li>@endforeach</ul>
                @endif
            </div>
            <dl class="facts">
                @foreach ($overview['readiness_counts'] as $count)
                    <div><dt>{{ $count['label'] }}</dt><dd>{{ $count['count'] }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($selected('impacts'))
        <section data-block="impacts">
            <h2>Impatti per Esercizio</h2>
            <table>
                <thead><tr><th>Esercizio</th><th>Applicazione</th><th class="money">Prima</th><th class="money">Dopo</th><th class="money">Variazione</th></tr></thead>
                <tbody>
                    @foreach ($overview['impacts'] as $impact)
                        <tr><td>{{ $impact['year'] }}</td><td>{{ $impact['application'] }}</td><td class="money">{{ $impact['before'] }}</td><td class="money">{{ $impact['after'] }}</td><td class="money">{{ $impact['delta'] }}</td></tr>
                        @foreach ([...$impact['warnings'], ...$impact['blocks']] as $message)
                            <tr><td colspan="5" class="muted">{{ $message }}</td></tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if ($selected('sources'))
        <section data-block="sources">
            <h2>Elenco Sorgenti</h2>
            <table>
                <thead><tr><th>Sorgente</th><th>Tipo</th><th>Natura</th><th>Readiness</th><th>Stato base → risultato</th><th class="money">Base</th><th class="money">Risultato</th><th class="money">Delta</th></tr></thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td><strong>{{ $item['label'] }}</strong><br><span class="muted">{{ $item['cost_center'] }} · {{ $item['supplier'] }}</span></td>
                            <td>{{ $item['type_label'] }}</td><td>{{ $item['plan_role'] }}</td><td>{{ $item['readiness'] }}</td><td>{{ $item['state_before'] }} → {{ $item['state_after'] }}</td>
                            <td class="money">{{ $item['allocation_before'] }}</td><td class="money">{{ $item['allocation_after'] }}</td><td class="money">{{ $item['allocation_delta'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @foreach (['expense', 'project', 'contract'] as $type)
        @if ($selected('details:'.$type))
            <section data-block="details:{{ $type }}">
                <h2>{{ ['expense' => 'Dettagli Spese', 'project' => 'Dettagli Progetti', 'contract' => 'Dettagli Contratti'][$type] }}</h2>
                @foreach ($items->where('type_value', $type) as $item)
                    @php($detail = $item['details'])
                    <article class="source">
                        <div class="source-heading"><h3>{{ $item['label'] }}</h3><p>{{ $item['state_before'] }} → {{ $item['state_after'] }}</p></div>
                        <p class="source-summary">{{ $item['cost_center'] }} · {{ $item['supplier'] }} · {{ $item['readiness'] }}</p>

                        @if ($type === 'expense')
                            <dl class="facts"><div><dt>Esercizio</dt><dd>{{ $detail['exercise'] }}</dd></div><div><dt>Contenitore</dt><dd>{{ $detail['owner'] }}</dd></div><div><dt>Stato</dt><dd>{{ $detail['state'] }}</dd></div></dl>
                            @if ($detail['lines'] !== [])
                                <table><thead><tr><th>Nota</th><th>Quantità</th><th>Unità</th><th class="money">Unitario</th><th class="money">Importo</th></tr></thead><tbody>
                                @foreach ($detail['lines'] as $line)<tr><td>{{ $line['note'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ $line['unit_of_measure'] }}</td><td class="money">{{ $line['unit_amount'] }}</td><td class="money">{{ $line['amount'] }}</td></tr>@endforeach
                                </tbody></table>
                            @endif
                        @elseif ($type === 'project')
                            <dl class="facts"><div><dt>Stato iniziale</dt><dd>{{ $detail['initial_state'] }} · {{ $detail['initial_date'] }}</dd></div><div><dt>Modalità rinvio</dt><dd>{{ $detail['deferral_mode'] }}</dd></div><div><dt>Riporto</dt><dd>{{ $detail['carryover'] }}</dd></div><div><dt>Riprogrammato</dt><dd>{{ $detail['reprogrammed'] }}</dd></div></dl>
                            @if ($detail['transitions'] !== [])
                                <h4>Transizioni</h4><table><thead><tr><th>Da</th><th>A</th><th>Data</th><th>Stato</th><th>Motivo</th></tr></thead><tbody>
                                @foreach ($detail['transitions'] as $transition)<tr><td>{{ $transition['from'] }}</td><td>{{ $transition['to'] }}</td><td>{{ $transition['date'] }}</td><td>{{ $transition['status'] }}</td><td>{{ $transition['reason'] ?? '—' }}</td></tr>@endforeach
                                </tbody></table>
                            @endif
                        @else
                            <dl class="facts"><div><dt>Data inizio</dt><dd>{{ $detail['start_date'] }}</dd></div><div><dt>Scadenza</dt><dd>{{ $detail['expiry_date'] }}</dd></div><div><dt>Rinnovo automatico</dt><dd>{{ $detail['automatic_renewal'] }}</dd></div><div><dt>Durata rinnovo</dt><dd>{{ $detail['renewal_duration'] }}</dd></div><div><dt>Preavviso</dt><dd>{{ $detail['notice'] }}</dd></div></dl>
                            @if ($detail['conditions'] !== [])
                                <h4>Condizioni economiche</h4><table><thead><tr><th>Origine</th><th>Ciclo</th><th>Attribuzione</th><th>Validità</th><th>Stato</th><th class="money">Importo</th></tr></thead><tbody>
                                @foreach ($detail['conditions'] as $condition)<tr><td>{{ $condition['origin'] }}</td><td>{{ $condition['cycle'] }}</td><td>{{ $condition['attribution'] }}</td><td>{{ $condition['valid_from'] }} → {{ $condition['valid_to'] }}</td><td>{{ $condition['status'] }}</td><td class="money">{{ $condition['amount'] }}</td></tr>@endforeach
                                </tbody></table>
                            @endif
                            @if ($detail['condition_changes'] !== [])
                                <h4>Modifiche pianificate</h4><table><thead><tr><th>Condizione</th><th>Ciclo</th><th>Attribuzione</th><th>Decorrenza</th><th class="money">Importo</th></tr></thead><tbody>
                                @foreach ($detail['condition_changes'] as $change)<tr><td>{{ $change['condition'] }}</td><td>{{ $change['cycle'] }}</td><td>{{ $change['attribution'] }}</td><td>{{ $change['effective_date'] }}</td><td class="money">{{ $change['amount'] }}</td></tr>@endforeach
                                </tbody></table>
                            @endif
                            @if ($detail['lifecycle'] !== [])
                                <h4>Ciclo di vita</h4><table><thead><tr><th>Evento</th><th>Data dichiarata</th><th>Data efficace</th><th>Stato</th><th>Motivo</th></tr></thead><tbody>
                                @foreach ($detail['lifecycle'] as $fact)<tr><td>{{ $fact['type'] }}</td><td>{{ $fact['declared_date'] }}</td><td>{{ $fact['effective_date'] }}</td><td>{{ $fact['status'] }}</td><td>{{ $fact['reason'] ?? '—' }}</td></tr>@endforeach
                                </tbody></table>
                            @endif
                        @endif
                        @if (in_array($type, ['project', 'contract'], true) && $detail['expenses'] !== [])
                            <h4>Spese che compongono l’Allocato</h4>
                            @foreach ($detail['expenses'] as $expense)
                                <p><strong>{{ $expense['description'] }}</strong> · {{ $expense['supplier'] }} · Esercizio {{ $expense['exercise'] }} · {{ $expense['total'] }}</p>
                                <table><thead><tr><th>Nota</th><th>Quantità</th><th>Unità</th><th class="money">Unitario</th><th class="money">Importo</th></tr></thead><tbody>
                                    @foreach ($expense['lines'] as $line)<tr><td>{{ $line['note'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ $line['unit_of_measure'] }}</td><td class="money">{{ $line['unit_amount'] }}</td><td class="money">{{ $line['amount'] }}</td></tr>@endforeach
                                </tbody></table>
                            @endforeach
                        @endif
                    </article>
                @endforeach
            </section>
        @endif
    @endforeach

    @if ($selected('decisions'))
        <section data-block="decisions">
            <h2>Storico Decisioni</h2>
            @foreach ($items as $item)
                @foreach ($item['actions'] as $action)
                    <article class="decision">
                        <strong>#{{ $action['sequence'] }} · {{ $item['label'] }} · {{ $action['label'] }}</strong>
                        <p>{{ $action['status'] }} · {{ $action['created_by'] }}@if (filled($action['reason'])) · {{ $action['reason'] }}@endif</p>
                        @if ($action['withdrawn_by'] !== null)<p class="muted">Ritirata da {{ $action['withdrawn_by'] }} il {{ $action['withdrawn_at'] }} · {{ $action['withdraw_reason'] }}</p>@endif
                    </article>
                @endforeach
            @endforeach
        </section>
    @endif
</body>
</html>
