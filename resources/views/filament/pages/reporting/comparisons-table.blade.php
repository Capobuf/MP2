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

@if ($comparisonCategory !== null)
    <p>Categoria visualizzata: {{ \App\Domain\Reporting\ComparisonCategory::from($comparisonCategory)->label() }}.
        <a href="{{ $this->reportUrl(['comparisonCategory' => null]) }}">Mostra tutte le categorie</a>
    </p>
@endif

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
                @foreach ($report['comparisons'] as $row)
                    <tbody x-data="{ expanded: false }" class="mp2-report-row-group">
                        <tr>
                            <th scope="row">
                                <button type="button" class="mp2-report-drilldown-trigger" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded">{{ $row['label'] }}<x-filament::icon icon="heroicon-m-chevron-down" x-bind:class="{ 'is-expanded': expanded }" /></button>
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
                        <tr class="mp2-report-detail-row" x-show="expanded" x-cloak>
                            <td colspan="7">
                                @foreach (['initial_source' => $initialHeading, 'final_source' => $finalHeading] as $key => $heading)
                                    <h4>{{ $heading }}</h4>
                                    @if ($row[$key] !== null)
                                        @include('filament.pages.reporting.drilldown', ['source' => $row[$key]])
                                    @else
                                        <p>Sorgente assente in questo riferimento.</p>
                                    @endif
                                @endforeach
                            </td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        </div>
    </section>
@endif

@if ($comparisonCategory !== null && $report['comparisons'] === [])
    <p>Nessuna sorgente nella categoria selezionata.</p>
@endif
