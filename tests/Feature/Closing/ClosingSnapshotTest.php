<?php

use App\Actions\Closing\CloseExercise;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Domain\Company\Capability;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('materializes zero-net Actual presence autonomously and keeps Snapshot rows immutable', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create(['name' => 'Snapshot Company']);
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CloseExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $supplier = Supplier::factory()->for($company)->create(['legal_name' => 'Supplier at Closing']);
    $costCenter = CostCenter::factory()->for($company)->create(['name' => 'Cost Center at Closing']);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create([
        'supplier_id' => $supplier->id,
        'direct_cost_center_id' => $costCenter->id,
        'description' => 'Zero net Actual source',
    ]);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '-100.00', 'note' => 'Rimborso di compensazione']);
    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $exercise, ['create_next_exercise' => false, 'projects' => []]);

    expect($prepared['review']->warnings)->toBe([]);

    $snapshot = app(CloseExercise::class)->execute($actor, $exercise, [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => false,
        'confirmed' => true,
    ], (string) Str::uuid());
    $row = $snapshot->rows()->where('origin_key', $expense->originKey())->sole();

    expect($snapshot->total_closing_actual)->toBe('0.00')
        ->and($row->closing_actual)->toBe('0.00')
        ->and($row->has_actuals)->toBeTrue()
        ->and($row->supplier_label)->toBe('Supplier at Closing')
        ->and($row->cost_center_label)->toBe('Cost Center at Closing')
        ->and(count($row->detail['lines']))->toBe(2);

    $supplier->update(['legal_name' => 'Supplier renamed later']);
    $costCenter->update(['name' => 'Cost Center renamed later']);
    $row->refresh();

    expect($row->supplier_label)->toBe('Supplier at Closing')
        ->and($row->cost_center_label)->toBe('Cost Center at Closing')
        ->and(fn () => $snapshot->update(['company_name' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $snapshot->delete())->toThrow(LogicException::class)
        ->and(fn () => $row->update(['label' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
});

it('rejects incoherent tenant Exercise and N+1 Snapshot references', function (): void {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $actor = User::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $nextExercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $foreignNextExercise = Exercise::factory()->for($otherCompany)->create(['year' => 2026]);
    $attributes = [
        'company_id' => $company->id,
        'company_name' => $company->name,
        'exercise_id' => $exercise->id,
        'exercise_year' => 2025,
        'closed_at' => now(),
        'closed_by_id' => $actor->id,
        'initial_budget_id' => null,
        'current_budget_id' => null,
        'total_final_allocation' => '0.00',
        'total_closing_actual' => '0.00',
        'total_operational_variance' => '0.00',
        'total_consolidated_carryover' => '0.00',
        'accepted_warnings' => [],
        'applied_settings' => [],
        'next_exercise_disposition' => 'already_existed',
        'next_exercise_id' => $nextExercise->id,
    ];

    expect(fn () => ClosingSnapshot::query()->create([
        ...$attributes,
        'exercise_year' => 2024,
        'operation_id' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class)
        ->and(fn () => ClosingSnapshot::query()->create([
            ...$attributes,
            'next_exercise_id' => $foreignNextExercise->id,
            'operation_id' => (string) Str::uuid(),
        ]))->toThrow(ValidationException::class)
        ->and(fn () => ClosingSnapshot::query()->create([
            ...$attributes,
            'next_exercise_disposition' => 'not_created',
            'operation_id' => (string) Str::uuid(),
        ]))->toThrow(ValidationException::class)
        ->and(ClosingSnapshot::query()->count())->toBe(0);
});

it('maps the historical non-creation disposition forward and back without weakening its shape check', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $snapshot = closeExerciseFixture($exercise, $actor);
    $migration = require database_path('migrations/2026_08_26_000400_rename_next_exercise_disposition.php');
    $legacyDisposition = implode('_', ['not', 'created', 'management', 'terminated']);

    $migration->down();
    expect(DB::table('closing_snapshots')->where('id', $snapshot->id)->value('next_exercise_disposition'))
        ->toBe($legacyDisposition);

    $migration->up();
    expect(DB::table('closing_snapshots')->where('id', $snapshot->id)->value('next_exercise_disposition'))
        ->toBe('not_created');

    expect(fn () => DB::table('closing_snapshots')->where('id', $snapshot->id)->update([
        'next_exercise_id' => $exercise->id,
    ]))->toThrow(QueryException::class);
});
