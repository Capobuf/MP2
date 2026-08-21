<?php

namespace App\Actions\Proposals;

use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Proposals\ProposalActionType;
use App\Models\Expense;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CopyExpenseIntoProposal
{
    public function __construct(private PlanExpense $planExpense) {}

    public function execute(User $actor, Proposal $proposal, Expense $source, string $operationId, int $expectedRevision): ProposalAction
    {
        $source->loadMissing(['exercise', 'lines']);
        if ($source->company_id !== $proposal->company_id || $source->project_id !== null || $source->contract_id !== null || $source->exercise_id === $proposal->exercise_id || ! $source->exercise->isOpen() || $source->isReversed()) {
            throw ValidationException::withMessages(['source_expense_id' => 'Selezionare una Spesa autonoma attiva di un altro Esercizio Aperto della stessa Azienda.']);
        }
        $lines = $source->lines->filter(fn ($line): bool => $line->lineType() === ExpenseLineType::Estimate && ! $line->isAnnulled())->map(fn ($line): array => ['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => (string) $line->amount, 'note' => $line->note, 'annulled' => false])->values()->all();

        return $this->planExpense->create($actor, $proposal, ['source_expense_id' => $source->id, 'target_exercise_id' => $proposal->exercise_id, 'description' => $source->description, 'notes' => $source->notes, 'supplier_id' => $source->supplier_id, 'cost_center_id' => $source->direct_cost_center_id, 'estimate_lines' => $lines], null, $operationId, $expectedRevision, ProposalActionType::CopyExpense, $source->originKey());
    }
}
