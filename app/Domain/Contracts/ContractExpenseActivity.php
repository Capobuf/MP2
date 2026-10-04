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
     * @return array{actual_kind: ?ContractActualKind, activity_note: ?string, residual_estimate: bool, today: string}
     */
    public static function validate(Contract $contract, Exercise $exercise, Company $company, iterable $lines, array $context): array
    {
        /** @var array{actual_kind: ?string, activity_note: ?string, residual_estimate: bool} $validated */
        $validated = Validator::make([
            'actual_kind' => $context['actual_kind'] ?? null,
            'activity_note' => self::nullableTrim($context['activity_note'] ?? null),
            'residual_estimate' => $context['residual_estimate'] ?? false,
        ], [
            'actual_kind' => ['nullable', Rule::enum(ContractActualKind::class)],
            'activity_note' => ['nullable', 'string'],
            'residual_estimate' => ['boolean'],
        ])->validate();

        if ($contract->isArchived()) {
            throw ValidationException::withMessages(['contract_id' => 'Ripristinare il Contratto prima di registrare nuova attività.']);
        }
        if ($contract->company_id !== $company->id || $exercise->company_id !== $company->id) {
            throw ValidationException::withMessages(['contract_id' => 'Contratto ed Esercizio devono appartenere alla stessa Azienda.']);
        }
        if (! $exercise->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'L’Esercizio deve essere Aperto.']);
        }

        $types = collect($lines)->map(fn (array $line): ExpenseLineType => $line['type'] instanceof ExpenseLineType
            ? $line['type'] : ExpenseLineType::from((string) $line['type']));
        $hasEstimates = $types->contains(ExpenseLineType::Estimate);
        $hasActuals = $types->contains(ExpenseLineType::Actual);

        $kind = ! $hasActuals ? null : ($validated['actual_kind'] === null
            ? ContractActualKind::Ordinary
            : ContractActualKind::from($validated['actual_kind']));
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $state = $contract->stateAtDate($today->toDateString());
        $terminal = in_array($state, [ContractState::Cessated, ContractState::Cancelled], true);
        if ($hasEstimates && $terminal && (! $validated['residual_estimate'] || $validated['activity_note'] === null)) {
            throw ValidationException::withMessages(['residual_estimate' => 'Dichiarare il costo residuo e indicare una Nota per aggiungere o ripristinare Stime in un Contratto Cessato o Annullato.']);
        }
        if ($hasActuals && $exercise->year > $today->year) {
            throw ValidationException::withMessages(['exercise_id' => 'Gli Effettivi non possono appartenere a un anno futuro.']);
        }
        if ($kind === ContractActualKind::Ordinary) {
            if ($state !== ContractState::Active) {
                throw ValidationException::withMessages(['actual_kind' => 'Un Effettivo ordinario richiede il Contratto Attivo alla data aziendale.']);
            }
        } elseif ($kind !== null) {
            if (! $terminal) {
                throw ValidationException::withMessages(['actual_kind' => 'La dichiarazione terminale è ammessa soltanto per un Contratto Cessato o Annullato.']);
            }
            if ($validated['activity_note'] === null) {
                throw ValidationException::withMessages(['activity_note' => 'La Nota è obbligatoria per un Effettivo terminale.']);
            }
        }

        return ['actual_kind' => $kind, 'activity_note' => $validated['activity_note'], 'residual_estimate' => $hasEstimates && $terminal && $validated['residual_estimate'], 'today' => $today->toDateString()];
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
