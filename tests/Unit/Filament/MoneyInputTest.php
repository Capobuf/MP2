<?php

use App\Filament\Forms\MoneyInput;

it('normalizes monetary input without floating point conversion', function (string $input, string $expected): void {
    expect(MoneyInput::normalizeInput($input))->toBe($expected);
})->with([
    ['2.074', '2074'],
    ['2074', '2074'],
    ['2074,00', '2074.00'],
    ['2074.00', '2074.00'],
    ['2.074,00', '2074.00'],
    ['1.234.567,89', '1234567.89'],
    ['-2.074,50', '-2074.50'],
    [' 2.074,50 ', '2074.50'],
    ['99.999.999.999.999.999,99', '99999999999999999.99'],
]);

it('leaves malformed monetary input unchanged for validation to reject', function (string $input): void {
    expect(MoneyInput::normalizeInput($input))->toBe($input);
})->with(['2.07,40', '2..074', '2.074.00', '2,074,00', '2074,', 'EUR 2074', '1e3']);
