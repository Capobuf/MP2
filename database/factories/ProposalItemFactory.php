<?php

namespace Database\Factories;

use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProposalItem> */
class ProposalItemFactory extends Factory
{
    public function definition(): array
    {
        return ['proposal_item_id' => Str::uuid(), 'proposal_id' => Proposal::factory(), 'company_id' => fn (array $attributes): int => Proposal::findOrFail($attributes['proposal_id'])->company_id, 'source_type' => 'expense', 'expense_id' => null, 'project_id' => null, 'contract_id' => null, 'baseline_revision' => null, 'baseline_fingerprint' => null, 'baseline' => ['plan_baseline' => [], 'actual_context' => []], 'result' => [], 'readiness_state' => 'aligned', 'readiness_reasons' => [], 'read_only_source' => false];
    }
}
