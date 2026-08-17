<?php

use App\Domain\Expenses\ManualExpenseLine;
use App\Models\Company;
use App\Models\Exercise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps amount authoritative and requires an explicit mismatch acknowledgement', function () {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $exercise = Exercise::factory()->for($company)->create(['year' => now('Europe/Rome')->year]);
    $input = [
        'type' => 'estimate',
        'amount' => '310.00',
        'quantity' => '3.000000',
        'unit_amount' => '100.000000',
    ];

    expect(fn () => ManualExpenseLine::validate($input, $company, $exercise))
        ->toThrow(ValidationException::class);

    $validated = ManualExpenseLine::validate([...$input, 'amount_warning_acknowledged' => true], $company, $exercise);
    expect($validated['amount'])->toBe('310.00')
        ->and(ManualExpenseLine::suggestedAmount('3.000000', '100.000000'))->toBe('300.00');
});

it('enforces estimate, actual, zero and company-year rules', function () {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $current = Exercise::factory()->for($company)->create(['year' => now('Europe/Rome')->year]);
    $future = Exercise::factory()->for($company)->create(['year' => now('Europe/Rome')->year + 1]);

    expect(fn () => ManualExpenseLine::validate(['type' => 'estimate', 'amount' => '-1'], $company, $current))
        ->toThrow(ValidationException::class)
        ->and(fn () => ManualExpenseLine::validate(['type' => 'actual', 'amount' => '-1'], $company, $current))
        ->toThrow(ValidationException::class)
        ->and(fn () => ManualExpenseLine::validate(['type' => 'estimate', 'amount' => '0'], $company, $current))
        ->toThrow(ValidationException::class)
        ->and(fn () => ManualExpenseLine::validate(['type' => 'actual', 'amount' => '1'], $company, $future))
        ->toThrow(ValidationException::class)
        ->and(ManualExpenseLine::validate(['type' => 'actual', 'amount' => '-1', 'note' => 'Rimborso'], $company, $current)['amount'])
        ->toBe('-1.00');
});
