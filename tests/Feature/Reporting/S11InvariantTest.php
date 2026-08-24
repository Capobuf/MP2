<?php

use App\Domain\Reporting\ComparisonCategory;
use App\Domain\Reporting\ComparisonEngine;
use App\Domain\Reporting\ReportSource;
use App\Domain\Reporting\SecondaryLabel;

function invariantSource(string $key, string $allocation, string $actual = '0.00', bool $hasActuals = false, array $overrides = []): ReportSource
{
    return new ReportSource(
        sourceType: 'project', originId: 1, originKey: $key, copiedFromOriginKey: null, label: 'Progetto', summary: null,
        supplierId: null, supplierLabel: null, costCenterId: null, costCenterLabel: null,
        state: $overrides['state'] ?? 'open', allocation: $allocation, actual: $actual, hasActuals: $hasActuals,
        detail: $overrides['detail'] ?? [],
    );
}

it('protects INV-28.50 and INV-28.51 with primary-only exclusive counts', function (): void {
    $rows = (new ComparisonEngine)->compare(
        [invariantSource('project:1', '100.00')],
        [invariantSource('project:1', '100.00', '0.00', false, ['state' => 'closed', 'detail' => ['deferred' => true]])],
        budgetComparison: true,
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['category'])->toBe(ComparisonCategory::Modified)
        ->and($rows[0]['labels'])->toContain(SecondaryLabel::PlannedNotOccurred, SecondaryLabel::Deferred)
        ->and(array_column($rows, 'category'))->toHaveCount(1);
});
