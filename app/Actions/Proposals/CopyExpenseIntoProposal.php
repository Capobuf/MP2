<?php

namespace App\Actions\Proposals;

use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CopyExpenseIntoProposal
{
    public function __construct(private PlanExpense $planExpense) {}

    public function execute(User $actor, Proposal $proposal, Expense $source, string $operationId, int $expectedRevision): ProposalAction
    {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
        }

        return DB::transaction(function () use ($actor, $proposal, $source, $operationId, $expectedRevision): ProposalAction {
            $company = Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
            $lockedProposal = Proposal::query()->with('company')->lockForUpdate()->findOrFail($proposal->id);
            Gate::forUser($actor)->authorize('update', $lockedProposal);
            $existing = ProposalAction::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->proposal_id !== $lockedProposal->id || $existing->action_type !== ProposalActionType::CopyExpense) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $existing;
            }

            $lockedSource = Expense::query()->with('exercise')->where('company_id', $company->id)->lockForUpdate()->find($source->id);
            if ($lockedSource === null || $lockedSource->project_id !== null || $lockedSource->contract_id !== null || $lockedSource->exercise_id === $lockedProposal->exercise_id || $lockedSource->isReversed()) {
                throw ValidationException::withMessages(['source_expense_id' => 'Selezionare una Spesa autonoma attiva di un altro Esercizio della stessa Azienda.']);
            }
            $sourceLines = ExpenseLine::query()->where('expense_id', $lockedSource->id)->orderBy('id')->lockForUpdate()->get();
            $lockedSource->setRelation('lines', $sourceLines);
            $snapshot = ProposalSourceSnapshot::expense($lockedSource);
            $lines = [];
            foreach ($sourceLines as $line) {
                if ($line->lineType() !== ExpenseLineType::Estimate || $line->isAnnulled()) {
                    continue;
                }

                $lines[] = ['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => (string) $line->amount, 'note' => $line->note, 'annulled' => false];
            }

            return $this->planExpense->create($actor, $lockedProposal, [
                'source_expense_id' => $lockedSource->id,
                'source_revision' => $lockedSource->revision,
                'source_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot),
                'target_exercise_id' => $lockedProposal->exercise_id,
                'description' => $lockedSource->description,
                'notes' => $lockedSource->notes,
                'supplier_id' => $lockedSource->supplier_id,
                'cost_center_id' => $lockedSource->direct_cost_center_id,
                'estimate_lines' => $lines,
            ], null, $operationId, $expectedRevision, ProposalActionType::CopyExpense, $lockedSource->originKey());
        }, 3);
    }
}
