<?php

use App\Domain\Expenses\Decimal;

it('calculates exact signed money without floating point', function () {
    expect(Decimal::sum(['1000.00', '500.00', '-0.01']))->toBe('1499.99')
        ->and(Decimal::subtract('1000.00', '1500.00'))->toBe('-500.00')
        ->and(Decimal::multiply('3.000000', '100.000000'))->toBe('300.00')
        ->and(Decimal::multiply('1.005000', '1.000000'))->toBe('1.01')
        ->and(Decimal::money('-1.005'))->toBe('-1.01')
        ->and(Decimal::compare('0.000001', '0'))->toBe(1);
});
