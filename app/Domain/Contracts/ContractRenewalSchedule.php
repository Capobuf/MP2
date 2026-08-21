<?php

namespace App\Domain\Contracts;

use Carbon\CarbonImmutable;

final readonly class ContractRenewalSchedule
{
    /** @param list<string> $elapsed */
    public function __construct(public array $elapsed, public string $nextExpiry) {}

    public static function fromAnchor(string $anchorDate, int $durationMonths, string $today): self
    {
        if ($durationMonths < 1) {
            throw new \InvalidArgumentException('Renewal duration must be positive.');
        }

        $anchor = CarbonImmutable::parse($anchorDate)->startOfDay();
        $reference = CarbonImmutable::parse($today)->startOfDay();
        $elapsed = [];
        $index = 0;

        do {
            $expiry = ContractCycle::anchoredDate($anchor, $index * $durationMonths);
            if ($expiry->lessThanOrEqualTo($reference)) {
                $elapsed[] = $expiry->toDateString();
                $index++;
            }
        } while ($expiry->lessThanOrEqualTo($reference));

        return new self($elapsed, ContractCycle::anchoredDate($anchor, $index * $durationMonths)->toDateString());
    }

    /**
     * @template T of array<string, mixed>|object
     *
     * @param  iterable<T>  $configurations
     * @return T|null
     */
    public static function configurationAtDate(iterable $configurations, string $date): array|object|null
    {
        $reference = CarbonImmutable::parse($date)->startOfDay();
        $selected = null;
        foreach ($configurations as $configuration) {
            $effective = CarbonImmutable::parse((string) self::value($configuration, 'effective_from'))->startOfDay();
            if ($effective->greaterThan($reference)) {
                continue;
            }
            if ($selected === null || [
                (string) self::value($configuration, 'effective_from'),
                (int) (self::value($configuration, 'id') ?? 0),
            ] > [
                (string) self::value($selected, 'effective_from'),
                (int) (self::value($selected, 'id') ?? 0),
            ]) {
                $selected = $configuration;
            }
        }

        return $selected;
    }

    public static function nextAnchoredExpiry(string $anchorDate, int $durationMonths, string $afterDate): string
    {
        if ($durationMonths < 1) {
            throw new \InvalidArgumentException('Renewal duration must be positive.');
        }

        $anchor = CarbonImmutable::parse($anchorDate)->startOfDay();
        $after = CarbonImmutable::parse($afterDate)->startOfDay();
        $index = 1;
        do {
            $candidate = ContractCycle::anchoredDate($anchor, $index * $durationMonths);
            $index++;
        } while (! $candidate->greaterThan($after));

        return $candidate->toDateString();
    }

    /** @param iterable<array<string, mixed>|object> $conditions */
    public static function hasRenewalWithoutCondition(iterable $conditions, string $expiryDate): bool
    {
        $dayAfter = CarbonImmutable::parse($expiryDate)->addDay()->startOfDay();
        foreach ($conditions as $condition) {
            if (self::value($condition, 'annulled_at') !== null) {
                continue;
            }
            $validFrom = CarbonImmutable::parse((string) self::value($condition, 'valid_from'))->startOfDay();
            $validToValue = self::value($condition, 'valid_to');
            $validTo = $validToValue === null ? null : CarbonImmutable::parse((string) $validToValue)->startOfDay();
            if (! $validFrom->greaterThan($dayAfter) && ($validTo === null || ! $validTo->lessThan($dayAfter))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed>|object $value */
    private static function value(array|object $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : ($value->{$key} ?? null);
    }
}
