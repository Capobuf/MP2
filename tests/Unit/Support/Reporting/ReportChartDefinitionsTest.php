<?php

use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportResult;
use App\Domain\Reporting\ReportSource;
use App\Filament\Pages\Reports;
use App\Support\Reporting\ReportChartDefinitions;
use App\Support\Reporting\ReportPdfComposer;
use Tests\TestCase;

uses(TestCase::class);

function chartDefinitionSource(int $id, string $allocation, string $actual, string $state = 'active'): ReportSource
{
    return new ReportSource(
        sourceType: 'contract',
        originId: $id,
        originKey: 'contract:'.$id,
        copiedFromOriginKey: null,
        label: 'Contratto '.$id,
        summary: null,
        supplierId: null,
        supplierLabel: null,
        costCenterId: null,
        costCenterLabel: null,
        state: $state,
        allocation: $allocation,
        actual: $actual,
        hasActuals: true,
    );
}

/** @param array<int, ReportSource> $sources */
function chartDefinitionResult(string $kind, array $sources, array $sections = []): ReportResult
{
    return new ReportResult(
        definition: ReportDefinition::fromArray([
            'company_id' => 1,
            'exercise_id' => 1,
            'kind' => $kind,
        ]),
        header: [],
        totals: [],
        sources: $sources,
        sections: $sections,
    );
}

/** @param array<string, mixed> $chart
 * @return array<string, mixed>
 */
function chartSemantics(array $chart): array
{
    return [
        'id' => $chart['id'],
        'heading' => $chart['heading'],
        'description' => $chart['description'],
        'type' => $chart['type'],
        'variant' => $chart['variant'],
        'labels' => $chart['data']['labels'],
        'datasets' => $chart['data']['datasets'],
    ];
}

it('gives the UI the shared contract definitions including state distribution', function (): void {
    $result = chartDefinitionResult('contracts', [
        chartDefinitionSource(1, '20.00', '10.00', 'planned'),
        chartDefinitionSource(2, '40.00', '30.00'),
        chartDefinitionSource(3, '10.00', '5.00', 'cancelled'),
    ]);
    $definitions = app(ReportChartDefinitions::class)->definitions($result);
    $charts = new ReflectionMethod(Reports::class, 'charts');
    $uiCharts = $charts->invoke(app(Reports::class), $result);

    expect(array_map('chartSemantics', $uiCharts))->toBe(array_map('chartSemantics', $definitions))
        ->and(array_column($definitions, 'id'))->toBe(['contract-values', 'contract-states'])
        ->and($definitions[0]['data']['labels'])->toBe(['Contratto 1', 'Contratto 2', 'Contratto 3'])
        ->and($definitions[0]['data']['datasets'][0]['data'])->toBe([20.0, 40.0, 10.0])
        ->and($definitions[1]['data']['labels'])->toBe(['Pianificato', 'Attivo', 'Cessato', 'Annullato'])
        ->and($definitions[1]['data']['datasets'][0]['data'])->toBe([1, 1, 0, 1]);
});

it('keeps PDF sorting and limits as presentation over the shared definitions', function (): void {
    $sources = [];
    foreach (range(1, 9) as $id) {
        $sources[] = chartDefinitionSource($id, (string) ($id * 10).'.00', (string) $id.'.00');
    }
    $result = chartDefinitionResult('contracts', $sources);
    $definitions = app(ReportChartDefinitions::class);
    $semantic = collect($definitions->definitions($result));
    $pdf = collect((new ReportPdfComposer($definitions))->chartDefinitions($result, 'landscape'));
    $semanticValues = $semantic->firstWhere('id', 'contract-values');
    $pdfValues = $pdf->firstWhere('id', 'contract-values');

    expect($pdf->firstWhere('id', 'contract-states'))->toBe($semantic->firstWhere('id', 'contract-states'))
        ->and(array_intersect_key($pdfValues, array_flip(['id', 'heading', 'type', 'variant'])))
        ->toBe(array_intersect_key($semanticValues, array_flip(['id', 'heading', 'type', 'variant'])))
        ->and(array_column($pdfValues['data']['datasets'], 'label'))
        ->toBe(array_column($semanticValues['data']['datasets'], 'label'))
        ->and($semanticValues['data']['labels'])->toHaveCount(9)
        ->and($pdfValues['data']['labels'])->toBe([
            'Contratto 9', 'Contratto 8', 'Contratto 7', 'Contratto 6',
            'Contratto 5', 'Contratto 4', 'Contratto 3', 'Contratto 2',
        ])
        ->and($pdfValues['data']['datasets'][0]['data'])->toBe([90.0, 80.0, 70.0, 60.0, 50.0, 40.0, 30.0, 20.0]);
});

it('defines both transfer datasets once for UI and PDF consumers', function (): void {
    $rows = [
        ['label' => 'Progetto A', 'provisional_carryover' => '20.00', 'reprogrammed_amount' => '5.00'],
        ['label' => 'Progetto B', 'consolidated_carryover' => '10.00', 'reprogrammed_amount' => '15.00'],
    ];
    $result = chartDefinitionResult('carryovers', [], [['title' => 'Riporti', 'rows' => $rows]]);
    $definitions = app(ReportChartDefinitions::class);
    $semantic = $definitions->definitions($result)[0];
    $pdf = (new ReportPdfComposer($definitions))->chartDefinitions($result)[0];
    $charts = new ReflectionMethod(Reports::class, 'charts');
    $ui = $charts->invoke(app(Reports::class), $result)[0];

    expect(chartSemantics($ui))->toBe(chartSemantics($pdf))
        ->and(chartSemantics($ui))->toBe(chartSemantics($semantic))
        ->and(array_column($semantic['data']['datasets'], 'label'))->toBe(['Riporto', 'Riprogrammato'])
        ->and($semantic['data']['datasets'][0]['data'])->toBe([20.0, 10.0])
        ->and($semantic['data']['datasets'][1]['data'])->toBe([5.0, 15.0])
        ->and($pdf['render_description'])->toContain('Visualizzati 2 di 2 progetti');
});
