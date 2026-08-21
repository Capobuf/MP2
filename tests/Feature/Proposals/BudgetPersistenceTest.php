<?php

use App\Models\BudgetSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects budget update and physical deletion', function (): void {
    $budget = BudgetSnapshot::factory()->create();

    expect(fn () => $budget->update(['total_approved_allocation' => '1.00']))
        ->toThrow(LogicException::class)
        ->and(fn () => $budget->delete())
        ->toThrow(LogicException::class);
});
