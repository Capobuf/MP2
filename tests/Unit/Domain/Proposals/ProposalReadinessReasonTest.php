<?php

use App\Domain\Proposals\ProposalReadinessReason;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('exposes exactly the canonical inconsistency vocabulary', function (): void {
    expect(collect(ProposalReadinessReason::inconsistencies())->map->value->all())->toBe([
        'carryover_above_limit',
        'reprogramming_above_available',
        'reprogramming_unbalanced',
        'deferral_modes_conflict',
        'actual_mutation',
        'expense_dual_owner',
        'manual_contract_estimate',
        'invalid_contract_condition',
        'incompatible_transition',
        'closed_exercise_action',
        'archived_source_without_restore',
        'missing_required_data',
        'stale_concurrent_action',
        'invalid_relation',
        'partial_multi_exercise_effect',
    ]);
});

it('maps validation failures to one or more exact canonical reasons', function (array $messages, array $expected): void {
    $exception = ValidationException::withMessages($messages);

    expect(collect(ProposalReadinessReason::fromValidation($exception))->map->value->all())->toBe($expected);
})->with([
    'actuals' => [['cost_center_id' => 'La Proposta modificherebbe una Spesa con Effettivi.'], ['actual_mutation']],
    'closed exercise' => [['exercise_id' => 'La pianificazione richiede un Esercizio Aperto.'], ['closed_exercise_action']],
    'transition and missing data' => [[
        'transitions' => 'Transizione Progetto non ammessa.',
        'title' => 'Campo obbligatorio.',
    ], ['incompatible_transition', 'missing_required_data']],
    'relation' => [['relation' => 'Il collegamento non è valido.'], ['invalid_relation']],
]);
