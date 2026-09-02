<?php

use App\Filament\Forms\DateInput;
use Carbon\CarbonImmutable;

it('normalizes Italian date input to ISO format', function (): void {
    expect(DateInput::toIso('01/01/2026'))->toBe('2026-01-01')
        ->and(DateInput::toIso('2026-01-01'))->toBe('2026-01-01')
        ->and(DateInput::toIso(CarbonImmutable::parse('2026-01-01')))->toBe('2026-01-01');
});

it('formats stored dates for Italian date input', function (): void {
    expect(DateInput::toDisplay('2026-01-01'))->toBe('01/01/2026')
        ->and(DateInput::toDisplay('01/01/2026'))->toBe('01/01/2026')
        ->and(DateInput::toDisplay(CarbonImmutable::parse('2026-01-01')))->toBe('01/01/2026');
});

it('leaves invalid dates unchanged for validation to reject', function (): void {
    expect(DateInput::toIso('31/02/2026'))->toBe('31/02/2026')
        ->and(DateInput::toDisplay('2026-02-31'))->toBe('2026-02-31');
});
