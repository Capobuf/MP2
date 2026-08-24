<?php

use App\Domain\Reporting\ComparisonCategory;
use App\Domain\Reporting\ComparisonEngine;
use App\Domain\Reporting\ModificationDimension;
use App\Domain\Reporting\ReportSource;
use App\Domain\Reporting\SecondaryLabel;
use Carbon\CarbonImmutable;

function reportSource(string $key, string $allocation = '0.00', string $actual = '0.00', bool $hasActuals = false, array $overrides = []): ReportSource
{
    return new ReportSource(
        sourceType: $overrides['sourceType'] ?? 'expense',
        originId: $overrides['originId'] ?? (int) filter_var($key, FILTER_SANITIZE_NUMBER_INT),
        originKey: $key,
        copiedFromOriginKey: $overrides['copiedFromOriginKey'] ?? null,
        label: $overrides['label'] ?? $key,
        summary: null,
        supplierId: $overrides['supplierId'] ?? null,
        supplierLabel: null,
        costCenterId: $overrides['costCenterId'] ?? null,
        costCenterLabel: null,
        state: $overrides['state'] ?? 'open',
        allocation: $allocation,
        actual: $actual,
        hasActuals: $hasActuals,
        carryover: $overrides['carryover'] ?? '0.00',
        detail: $overrides['detail'] ?? [],
        corrections: $overrides['corrections'] ?? [],
        annotations: $overrides['annotations'] ?? [],
    );
}

it('assigns exactly one primary category to every unique source', function (): void {
    $rows = (new ComparisonEngine)->compare(
        [reportSource('expense:1', '10.00'), reportSource('expense:2', '20.00'), reportSource('expense:3', '30.00')],
        [reportSource('expense:1', '10.00'), reportSource('expense:2', '25.00'), reportSource('expense:4', '40.00')],
    );

    expect(array_column($rows, 'category'))->toEqualCanonicalizing([
        ComparisonCategory::Unchanged,
        ComparisonCategory::Modified,
        ComparisonCategory::Removed,
        ComparisonCategory::Added,
    ])->and($rows)->toHaveCount(4);
});

it('reports all changed dimensions without changing the primary count', function (): void {
    $rows = (new ComparisonEngine)->compare(
        [reportSource('project:1', '10.00', '5.00', true, ['supplierId' => 1, 'state' => 'open'])],
        [reportSource('project:1', '20.00', '7.00', true, ['supplierId' => 2, 'state' => 'closed'])],
    );

    expect($rows[0]['category'])->toBe(ComparisonCategory::Modified)
        ->and($rows[0]['dimensions'])->toContain(
            ModificationDimension::AllocationOrEstimate,
            ModificationDimension::Actual,
            ModificationDimension::Supplier,
            ModificationDimension::StateOrTransitions,
        );
});

it('uses has actuals rather than a net zero balance for unplanned', function (): void {
    $rows = (new ComparisonEngine)->compare(
        [reportSource('expense:1')],
        [reportSource('expense:1', '0.00', '0.00', true)],
        budgetComparison: true,
    );

    expect($rows[0]['labels'])->toContain(SecondaryLabel::Unplanned);
});

it('distinguishes terminal planned not occurred from an open source without actuals', function (): void {
    $engine = new ComparisonEngine;
    $budget = [reportSource('project:1', '100.00')];

    expect($engine->compare($budget, [reportSource('project:1', '100.00')], true, false)[0]['labels'])
        ->toContain(SecondaryLabel::WithoutActuals)
        ->not->toContain(SecondaryLabel::PlannedNotOccurred)
        ->and($engine->compare($budget, [reportSource('project:1', '100.00', '0.00', false, ['state' => 'closed'])], true, false)[0]['labels'])
        ->toContain(SecondaryLabel::PlannedNotOccurred);
});

it('does not merge copied or similar expenses and exposes derivation only', function (): void {
    $rows = (new ComparisonEngine)->compare(
        [reportSource('expense:1', '50.00', overrides: ['label' => 'Canone'])],
        [reportSource('expense:2', '50.00', overrides: ['label' => 'Canone', 'copiedFromOriginKey' => 'expense:1'])],
    );

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'category'))->toEqualCanonicalizing([ComparisonCategory::Removed, ComparisonCategory::Added])
        ->and(collect($rows)->firstWhere('origin_key', 'expense:2')['derived_from_origin_key'])->toBe('expense:1');
});

it('applies contract expiry only for an explicit containing interval', function (): void {
    $contract = reportSource('contract:1', overrides: ['sourceType' => 'contract', 'detail' => ['deadline' => '2026-09-10']]);
    $engine = new ComparisonEngine;

    expect($engine->compare([], [$contract])[0]['labels'])->not->toContain(SecondaryLabel::ContractExpiryInSelectedInterval)
        ->and($engine->compare([], [$contract], dateFrom: CarbonImmutable::parse('2026-09-01'), dateTo: CarbonImmutable::parse('2026-09-30'))[0]['labels'])
        ->toContain(SecondaryLabel::ContractExpiryInSelectedInterval);
});

it('uses the explicitly requested economic measure for the delta', function (): void {
    $rows = (new ComparisonEngine)->compare(
        [reportSource('expense:1', '100.00')],
        [reportSource('expense:1', '120.00', '90.00', true)],
        initialMeasure: 'allocation',
        finalMeasure: 'allocation',
    );

    expect($rows[0]['initial_value'])->toBe('100.00')
        ->and($rows[0]['final_value'])->toBe('120.00')
        ->and($rows[0]['delta'])->toBe('20.00');
});
