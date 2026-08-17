<?php

namespace App\Domain\Expenses;

enum ExerciseStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aperto',
            self::Closed => 'Chiuso',
        };
    }
}
