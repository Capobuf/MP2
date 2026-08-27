<?php

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the complete forward S1 schema', function () {
    expect(Schema::hasColumn('users', 'is_platform_admin'))->toBeTrue()
        ->and(Schema::hasColumns('companies', [
            'name',
            'timezone',
            'overspend_note_required',
            'unclassified_closing_policy',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('audit_events', [
            'subject_type',
            'subject_id',
            'affected_exercise_ids',
            'effective_from',
            'allocated_impact_by_exercise',
            'actual_impact_by_exercise',
        ]))->toBeTrue();
});

it('never propagates a capability to another company', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create(['name' => 'Azienda A']);
    $companyB = Company::factory()->create(['name' => 'Azienda B']);

    CompanyCapability::query()->create([
        'company_id' => $companyA->id,
        'user_id' => $user->id,
        'capability' => Capability::View,
    ]);

    expect($user->hasCapability($companyA, Capability::View))->toBeTrue()
        ->and($user->hasCapability($companyB, Capability::View))->toBeFalse()
        ->and($user->canAccessTenant($companyA->tenantCompany))->toBeTrue()
        ->and($user->canAccessTenant($companyB->tenantCompany))->toBeFalse()
        ->and($user->getTenants(Filament::getPanel('admin'))->modelKeys())
        ->toBe([$companyA->id]);
});

it('does not turn platform administration into a company authorization bypass', function () {
    $platformAdmin = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create();

    expect(Gate::forUser($platformAdmin)->allows('create', Company::class))->toBeTrue()
        ->and(Gate::forUser($platformAdmin)->allows('view', $company))->toBeFalse()
        ->and(Gate::forUser($platformAdmin)->allows('manageSettings', $company))->toBeFalse()
        ->and(Gate::forUser($platformAdmin)->allows('managePermissions', $company))->toBeFalse();
});

it('authorizes management only through the exact company capability', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    foreach ([Capability::ManageSettings, Capability::ManagePermissions] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }

    expect(Gate::forUser($user)->allows('manageSettings', $companyA))->toBeTrue()
        ->and(Gate::forUser($user)->allows('managePermissions', $companyA))->toBeTrue()
        ->and(Gate::forUser($user)->allows('manageSettings', $companyB))->toBeFalse()
        ->and(Gate::forUser($user)->allows('managePermissions', $companyB))->toBeFalse();
});
