<?php

namespace Database\Factories;

use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BudgetSourceRow> */
class BudgetSourceRowFactory extends Factory
{
    public function definition(): array
    {
        return ['budget_snapshot_id' => BudgetSnapshot::factory(), 'company_id' => fn (array $attributes): int => BudgetSnapshot::findOrFail($attributes['budget_snapshot_id'])->company_id, 'source_type' => 'expense', 'origin_id' => fake()->unique()->numberBetween(1, 1000000), 'origin_key' => 'expense:'.Str::uuid(), 'proposal_item_id' => Str::uuid(), 'label' => fake()->sentence(3), 'cost_center_label' => 'Non classificato', 'approved_estimates' => '0.00', 'approved_carryover' => '0.00', 'approved_allocation' => '0.00', 'detail_version' => 1, 'detail' => []];
    }
}
