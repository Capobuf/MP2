<?php

namespace App\Domain\Projects;

enum ProjectActualKind: string
{
    case Ordinary = 'ordinary';
    case Late = 'late';
    case Reimbursement = 'reimbursement';
    case Corrective = 'corrective';

    public function requiresNote(): bool
    {
        return $this !== self::Ordinary;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ordinary => 'Ordinario',
            self::Late => 'Tardivo',
            self::Reimbursement => 'Rimborso',
            self::Corrective => 'Correttivo',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $kind) {
            $options[$kind->value] = $kind->label();
        }

        return $options;
    }
}
