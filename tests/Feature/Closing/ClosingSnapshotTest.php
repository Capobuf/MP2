<?php

use App\Actions\Closing\CloseExercise;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;

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
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '-100.00']);
    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $exercise, ['management_continues' => false, 'projects' => []]);

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
