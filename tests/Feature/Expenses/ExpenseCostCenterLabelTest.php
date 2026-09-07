<?php

use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('uses the expense year and loaded classifications without additional queries', function (string $ownerType, string $classificationState) {
    $company = Company::factory()->create();
    $previous = Exercise::factory()->for($company)->create(['year' => 2025]);
    $current = Exercise::factory()->for($company)->create(['year' => 2026]);
    $owner = $ownerType === 'project'
        ? Project::factory()->for($company)->create()
        : Contract::factory()->for($company)->create();
    $oldCostCenter = CostCenter::factory()->for($company)->create(['name' => 'Precedente']);
    $costCenter = CostCenter::factory()->for($company)->create([
        'name' => 'Corrente',
        'archived_at' => $classificationState === 'archived' ? now() : null,
    ]);
    $owner->classifications()->create([
        'company_id' => $company->id,
        'exercise_id' => $previous->id,
        'cost_center_id' => $oldCostCenter->id,
    ]);
    if ($classificationState !== 'absent') {
        $owner->classifications()->create([
            'company_id' => $company->id,
            'exercise_id' => $current->id,
            'cost_center_id' => $classificationState === 'unclassified' ? null : $costCenter->id,
        ]);
    }
    Expense::factory()->forExercise($current)->count(5)->create([$ownerType.'_id' => $owner->id]);
    $suffix = ' · ereditata dal '.($ownerType === 'project' ? 'Progetto' : 'Contratto');
    $expected = match ($classificationState) {
        'absent', 'unclassified' => 'Non classificata'.$suffix,
        'archived' => 'Corrente · Archiviato'.$suffix,
        default => 'Corrente'.$suffix,
    };

    expect(Expense::query()->firstOrFail()->costCenterLabel())->toBe($expected);

    $expenses = Expense::query()->with($ownerType.'.classifications.costCenter')->get();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $labels = $expenses->map(fn (Expense $expense): string => $expense->costCenterLabel());
        expect($labels->all())->toBe(array_fill(0, 5, $expected))
            ->and(DB::getQueryLog())->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }
})->with(['project', 'contract'])->with(['active', 'archived', 'unclassified', 'absent']);
