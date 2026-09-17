<?php

use App\Filament\Pages\CompanyAudit;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('shares tenant exercise labels across timeline rows and impact columns for each request', function (int $count) {
    $company = Company::factory()->create();
    $viewer = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $viewer, 'permissions' => TestPermissions::VIEW]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $foreign = Exercise::factory()->create(['year' => 2099]);
    $events = collect();
    for ($index = 0; $index < $count; $index++) {
        $events->push(AuditEvent::query()->create([
            'operation_id' => (string) Str::uuid(),
            'company_id' => $company->id, 'actor_id' => $viewer->id,
            'event_type' => 'company_created', 'subject_type' => Company::class, 'subject_id' => $company->id,
            'affected_exercise_ids' => [$exercise->id, $foreign->id], 'effective_from' => '2026-01-01',
            'allocated_impact_by_exercise' => [$exercise->id => '100.00', $foreign->id => '20.00'],
            'actual_impact_by_exercise' => [$exercise->id => '30.00', $foreign->id => '5.00'],
        ]));
    }
    $this->actingAs($viewer);
    Filament::setTenant($company->tenantCompany);
    $page = Livewire::test(CompanyAudit::class)->set('tableRecordsPerPage', 50);
    $columns = collect($page->get('tableColumns'))->map(fn (array $column): array => [
        ...$column, 'isToggled' => true,
    ])->all();
    $page->set('tableColumns', $columns);

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $page->call('$refresh');
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from `exercises`'));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($queries)->toHaveCount(1);
    $page->assertCanSeeTableRecords($events)
        ->assertTableColumnStateSet('affected_exercise_ids', '2026, #'.$foreign->id, $events->first())
        ->assertTableColumnStateSet('allocated_impact_by_exercise', '2026: € 100.00 · #'.$foreign->id.': € 20.00', $events->first())
        ->assertTableColumnStateSet('actual_impact_by_exercise', '2026: € 30.00 · #'.$foreign->id.': € 5.00', $events->first())
        ->assertSee('2026: € 100.00')
        ->assertDontSee('2099: €');

    $exercise->update(['year' => 2027]);
    $page->call('$refresh')
        ->assertTableColumnStateSet('affected_exercise_ids', '2027, #'.$foreign->id, $events->first())
        ->assertSee('2027: € 100.00')
        ->mountTableAction('details', $events->first())
        ->assertSee('2027: € 100.00')
        ->assertDontSee('2099: €');
})->with([1, 25]);
