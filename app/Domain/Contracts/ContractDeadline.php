<?php

namespace App\Domain\Contracts;

use App\Models\Contract;
use App\Models\Exercise;
use Carbon\CarbonImmutable;

final readonly class ContractDeadline
{
    public function __construct(
        public ?int $contractId,
        public ?int $supplierId,
        public string $contractualStartDate,
        public ?string $nextExpiryDate,
        public bool $automaticRenewal,
        public ?int $renewalDurationMonths,
        public ?int $noticeDays,
        public ?string $noticeLimitDate,
        public ?int $daysUntilExpiry,
        public ?int $daysUntilNoticeLimit,
        public ContractState $state,
        public ?string $plannedCessationDate,
        public ?int $costCenterId,
        public bool $renewalWithoutCondition,
    ) {}

    public static function derive(
        string $contractualStartDate,
        ?string $nextExpiryDate,
        bool $automaticRenewal,
        ?int $renewalDurationMonths,
        ?int $noticeDays,
        string $today,
        ContractState $state,
        ?string $plannedCessationDate,
        ?int $costCenterId,
        bool $renewalWithoutCondition,
        ?int $contractId = null,
        ?int $supplierId = null,
    ): self {
        $reference = CarbonImmutable::parse($today)->startOfDay();
        $expiry = $nextExpiryDate === null ? null : CarbonImmutable::parse($nextExpiryDate)->startOfDay();
        $noticeLimit = $expiry === null || $noticeDays === null ? null : $expiry->subDays($noticeDays);

        return new self(
            contractId: $contractId,
            supplierId: $supplierId,
            contractualStartDate: CarbonImmutable::parse($contractualStartDate)->toDateString(),
            nextExpiryDate: $expiry?->toDateString(),
            automaticRenewal: $automaticRenewal,
            renewalDurationMonths: $renewalDurationMonths,
            noticeDays: $noticeDays,
            noticeLimitDate: $noticeLimit?->toDateString(),
            daysUntilExpiry: $expiry === null ? null : (int) $reference->diffInDays($expiry, false),
            daysUntilNoticeLimit: $noticeLimit === null ? null : (int) $reference->diffInDays($noticeLimit, false),
            state: $state,
            plannedCessationDate: $plannedCessationDate,
            costCenterId: $costCenterId,
            renewalWithoutCondition: $renewalWithoutCondition,
        );
    }

    public static function fromContract(Contract $contract, ?Exercise $exercise, CarbonImmutable $today): self
    {
        $facts = $contract->relationLoaded('lifecycleFacts') ? $contract->lifecycleFacts : $contract->lifecycleFacts()->get();
        $conditions = $contract->relationLoaded('conditions') ? $contract->conditions : $contract->conditions()->get();
        if (! $contract->relationLoaded('renewalConfigurations')) {
            $contract->setRelation('renewalConfigurations', $contract->renewalConfigurations()->get());
        }
        $plannedCessation = $facts
            ->filter(fn ($fact): bool => $fact->annulled_at === null
                && in_array($fact->type, ['cessation', 'expiry_cessation'], true)
                && $fact->stateChangeDate() !== null
                && $fact->stateChangeDate()->greaterThan($today))
            ->sortBy(fn ($fact): array => [$fact->stateChangeDate()?->toDateString(), $fact->id])
            ->first();
        $classification = $exercise === null
            ? null
            : ($contract->relationLoaded('classifications')
                ? $contract->classifications->firstWhere('exercise_id', $exercise->id)
                : $contract->classifications()->where('exercise_id', $exercise->id)->first());
        $expiry = $contract->nextExpiryDate()?->toDateString();

        return self::derive(
            contractualStartDate: $contract->contractualStartDate()->toDateString(),
            nextExpiryDate: $expiry,
            automaticRenewal: (bool) $contract->automatic_renewal,
            renewalDurationMonths: $contract->renewal_duration_months,
            noticeDays: $contract->notice_days,
            today: $today->toDateString(),
            state: $contract->stateAtDate($today->toDateString()),
            plannedCessationDate: $plannedCessation?->stateChangeDate()?->toDateString(),
            costCenterId: $classification?->cost_center_id,
            renewalWithoutCondition: $expiry !== null
                && (bool) $contract->automatic_renewal
                && ContractRenewalSchedule::hasRenewalWithoutCondition($conditions, $expiry),
            contractId: $contract->id,
            supplierId: $contract->supplier_id,
        );
    }
}
