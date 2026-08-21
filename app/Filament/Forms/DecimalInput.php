<?php

namespace App\Filament\Forms;

use App\Domain\Expenses\Decimal;
use Filament\Forms\Components\TextInput;

final class DecimalInput
{
    public static function make(string $name, int $integerDigits = 17, int $decimalDigits = 2): TextInput
    {
        return TextInput::make($name)
            ->inputMode('decimal')
            ->mutateStateForValidationUsing(fn (mixed $state): mixed => Decimal::normalizeInput($state))
            ->dehydrateStateUsing(fn (mixed $state): mixed => Decimal::normalizeInput($state))
            ->rules(["decimal:0,{$decimalDigits}"])
            ->regex("/^-?\\d{1,{$integerDigits}}(\\.\\d{1,{$decimalDigits}})?$/");
    }
}
