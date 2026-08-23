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
        return ['proposal_id' => Proposal::factory(), 'company_id' => fn (array $attributes): int => Proposal::findOrFail($attributes['proposal_id'])->company_id, 'proposal_item_id' => null, 'sequence' => 1, 'action_type' => 'create_expense', 'payload_version' => 1, 'payload' => [], 'reason' => null, 'status' => 'active', 'created_by_id' => User::factory(), 'withdrawn_by_id' => null, 'withdrawn_at' => null, 'withdraw_operation_id' => null, 'withdraw_reason' => null, 'operation_id' => Str::uuid()];
    }
}
