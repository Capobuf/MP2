<?php

namespace Database\Factories;

use App\Domain\Expenses\ExpenseLineType;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\LateCorrection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LateCorrection> */
class LateCorrectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'exercise_id' => fn (array $attributes): int => Exercise::factory()->create([
                'company_id' => $attributes['company_id'],
                'status' => 'closed',
            ])->id,
            'expense_id' => fn (array $attributes): int => Expense::factory()->create([
                'company_id' => $attributes['company_id'],
                'exercise_id' => $attributes['exercise_id'],
                'origin' => 'manual',
            ])->id,
            'expense_line_id' => fn (array $attributes): int => ExpenseLine::factory()->actual()->create([
                'expense_id' => $attributes['expense_id'],
                'type' => ExpenseLineType::Actual,
                'amount' => '0.00',
                'note' => 'Correzione tardiva',
            ])->id,
            'closing_snapshot_id' => fn (array $attributes): int => ClosingSnapshot::query()->firstOrCreate(
                [
                    'company_id' => $attributes['company_id'],
                    'exercise_id' => $attributes['exercise_id'],
                ],
                [
                    'company_name' => Company::query()->findOrFail($attributes['company_id'])->name,
                    'exercise_year' => Exercise::query()->findOrFail($attributes['exercise_id'])->year,
                    'closed_at' => now(),
                    'closed_by_id' => User::factory()->create()->id,
                    'initial_budget_id' => null,
                    'current_budget_id' => null,
                    'total_final_allocation' => '0.00',
                    'total_closing_actual' => '0.00',
                    'total_operational_variance' => '0.00',
                    'total_consolidated_carryover' => '0.00',
                    'accepted_warnings' => [],
                    'applied_settings' => [],
                    'next_exercise_disposition' => 'not_created_management_terminated',
                    'next_exercise_id' => null,
                    'operation_id' => (string) Str::uuid(),
                ],
            )->id,
            'original_expense_line_id' => null,
            'recorded_by_id' => User::factory()->create()->id,
            'operation_id' => (string) Str::uuid(),
            'reason' => 'Motivo della correzione tardiva',
            'belongs_to_closed_exercise' => true,
            'source_type' => 'expense',
            'source_origin_id' => fn (array $attributes): int => $attributes['expense_id'],
            'source_origin_key' => fn (array $attributes): string => 'expense:'.$attributes['expense_id'],
            'source_label' => 'Spesa storica',
            'owner_context' => ['schema_version' => 1, 'container' => 'autonomous'],
            'supplier_context' => null,
        ];
    }

    public function forExercise(Exercise $exercise): static
    {
        return $this->state([
            'company_id' => $exercise->company_id,
            'exercise_id' => $exercise->id,
            'closing_snapshot_id' => fn (array $attributes): int => ClosingSnapshot::query()
                ->where('company_id', $attributes['company_id'])
                ->where('exercise_id', $attributes['exercise_id'])
                ->sole()
                ->id,
        ]);
    }
}
