<?php

namespace App\Filament\Forms;

use App\Domain\Expenses\Decimal;
use Filament\Forms\Components\TextInput;

final class MoneyInput
{
    public static function make(string $name, int $integerDigits = 17): TextInput
    {
        return DecimalInput::make($name, $integerDigits)
            ->formatStateUsing(fn (mixed $state): mixed => filled($state) ? Decimal::money($state) : $state)
            ->afterStateUpdated(function (TextInput $component, mixed $state): void {
                $normalized = self::normalizeInput($state);
                if (is_string($normalized) && preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized) === 1) {
                    $component->state(Decimal::money($normalized));
                }
            })
            ->mutateStateForValidationUsing(fn (mixed $state): mixed => self::normalizeInput($state))
            ->dehydrateStateUsing(fn (mixed $state): mixed => self::normalizeInput($state));
    }

    public static function preserveStoredPrecision(mixed $value, ?string $stored): mixed
    {
        $normalized = self::normalizeInput($value);

        // Saving an unchanged rounded display must not overwrite stored precision.
        if ($stored !== null && is_string($normalized)
            && preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized) === 1
            && Decimal::compare($normalized, Decimal::money($stored)) === 0) {
            return $stored;
        }

        return $normalized;
    }

    public static function normalizeInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if (preg_match('/^-?\d{1,3}(?:\.\d{3})+(?:,\d+)?$/', $value) === 1) {
            return str_replace(',', '.', str_replace('.', '', $value));
        }

        if (preg_match('/^-?\d+(?:[.,]\d+)?$/', $value) === 1) {
            return str_replace(',', '.', $value);
        }

        return $value;
    }
}
