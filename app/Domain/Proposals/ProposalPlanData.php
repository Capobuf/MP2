<?php

namespace App\Domain\Proposals;

use Illuminate\Validation\ValidationException;

final class ProposalPlanData
{
    /** @return list<array<string, mixed>> */
    public static function rows(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            throw ValidationException::withMessages([$field => 'Il piano persistito non è un elenco valido.']);
        }

        $rows = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([$field => 'Il piano persistito contiene un elemento non valido.']);
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
