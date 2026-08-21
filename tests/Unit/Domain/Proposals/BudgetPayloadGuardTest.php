<?php

use App\Domain\Proposals\BudgetPayloadGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('rejects forbidden budget keys recursively', function (): void {
    expect(fn () => BudgetPayloadGuard::assertPlanOnly(['detail' => ['forecast' => '10.00']]))->toThrow(ValidationException::class)
        ->and(fn () => BudgetPayloadGuard::assertPlanOnly(['actual_context' => []]))->toThrow(ValidationException::class)
        ->and(BudgetPayloadGuard::assertPlanOnly(['estimate_lines' => []]))->toBeNull();
});
