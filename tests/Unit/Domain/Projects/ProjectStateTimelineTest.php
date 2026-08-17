<?php

use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;

it('derives absence and state from ordered non-annulled dated transitions', function () {
    $transitions = [
        ['from_state' => 'open', 'to_state' => 'closed', 'effective_date' => '2026-09-01', 'annulled_at' => null],
        ['from_state' => 'planned', 'to_state' => 'cancelled', 'effective_date' => '2026-03-01', 'annulled_at' => '2026-02-01 10:00:00'],
        ['from_state' => 'planned', 'to_state' => 'open', 'effective_date' => '2026-04-01', 'annulled_at' => null],
    ];

    expect(ProjectStateTimeline::stateAtDate(ProjectState::Planned, '2026-01-15', $transitions, '2026-01-14'))->toBeNull()
        ->and(ProjectStateTimeline::stateAtDate(ProjectState::Planned, '2026-01-15', $transitions, '2026-03-31'))->toBe(ProjectState::Planned)
        ->and(ProjectStateTimeline::stateAtDate(ProjectState::Planned, '2026-01-15', $transitions, '2026-04-01'))->toBe(ProjectState::Open)
        ->and(ProjectStateTimeline::stateAtDate(ProjectState::Planned, '2026-01-15', $transitions, '2026-12-31'))->toBe(ProjectState::Closed);
});

it('validates the entire canonical transition sequence', function () {
    expect(fn () => ProjectStateTimeline::validate(ProjectState::Planned, '2026-01-01', [
        ['from_state' => 'planned', 'to_state' => 'open', 'effective_date' => '2026-02-01', 'annulled_at' => null],
        ['from_state' => 'open', 'to_state' => 'closed', 'effective_date' => '2026-03-01', 'annulled_at' => null],
        ['from_state' => 'closed', 'to_state' => 'open', 'effective_date' => '2026-04-01', 'annulled_at' => null],
    ]))->not->toThrow(Throwable::class)
        ->and(fn () => ProjectStateTimeline::validate(ProjectState::Planned, '2026-01-01', [
            ['from_state' => 'planned', 'to_state' => 'open', 'effective_date' => '2026-02-01', 'annulled_at' => null],
            ['from_state' => 'planned', 'to_state' => 'cancelled', 'effective_date' => '2026-03-01', 'annulled_at' => null],
        ]))->toThrow(DomainException::class)
        ->and(fn () => ProjectStateTimeline::validate(ProjectState::Planned, '2026-01-01', [
            ['from_state' => 'planned', 'to_state' => 'open', 'effective_date' => '2026-02-01', 'annulled_at' => null],
            ['from_state' => 'open', 'to_state' => 'closed', 'effective_date' => '2026-02-01', 'annulled_at' => null],
        ]))->toThrow(DomainException::class)
        ->and(fn () => ProjectStateTimeline::validate(ProjectState::Planned, '2026-01-01', [
            ['from_state' => 'planned', 'to_state' => 'open', 'effective_date' => '2025-12-31', 'annulled_at' => null],
        ]))->toThrow(DomainException::class);
});
