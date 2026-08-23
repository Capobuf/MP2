<?php

use App\Domain\Company\Capability;
use App\Domain\Contracts\ContractState;
use App\Filament\Pages\ContractDeadlines;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractConditionsRelationManager;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('exposes no ordinary physical delete for Contract governance records or Timeline', function () {
    $company = Company::factory()->create();
    $contract = Contract::factory()->for($company)->create();
    $exercise = Exercise::factory()->for($company)->create();
    $records = [
        $contract,
        ContractCondition::factory()->forContract($contract)->create(),
        ContractLifecycleFact::factory()->forContract($contract)->create(),
        ContractRenewalConfiguration::factory()->forContract($contract)->create(),
        ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create(),
        ProjectContractLink::factory()->forProjectAndContract(Project::factory()->for($company)->create(), $contract)->create(),
        Attachment::factory()->forContract($contract)->create(),
        AuditEvent::withoutEvents(fn () => AuditEvent::query()->create([
            'operation_id' => (string) Str::uuid(), 'company_id' => $company->id,
            'actor_id' => User::factory()->create()->id, 'event_type' => 'contract_created',
            'subject_type' => Contract::class, 'subject_id' => $contract->id,
            'affected_exercise_ids' => [], 'effective_from' => now()->toDateString(),
            'allocated_impact_by_exercise' => [], 'actual_impact_by_exercise' => [],
        ])),
    ];

    foreach ($records as $record) {
        expect(fn () => $record->delete())->toThrow(LogicException::class);
    }
});

it('has no suspended state or unsupported Contract workflow classes', function () {
    expect(ContractState::tryFrom('suspended'))->toBeNull()
        ->and(class_exists('App\\Models\\Budget'))->toBeFalse()
        ->and(class_exists('App\\Models\\Revision'))->toBeFalse()
        ->and(class_exists('App\\Models\\Invoice'))->toBeFalse()
        ->and(class_exists('App\\Models\\Payment'))->toBeFalse()
        ->and(class_exists('App\\Models\\ContractReplacement'))->toBeFalse();

    $surface = collect(Route::getRoutes())->map(fn ($route): string => strtolower(($route->getName() ?? '').' '.$route->uri()))->implode("\n");
    foreach (['invoice', 'payment', 'reminder', 'report', 'sostituisce'] as $forbidden) {
        expect($surface)->not->toContain($forbidden);
    }
});

it('shows no prorata matching invoice reminder carryover reporting or Sostituisce actions', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    $contract = Contract::factory()->for($company)->create();
    $condition = ContractCondition::factory()->forContract($contract)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);

    $forbiddenActions = [
        'delete', 'prorate', 'matchActual', 'createInvoice', 'schedulePayment', 'sendReminder',
        'carryover', 'reprogram', 'createProposal', 'approveBudget', 'closeExercise',
        'lateCorrection', 'forecast', 'exportReport', 'replace', 'sostituisce',
    ];
    $contractPage = Livewire::test(ViewContract::class, ['record' => $contract->id]);
    $deadlinePage = Livewire::test(ContractDeadlines::class);
    $conditions = Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class]);

    foreach ($forbiddenActions as $action) {
        $contractPage->assertActionDoesNotExist($action);
        $deadlinePage->assertTableActionDoesNotExist($action);
        $conditions->assertTableActionDoesNotExist($action, record: $condition);
    }
});
