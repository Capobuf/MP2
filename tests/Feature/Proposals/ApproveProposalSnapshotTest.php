<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Domain\Proposals\ProposalActionType;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\BudgetEvidence;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function snapshotApprovalFixture(): array
{
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $user = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $user, 'permissions' => $capability]);
    }
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());
    app(PlanExpense::class)->execute($user, $proposal, $proposal->items->sole(), ProposalActionType::SetExpenseEstimates, ['estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => $line->id, 'amount' => '8.00', 'note' => null, 'annulled' => false]]], null, (string) Str::uuid(), 0);

    return compact('user', 'proposal');
}

it('materializes immutable rows evidence and deterministic approval events', function (): void {
    ['user' => $user, 'proposal' => $proposal] = snapshotApprovalFixture();
    $operation = (string) Str::uuid();
    $budget = app(ApproveProposal::class)->execute($user, $proposal->refresh(), $operation, ['external_subject' => 'Direzione', 'external_venue' => 'Verbale', 'reason' => 'Approvato']);
    $row = BudgetSourceRow::query()->sole();
    $budgetEvent = AuditEvent::query()->where('operation_id', $operation)->where('event_type', 'budget_created')->sole();
    expect($budget->rows)->toHaveCount(1)
        ->and($row->detail)->toHaveKeys(['identity', 'expense', 'approved_actions', 'approval_event_sequences'])
        ->and($row->detail)->not->toHaveKeys(['plan', 'actual_context'])
        ->and($row->detail['expense']['approved_estimate_total'])->toBe('8.00')
        ->and($row->detail['expense']['active_estimate_lines'][0]['amount'])->toBe('8.00')
        ->and(BudgetEvidence::query()->sole()->external_subject)->toBe('Direzione')
        ->and($budgetEvent->new_value['approval_evidence'])->toMatchArray(['external_subject' => 'Direzione', 'external_venue' => 'Verbale', 'reason' => 'Approvato', 'attachment_ids' => []])
        ->and($budgetEvent->new_value)->toHaveKeys(['affected_exercises', 'budget_id', 'proposal_id', 'version'])
        ->and(AuditEvent::query()->where('operation_id', $operation)->orderBy('event_sequence')->pluck('event_sequence')->all())->toBe([0, 1, 2]);
    expect(fn () => $budget->rows->first()->update(['label' => 'cambiata']))->toThrow(LogicException::class);
});

it('downloads retained evidence through budget company authorization', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('evidence/prova.txt', 'prova');
    $budget = BudgetSnapshot::factory()->create();
    $viewer = User::factory()->create();
    grantTestPermissions(['company_id' => $budget->company_id, 'user' => $viewer, 'permissions' => TestPermissions::VIEW]);
    $evidence = BudgetEvidence::factory()->for($budget, 'budget')->create(['company_id' => $budget->company_id, 'storage_disk' => 'local', 'storage_path' => 'evidence/prova.txt', 'original_name' => 'prova.txt', 'media_type' => 'text/plain', 'size_bytes' => 5, 'sha256' => hash('sha256', 'prova')]);
    $this->actingAs($viewer)->get(route('budget-evidence.download', $evidence))->assertOk()->assertDownload('prova.txt');
    $intruder = User::factory()->create();
    $this->actingAs($intruder)->get(route('budget-evidence.download', $evidence))->assertNotFound();
});

it('enforces same-company Attachment evidence integrity in the database', function (): void {
    $budget = BudgetSnapshot::factory()->create();
    $foreignAttachment = Attachment::factory()->create();

    expect(fn () => BudgetEvidence::factory()->for($budget, 'budget')->create([
        'company_id' => $budget->company_id, 'attachment_id' => $foreignAttachment->id,
    ]))->toThrow(QueryException::class);
});

it('rolls back header events rows evidence and status stage failures', function (): void {
    foreach (['after_budget_header', 'after_audit_events', 'after_budget_rows', 'after_evidence', 'after_proposal_status'] as $stage) {
        ['user' => $user, 'proposal' => $proposal] = snapshotApprovalFixture();
        try {
            app(ApproveProposal::class)->execute($user, $proposal->refresh(), (string) Str::uuid(), checkpoint: fn (string $current) => $current === $stage ? throw new RuntimeException($stage) : null);
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe($stage);
        }
        expect(BudgetSnapshot::query()->where('proposal_id', $proposal->id)->exists())->toBeFalse()
            ->and($proposal->refresh()->status->value)->toBe('draft');
    }
});
