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
        costCenterId: $overrides['costCenterId'] ?? null,
        costCenterLabel: $overrides['costCenterLabel'] ?? null,
        state: 'open',
        allocation: $allocation,
        actual: $actual,
        hasActuals: $hasActuals,
        carryover: $overrides['carryover'] ?? '0.00',
        costCenterLineage: $overrides['costCenterLineage'] ?? [],
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

it('separates direct and branch cost center totals without duplicating company totals', function (): void {
    $it = ['cost_center_id' => 1, 'cost_center_label' => 'IT'];
    $sources = [
        aggregationSource('expense:1', '10.00', '8.00', true, [
            'carryover' => '1.00',
            'costCenterId' => 1,
            'costCenterLineage' => [$it],
        ]),
        aggregationSource('project:2', '20.00', '15.00', true, [
            'sourceType' => 'project',
            'carryover' => '2.00',
            'costCenterId' => 2,
            'costCenterLineage' => [$it, ['cost_center_id' => 2, 'cost_center_label' => 'Software']],
        ]),
        aggregationSource('project:3', '30.00', '25.00', true, [
            'sourceType' => 'project',
            'carryover' => '3.00',
            'costCenterId' => 3,
            'costCenterLineage' => [$it, ['cost_center_id' => 3, 'cost_center_label' => 'Hardware']],
        ]),
    ];

    $rows = collect((new ReportAggregator)->costCenters($sources))->keyBy('cost_center_id');
    $company = (new ReportAggregator)->executive($sources);

    expect($rows[1])->toMatchArray([
        'direct_allocation' => '10.00',
        'branch_allocation' => '60.00',
        'direct_actual' => '8.00',
        'branch_actual' => '48.00',
        'direct_operational_variance' => '-2.00',
        'branch_operational_variance' => '-12.00',
        'direct_carryover' => '1.00',
        'branch_carryover' => '6.00',
    ])->and($rows[2]['branch_allocation'])->toBe('20.00')
        ->and($rows[3]['branch_allocation'])->toBe('30.00')
        ->and($company['allocation'])->toBe('60.00')
        ->and($company['actual'])->toBe('48.00')
        ->and($company['carryover'])->toBe('6.00');
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
        carryover: '25.00',
        receivedCarryover: '10.00',
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
