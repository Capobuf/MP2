<?php

namespace App\Filament\Forms;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\TextInput;

final class DateInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->mask('99/99/9999')
            ->inputMode('numeric')
            ->placeholder('gg/mm/aaaa')
            ->maxLength(10)
            ->formatStateUsing(fn (mixed $state): mixed => self::toDisplay($state))
            ->mutateStateForValidationUsing(fn (mixed $state): mixed => self::toIso($state))
            ->rule('date_format:Y-m-d')
            ->dehydrateStateUsing(fn (mixed $state): mixed => self::toIso($state))
            ->live(onBlur: true);
    }

    public static function toDisplay(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        $date = (string) $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $date;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === null || $parsed->format('Y-m-d') !== $date
            ? $date
            : $parsed->format('d/m/Y');
    }

    public static function toIso(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $date = (string) $value;
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date) !== 1) {
            return $date;
        }

        $parsed = CarbonImmutable::createFromFormat('!d/m/Y', $date);

        return $parsed === null || $parsed->format('d/m/Y') !== $date
            ? $date
            : $parsed->toDateString();
    }
}
