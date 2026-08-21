<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Attachment> */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'proposal_id' => null,
            'contract_id' => fn (array $attributes): int => Contract::factory()->create(['company_id' => $attributes['company_id']])->id,
            'expense_id' => null,
            'expense_line_id' => null,
            'storage_disk' => 'local',
            'storage_path' => 'attachments/'.Str::uuid(),
            'original_name' => 'documento.pdf',
            'media_type' => 'application/pdf',
            'size_bytes' => 100,
            'sha256' => hash('sha256', fake()->uuid()),
            'uploaded_by_id' => User::factory(),
            'detached_at' => null,
            'detached_by_id' => null,
        ];
    }

    public function forContract(Contract $contract): static
    {
        return $this->state(fn (): array => [
            'company_id' => $contract->company_id,
            'contract_id' => $contract->id,
            'expense_id' => null,
            'expense_line_id' => null,
        ]);
    }

    public function forExpense(Expense $expense): static
    {
        return $this->state(fn (): array => [
            'company_id' => $expense->company_id,
            'contract_id' => null,
            'expense_id' => $expense->id,
            'expense_line_id' => null,
        ]);
    }

    public function forExpenseLine(ExpenseLine $line): static
    {
        return $this->state(fn (): array => [
            'company_id' => $line->expense->company_id,
            'contract_id' => null,
            'expense_id' => null,
            'expense_line_id' => $line->id,
        ]);
    }

    public function forProposal(Proposal $proposal): static
    {
        return $this->state(fn (): array => ['company_id' => $proposal->company_id, 'proposal_id' => $proposal->id, 'contract_id' => null, 'expense_id' => null, 'expense_line_id' => null]);
    }
}
