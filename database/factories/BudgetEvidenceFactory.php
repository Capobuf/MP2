<?php

namespace Database\Factories;

use App\Models\BudgetEvidence;
use App\Models\BudgetSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BudgetEvidence> */
class BudgetEvidenceFactory extends Factory
{
    public function definition(): array
    {
        return ['budget_snapshot_id' => BudgetSnapshot::factory(), 'company_id' => fn (array $attributes): int => BudgetSnapshot::findOrFail($attributes['budget_snapshot_id'])->company_id, 'external_subject' => null, 'external_venue' => null, 'reason' => null, 'attachment_id' => null];
    }
}
