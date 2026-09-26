@php
    use Illuminate\Support\Number;
    $money = static fn (string|int|float $value): string => Number::currency((float) $value, in: 'EUR', locale: 'it');
    $kind = $report['header']['kind'];
    [$tableTitle, $initialHeading, $finalHeading] = match ($kind) {
        'budget_actual' => ['Budget e Actual per Sorgente', 'Budget Selezionato', $report['header']['actual_reference'] ?? 'Actual Selezionato'],
        'budget_current_allocation' => ['Evoluzione del Piano per Sorgente', 'Budget Selezionato', 'Allocato Corrente'],
        'budget_versions' => ['Variazioni fra le Versioni', 'Budget Iniziale', 'Budget Finale'],
        'exercises' => ['Confronto della Misura per Sorgente', 'Esercizio Iniziale', 'Esercizio Finale'],
        default => ['Variazioni per Sorgente', 'Valore Iniziale', 'Valore Finale'],
    };
@endphp

@if ($report['comparisons'] !== [])
    <section class="mp2-report-table-section" aria-labelledby="report-comparisons-title">
        <div class="mp2-report-section-heading">
            <div>
                <p class="mp2-report-kicker">Confronto Principale</p>
                <h3 id="report-comparisons-title">{{ $tableTitle }}</h3>
            </div>
        </div>
        <div class="mp2-report-table-wrap" tabindex="0">
            <table class="mp2-report-table mp2-report-comparison-table">
                <thead>
                    <tr>
                        <th scope="col">Sorgente</th>
                        <th scope="col" class="mp2-report-number">{{ $initialHeading }}</th>
                        <th scope="col" class="mp2-report-number">{{ $finalHeading }}</th>
                        <th scope="col" class="mp2-report-number">Delta</th>
                        <th scope="col">Categoria Primaria</th>
                        <th scope="col">Dimensioni Modificate</th>
                        <th scope="col">Etichette Secondarie</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['comparisons'] as $row)
                        <tr>
                            <th scope="row">
                                {{ $row['label'] }}
                                <small>
                                    {{ $this->sourceTypeLabel($row['source_type']) }}
                                    @if ($row['cost_center']) · {{ $row['cost_center'] }} @endif
                                    @if ($row['supplier']) · {{ $row['supplier'] }} @endif
                                </small>
                                @if ($row['derived_from_origin_key'])
                                    <small>Derivata da {{ $row['derived_from_origin_key'] }}</small>
                                @endif
                            </th>
                            <td class="mp2-report-number">{{ $money($row['initial_value']) }}</td>
                            <td class="mp2-report-number">{{ $money($row['final_value']) }}</td>
                            <td class="mp2-report-number">{{ $money($row['delta']) }}</td>
                            <td><span class="mp2-report-category" data-category="{{ $row['category_key'] }}">{{ $row['category'] }}</span></td>
                            <td>
                                <div class="mp2-report-chip-list">
                                    @forelse ($row['dimensions'] as $dimension)<span>{{ $dimension }}</span>@empty<span>—</span>@endforelse
                                </div>
                            </td>
                            <td>
                                <div class="mp2-report-chip-list">
                                    @forelse ($row['labels'] as $label)<span>{{ $label }}</span>@empty<span>—</span>@endforelse
                                </div>
                                @if ($row['insufficiently_explained'])
                                    <p class="mp2-report-warning">Variazione non sufficientemente spiegata</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
