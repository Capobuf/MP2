<?php

namespace Database\Factories;

use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProposalAction> */
class ProposalActionFactory extends Factory
{
    public function definition(): array
    {
        return ['proposal_id' => Proposal::factory(), 'company_id' => fn (array $attributes): int => Proposal::findOrFail($attributes['proposal_id'])->company_id, 'proposal_item_id' => null, 'sequence' => 1, 'action_type' => 'create_expense', 'payload_version' => 1, 'payload' => [], 'created_by_id' => User::factory(), 'operation_id' => Str::uuid()];
    }
}
