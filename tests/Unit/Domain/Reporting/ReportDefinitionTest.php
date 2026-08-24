<?php

use App\Domain\Reporting\ActualReference;
use App\Domain\Reporting\ComparisonCategory;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportKind;
use App\Domain\Reporting\SecondaryLabel;
use Carbon\CarbonImmutable;

it('defines the closed S11 vocabulary without replaced', function (): void {
    expect(array_column(ReportKind::cases(), 'value'))->toHaveCount(10)
        ->and(array_column(ComparisonCategory::cases(), 'value'))->toBe(['unchanged', 'added', 'removed', 'modified'])
        ->and(array_column(SecondaryLabel::cases(), 'value'))->not->toContain('replaced')
        ->and(array_column(SecondaryLabel::cases(), 'value'))->not->toContain('sostituito');
});

it('requires explicit coherent budget actual references', function (): void {
    $definition = ReportDefinition::fromArray([
        'company_id' => 1,
        'exercise_id' => 10,
        'kind' => 'budget_actual',
        'actual_reference' => ActualReference::Closing->value,
        'initial_reference' => ['type' => 'budget', 'exercise_id' => 10, 'budget_snapshot_id' => 5],
        'final_reference' => ['type' => 'closing', 'exercise_id' => 10],
    ], CarbonImmutable::parse('2026-08-24 10:00:00'));

    expect($definition->initialReference?->budgetSnapshotId)->toBe(5)
        ->and($definition->actualReference)->toBe(ActualReference::Closing)
        ->and($definition->generatedAt->toDateTimeString())->toBe('2026-08-24 10:00:00');
});

it('rejects missing comparison references and unknown vocabulary', function (array $input): void {
    expect(fn () => ReportDefinition::fromArray($input))->toThrow(InvalidArgumentException::class);
})->with([
    'missing references' => [['company_id' => 1, 'exercise_id' => 1, 'kind' => 'budget_actual']],
    'annual without actual' => [['company_id' => 1, 'exercise_id' => 1, 'kind' => 'annual_executive']],
    'unknown kind' => [['company_id' => 1, 'exercise_id' => 1, 'kind' => 'forecast']],
    'unknown actual' => [['company_id' => 1, 'exercise_id' => 1, 'kind' => 'annual_executive', 'actual_reference' => 'forecast']],
]);

it('rejects different measures across exercise comparison', function (): void {
    expect(fn () => ReportDefinition::fromArray([
        'company_id' => 1,
        'exercise_id' => 10,
        'comparison_exercise_id' => 11,
        'kind' => 'exercises',
        'initial_reference' => ['type' => 'closing', 'exercise_id' => 10],
        'final_reference' => ['type' => 'current_knowledge', 'exercise_id' => 11],
    ]))->toThrow(InvalidArgumentException::class, 'stessa misura');
});

it('normalizes numeric HTTP filter identifiers without accepting arbitrary strings', function (): void {
    $definition = ReportDefinition::fromArray([
        'company_id' => 1,
        'exercise_id' => 1,
        'kind' => 'suppliers',
        'filters' => ['supplier_id' => '42'],
    ]);

    expect($definition->filters)->toBe(['supplier_id' => 42]);

    expect(fn () => ReportDefinition::fromArray([
        'company_id' => 1,
        'exercise_id' => 1,
        'kind' => 'suppliers',
        'filters' => ['supplier_id' => 'not-an-id'],
    ]))->toThrow(InvalidArgumentException::class, 'identificativo valido');
});

it('rejects partial intervals unsupported filters and silent reference fallbacks', function (array $input): void {
    expect(fn () => ReportDefinition::fromArray($input))->toThrow(InvalidArgumentException::class);
})->with([
    'partial interval' => [[
        'company_id' => 1, 'exercise_id' => 1, 'kind' => 'contracts', 'date_from' => '2026-01-01',
    ]],
    'unknown filter' => [[
        'company_id' => 1, 'exercise_id' => 1, 'kind' => 'projects', 'filters' => ['title' => 1],
    ]],
    'budget without version' => [[
        'company_id' => 1,
        'exercise_id' => 1,
        'kind' => 'budget_versions',
        'initial_reference' => ['type' => 'budget', 'exercise_id' => 1],
        'final_reference' => ['type' => 'budget', 'exercise_id' => 1, 'budget_snapshot_id' => 2],
    ]],
]);
