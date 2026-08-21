<?php

namespace App\Domain\Contracts;

use App\Domain\Expenses\ExpenseLineType;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContractExpenseActivity
{
    /**
     * @param  iterable<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $context
     * @return array{actual_kind: ContractActualKind, activity_note: ?string, today: string}
     */
    public static function validate(Contract $contract, Exercise $exercise, Company $company, iterable $lines, array $context): array
    {
        /** @var array{actual_kind: ?string, activity_note: ?string} $validated */
        $validated = Validator::make([
            'actual_kind' => $context['actual_kind'] ?? null,
            'activity_note' => self::nullableTrim($context['activity_note'] ?? null),
        ], [
            'actual_kind' => ['nullable', Rule::enum(ContractActualKind::class)],
            'activity_note' => ['nullable', 'string'],
        ])->validate();

        if ($contract->isArchived()) {
            throw ValidationException::withMessages(['contract_id' => 'Ripristinare il Contratto prima di registrare nuova attività.']);
        }
        if (! $exercise->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'L’Esercizio deve essere Aperto.']);
        }

        self::assertActualOnly($lines);

        $kind = $validated['actual_kind'] === null
            ? ContractActualKind::Ordinary
            : ContractActualKind::from($validated['actual_kind']);
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $state = $contract->stateAtDate($today->toDateString());
        if ($kind === ContractActualKind::Ordinary) {
            if ($state !== ContractState::Active) {
                throw ValidationException::withMessages(['actual_kind' => 'Un Effettivo ordinario richiede il Contratto Attivo alla data aziendale.']);
            }
        } else {
            if (! in_array($state, [ContractState::Cessated, ContractState::Cancelled], true)) {
                throw ValidationException::withMessages(['actual_kind' => 'La dichiarazione terminale è ammessa soltanto per un Contratto Cessato o Annullato.']);
            }
            if ($validated['activity_note'] === null) {
                throw ValidationException::withMessages(['activity_note' => 'La Nota è obbligatoria per un Effettivo terminale.']);
            }
        }

        return ['actual_kind' => $kind, 'activity_note' => $validated['activity_note'], 'today' => $today->toDateString()];
    }

    /** @param iterable<array<string, mixed>> $lines */
    public static function assertActualOnly(iterable $lines): void
    {
        foreach ($lines as $line) {
            $type = $line['type'] ?? null;
            $lineType = $type instanceof ExpenseLineType ? $type : ExpenseLineType::from((string) $type);
            if ($lineType !== ExpenseLineType::Actual) {
                throw ValidationException::withMessages(['lines' => 'Una Spesa manuale di Contratto può contenere soltanto Righe Effettivo.']);
            }
        }
    }

    private static function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
