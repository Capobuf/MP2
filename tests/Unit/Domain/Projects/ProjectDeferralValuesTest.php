<?php

use App\Domain\Projects\ProjectDeferralValues;

it('calculates the canonical Project residual and transferable maximum', function (string $allocation, string $actual, string $residual, string $maximum): void {
    expect(ProjectDeferralValues::residual($allocation, $actual))->toBe($residual)
        ->and(ProjectDeferralValues::maximumTransferable($allocation, $actual))->toBe($maximum);
})->with([
    'INV-28.11 ordinary residual' => ['10000.00', '6000.00', '4000.00', '4000.00'],
    'INV-28.13 negative Actual capped by allocation' => ['10000.00', '-1000.00', '11000.00', '10000.00'],
    'INV-28.13 zero allocation remains zero transferable' => ['0.00', '-1000.00', '1000.00', '0.00'],
    'overspent Project' => ['4000.00', '6000.00', '0.00', '0.00'],
]);
