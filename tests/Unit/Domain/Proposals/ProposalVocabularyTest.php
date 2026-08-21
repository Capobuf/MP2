<?php

use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalPurpose;
use App\Domain\Proposals\ProposalReadinessReason;
use App\Domain\Proposals\ProposalReadinessState;
use App\Domain\Proposals\ProposalSourceType;
use App\Domain\Proposals\ProposalStatus;

it('exposes exhaustive non-empty labels for the closed proposal vocabulary', function (): void {
    foreach ([ProposalPurpose::cases(), ProposalStatus::cases(), ProposalSourceType::cases(), ProposalReadinessState::cases(), ProposalActionType::cases()] as $cases) {
        foreach ($cases as $case) {
            expect($case->label())->not->toBeEmpty();
        }
    }
    foreach (ProposalReadinessReason::cases() as $reason) {
        expect($reason->message())->not->toBeEmpty();
    }
});

it('rejects unknown persisted vocabulary values', function (): void {
    expect(fn () => ProposalActionType::from('sostituisce'))->toThrow(ValueError::class)
        ->and(fn () => ProposalStatus::from('unknown'))->toThrow(ValueError::class);
});
