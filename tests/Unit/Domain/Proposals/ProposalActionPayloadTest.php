<?php

use App\Domain\Proposals\ProposalActionPayload;
use App\Domain\Proposals\ProposalActionType;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('accepts a complete typed estimate replacement and rejects unknown or actual fields', function (): void {
    $payload = ['estimate_lines' => [['proposal_line_id' => fake()->uuid(), 'line_id' => null, 'amount' => '10.00', 'note' => null, 'annulled' => false]]];
    expect(ProposalActionPayload::validate(ProposalActionType::SetExpenseEstimates, $payload))->toBe($payload);
    expect(fn () => ProposalActionPayload::validate(ProposalActionType::SetExpenseEstimates, [...$payload, 'actual_total' => '2.00']))->toThrow(ValidationException::class)
        ->and(fn () => ProposalActionPayload::validate(ProposalActionType::SetExpenseEstimates, [...$payload, 'surprise' => true]))->toThrow(ValidationException::class);
});
