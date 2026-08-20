<?php

use App\Domain\Projects\ProjectActualKind;
use App\Domain\Projects\ProjectAnnualReferenceDate;
use App\Domain\Projects\ProjectOverspend;
use App\Domain\Projects\ProjectOverspendResult;
use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectTransitionStatus;
use Carbon\CarbonImmutable;

it('defines exactly the canonical Project states transitions and Actual declarations', function () {
    expect(array_column(ProjectState::cases(), 'value'))->toBe(['planned', 'open', 'closed', 'cancelled'])
        ->and(ProjectState::Planned->canTransitionTo(ProjectState::Open))->toBeTrue()
        ->and(ProjectState::Planned->canTransitionTo(ProjectState::Closed))->toBeFalse()
        ->and(ProjectState::Closed->canTransitionTo(ProjectState::Open))->toBeTrue()
        ->and(ProjectState::Cancelled->canTransitionTo(ProjectState::Planned))->toBeTrue()
        ->and(array_column(ProjectActualKind::cases(), 'value'))->toBe(['ordinary', 'late', 'reimbursement', 'corrective'])
        ->and(ProjectActualKind::Ordinary->requiresNote())->toBeFalse()
        ->and(ProjectActualKind::Late->requiresNote())->toBeTrue();
});

it('derives annual reference dates and transition status without mutable clock state', function () {
    $today = CarbonImmutable::parse('2026-08-17');

    expect(ProjectAnnualReferenceDate::forYear(2025, $today)->toDateString())->toBe('2025-12-31')
        ->and(ProjectAnnualReferenceDate::forYear(2026, $today)->toDateString())->toBe('2026-08-17')
        ->and(ProjectAnnualReferenceDate::forYear(2027, $today)->toDateString())->toBe('2027-01-01')
        ->and(ProjectTransitionStatus::for('2026-08-18', null, $today))->toBe(ProjectTransitionStatus::Planned)
        ->and(ProjectTransitionStatus::for('2026-08-17', null, $today))->toBe(ProjectTransitionStatus::Effective)
        ->and(ProjectTransitionStatus::for('2026-08-18', '2026-08-16 09:00:00', $today))->toBe(ProjectTransitionStatus::Annulled);
});

it('detects only creation or increase of exact positive overspend', function (string $before, string $after, ProjectOverspendResult $expected) {
    expect(ProjectOverspend::detect($before, $after))->toBe($expected);
})->with([
    ['-1.00', '0.00', ProjectOverspendResult::None],
    ['0.00', '0.01', ProjectOverspendResult::Created],
    ['-10.00', '2.00', ProjectOverspendResult::Created],
    ['2.00', '2.01', ProjectOverspendResult::Increased],
    ['2.00', '2.00', ProjectOverspendResult::None],
    ['2.00', '1.99', ProjectOverspendResult::None],
    ['2.00', '-1.00', ProjectOverspendResult::None],
]);
