<?php

use App\Actions\Operations\CreateExpense;
use App\Actions\Operations\CreateExpenseLine;
use App\Filament\Pages\CompanyAudit;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('renders S3 events newest first and filters an expense with its line events', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $expense = app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id,
        'description' => 'Filtrata',
        'lines' => [['type' => 'estimate', 'amount' => '10.00']],
    ], (string) Str::uuid());
    app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'estimate', 'amount' => '5.00'], (string) Str::uuid());
    $events = AuditEvent::query()->where('company_id', $company->id)->orderByDesc('id')->get();
    $otherCompany = Company::factory()->create();
    $otherEvent = AuditEvent::withoutEvents(fn () => AuditEvent::query()->create([
        'operation_id' => (string) Str::uuid(),
        'company_id' => $otherCompany->id,
        'actor_id' => $actor->id,
        'event_type' => 'company_created',
        'subject_type' => Company::class,
        'subject_id' => $otherCompany->id,
        'affected_exercise_ids' => [],
        'effective_from' => now()->toDateString(),
        'allocated_impact_by_exercise' => [],
        'actual_impact_by_exercise' => [],
    ]));
    $this->actingAs($actor);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CompanyAudit::class)
        ->set('expense', $expense->id)
        ->assertCanSeeTableRecords($events, inOrder: true)
        ->assertCanNotSeeTableRecords([$otherEvent])
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});
