<?php

use App\Domain\Reporting\ReportAggregator;
use App\Domain\Reporting\ReportSource;

function aggregationSource(string $key, string $allocation, string $actual, bool $hasActuals, array $overrides = []): ReportSource
{
    return new ReportSource(
        sourceType: $overrides['sourceType'] ?? 'expense',
        originId: (int) filter_var($key, FILTER_SANITIZE_NUMBER_INT),
        originKey: $key,
        copiedFromOriginKey: null,
        label: $key,
        summary: null,
        supplierId: null,
        supplierLabel: null,
        costCenterId: null,
        costCenterLabel: null,
        state: 'open',
        allocation: $allocation,
        actual: $actual,
        hasActuals: $hasActuals,
        detail: $overrides['detail'] ?? [],
    );
}

it('counts each primary source once in executive totals', function (): void {
    $sources = [
        aggregationSource('expense:1', '10.00', '8.00', true),
        aggregationSource('project:2', '30.00', '20.00', true, [
            'sourceType' => 'project',
            'detail' => ['expenses' => [
                ['allocation' => '10.00', 'actual' => '5.00'],
                ['allocation' => '20.00', 'actual' => '15.00'],
            ]],
        ]),
    ];

    expect((new ReportAggregator)->executive($sources))->toMatchArray([
        'source_count' => 2,
        'allocation' => '40.00',
        'actual' => '28.00',
        'operational_variance' => '-12.00',
    ]);
});

it('aggregates suppliers through expenses without adding project totals', function (): void {
    $project = new ReportSource(
        sourceType: 'project',
        originId: 1,
        originKey: 'project:1',
        copiedFromOriginKey: null,
        label: 'Project',
        summary: null,
        supplierId: null,
        supplierLabel: null,
        costCenterId: 1,
        costCenterLabel: 'IT',
        state: 'open',
        allocation: '100.00',
        actual: '80.00',
        hasActuals: true,
        carryover: '10.00',
        detail: ['expenses' => [
            ['supplier_id' => 1, 'supplier_label' => 'A', 'allocation' => '60.00', 'actual' => '50.00', 'source' => 'One'],
            ['supplier_id' => null, 'supplier_label' => null, 'allocation' => '30.00', 'actual' => '30.00', 'source' => 'Two'],
        ]],
    );

    $rows = collect((new ReportAggregator)->suppliers([$project]))->keyBy('key');

    expect($rows['supplier:1']['actual'])->toBe('50.00')
        ->and($rows['without_supplier']['actual'])->toBe('30.00')
        ->and($rows['carryover_without_supplier']['allocation'])->toBe('10.00')
        ->and($rows->sum(fn (array $row): float => (float) $row['allocation']))->toBe(100.0);
});
