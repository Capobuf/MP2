<?php

namespace App\Domain\Contracts;

enum ContractActualKind: string
{
    case Ordinary = 'ordinary';
    case Late = 'late';
    case CessationCost = 'cessation_cost';
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
            self::Late => 'Addebito tardivo',
            self::CessationCost => 'Costo di cessazione',
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
