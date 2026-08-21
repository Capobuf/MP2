<?php

namespace App\Domain\Proposals;

use Illuminate\Validation\ValidationException;

final class BudgetPayloadGuard
{
    private const FORBIDDEN = ['actual', 'effettiv[^_]*', 'forecast', 'closing', 'chiusura', 'variance', 'scostamento', 'residual', 'residuo', 'saving', 'risparmio', 'overspend', 'sovraspesa', 'late_correction'];

    /** @param array<string, mixed> $payload */
    public static function assertPlanOnly(array $payload): void
    {
        self::walk($payload, 'payload');
    }

    private static function walk(mixed $value, string $path): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $nested) {
            $normalized = strtolower((string) $key);
            foreach (self::FORBIDDEN as $forbidden) {
                if (preg_match('/(^|_)'.$forbidden.'($|_)/', $normalized) === 1) {
                    throw ValidationException::withMessages([$path.'.'.$key => 'Il payload del Budget può contenere soltanto dati di piano.']);
                }
            }
            self::walk($nested, $path.'.'.$key);
        }
    }
}
