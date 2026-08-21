<?php

use App\Actions\Operations\CreateContract;
use App\Actions\Operations\CreateProjectContractLink;
use App\Actions\Operations\UploadAttachment;
use App\Domain\Company\Capability;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-21 10:00:00 Europe/Rome');
    Storage::fake('local');
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('rehydrates every stable Contract identity and ordered audit sequence unchanged', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $creationOperation = (string) Str::uuid();
    $contract = app(CreateContract::class)->execute($actor, $company, [
        'title' => 'Persistenza Contratto',
        'supplier_id' => $supplier->id,
        'contractual_start_date' => '2025-01-31',
        'next_expiry_date' => '2025-07-31',
        'renewal_effective_from' => '2025-01-01',
        'automatic_renewal' => true,
        'renewal_duration_months' => 6,
        'notice_days' => 30,
        'condition' => [
            'amount' => '10.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start',
            'valid_from' => '2025-01-31', 'valid_to' => null,
        ],
        'classifications' => [(string) $exercise->id => null],
    ], $creationOperation);
    $systemExpense = $contract->expenses()->where('origin', 'system')->sole();
    $systemLine = $systemExpense->lines()->sole();
    $renewalFactIds = $contract->lifecycleFacts()->where('type', 'renewal')->orderBy('renewed_expiry_date')->pluck('id')->all();
    $link = app(CreateProjectContractLink::class)->execute(
        $actor,
        Project::factory()->for($company)->create(),
        $contract,
        'Persistente',
        (string) Str::uuid(),
    );
    $attachment = app(UploadAttachment::class)->execute(
        $actor,
        $contract,
        UploadedFile::fake()->createWithContent('persistente.txt', 'evidenza persistente'),
        (string) Str::uuid(),
    );
    $creationSequences = AuditEvent::query()->where('operation_id', $creationOperation)->orderBy('event_sequence')->pluck('event_sequence')->all();
    $identities = [
        'contract' => $contract->id,
        'expense' => $systemExpense->id,
        'line' => $systemLine->id,
        'link' => $link->id,
        'attachment' => $attachment->id,
    ];

    unset($contract, $systemExpense, $systemLine, $link, $attachment);

    $rehydratedContract = Contract::query()->findOrFail($identities['contract']);
    $rehydratedExpense = Expense::query()->findOrFail($identities['expense']);
    $rehydratedLine = ExpenseLine::query()->findOrFail($identities['line']);
    $rehydratedLink = ProjectContractLink::query()->findOrFail($identities['link']);
    $rehydratedAttachment = Attachment::query()->findOrFail($identities['attachment']);

    expect($rehydratedContract->originKey())->toBe('contract:'.$identities['contract'])
        ->and($rehydratedExpense->originKey())->toBe('expense:'.$identities['expense'])
        ->and($rehydratedExpense->id)->toBe($identities['expense'])
        ->and($rehydratedLine->id)->toBe($identities['line'])
        ->and($rehydratedLink->id)->toBe($identities['link'])
        ->and($rehydratedAttachment->id)->toBe($identities['attachment'])
        ->and($rehydratedAttachment->sha256)->toBe(hash('sha256', 'evidenza persistente'))
        ->and(ContractLifecycleFact::query()->whereIn('id', $renewalFactIds)->orderBy('renewed_expiry_date')->pluck('id')->all())->toBe($renewalFactIds)
        ->and(AuditEvent::query()->where('operation_id', $creationOperation)->orderBy('event_sequence')->pluck('event_sequence')->all())->toBe($creationSequences)
        ->and($creationSequences)->toBe(range(0, count($creationSequences) - 1));
    Storage::disk('local')->assertExists($rehydratedAttachment->storage_path);
});
