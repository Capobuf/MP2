<?php

namespace App\Domain\Expenses;

use App\Models\Company;
use App\Models\Exercise;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ManualExpenseLine
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{type: string, amount: string, quantity: ?string, unit_amount: ?string, unit_of_measure: ?string, note: ?string}
     */
    public static function validate(array $input, Company $company, Exercise $exercise, bool $isNew = true): array
    {
        $normalized = [
            'type' => $input['type'] ?? null,
            'amount' => self::trim($input['amount'] ?? null),
            'quantity' => self::nullableTrim($input['quantity'] ?? null),
            'unit_amount' => self::nullableTrim($input['unit_amount'] ?? null),
            'unit_of_measure' => self::nullableTrim($input['unit_of_measure'] ?? null),
            'note' => self::nullableTrim($input['note'] ?? null),
            'amount_warning_acknowledged' => filter_var($input['amount_warning_acknowledged'] ?? false, FILTER_VALIDATE_BOOL),
        ];

        /** @var array{type: string, amount: string, quantity: ?string, unit_amount: ?string, unit_of_measure: ?string, note: ?string, amount_warning_acknowledged: bool} $validated */
        $validated = Validator::make($normalized, [
            'type' => ['required', Rule::enum(ExpenseLineType::class)],
            'amount' => ['required', 'regex:/^-?\d{1,17}(\.\d{1,2})?$/'],
            'quantity' => ['nullable', 'regex:/^-?\d{1,14}(\.\d{1,6})?$/'],
            'unit_amount' => ['nullable', 'regex:/^-?\d{1,14}(\.\d{1,6})?$/'],
            'unit_of_measure' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string'],
            'amount_warning_acknowledged' => ['boolean'],
        ], [
            'amount.regex' => 'L’importo deve avere al massimo due decimali.',
            'quantity.regex' => 'La quantità deve avere al massimo sei decimali.',
            'unit_amount.regex' => 'L’importo unitario deve avere al massimo sei decimali.',
        ])->validate();

        $amount = Decimal::money($validated['amount']);
        $type = ExpenseLineType::from($validated['type']);

        if ($type === ExpenseLineType::Estimate && Decimal::compare($amount, '0') < 0) {
            throw ValidationException::withMessages(['amount' => 'Una Stima non può essere negativa.']);
        }

        if ($type === ExpenseLineType::Actual && Decimal::compare($amount, '0') < 0 && $validated['note'] === null) {
            throw ValidationException::withMessages(['note' => 'Una Nota è obbligatoria per un Effettivo negativo.']);
        }

        if ($isNew && Decimal::compare($amount, '0') === 0 && $validated['note'] === null) {
            throw ValidationException::withMessages(['note' => 'Indicare il motivo della nuova Riga a importo zero.']);
        }

        if ($type === ExpenseLineType::Actual && $exercise->year > now($company->timezone)->year) {
            throw ValidationException::withMessages(['type' => 'Non è possibile registrare un Effettivo in un anno futuro.']);
        }

        if (self::hasAmountMismatch($validated['quantity'], $validated['unit_amount'], $amount)
            && ! $validated['amount_warning_acknowledged']) {
            $suggested = self::suggestedAmount($validated['quantity'], $validated['unit_amount']);
            throw ValidationException::withMessages([
                'amount_warning_acknowledged' => "Quantità × importo unitario produce € {$suggested}, mentre l’importo indicato è € {$amount}. Verrà salvato l’importo indicato.",
            ]);
        }

        return [
            'type' => $type->value,
            'amount' => $amount,
            'quantity' => $validated['quantity'],
            'unit_amount' => $validated['unit_amount'],
            'unit_of_measure' => $validated['unit_of_measure'],
            'note' => $validated['note'],
        ];
    }

    public static function suggestedAmount(?string $quantity, ?string $unitAmount): ?string
    {
        return $quantity !== null && $unitAmount !== null
            ? Decimal::multiply($quantity, $unitAmount)
            : null;
    }

    public static function hasAmountMismatch(?string $quantity, ?string $unitAmount, string $amount): bool
    {
        $suggested = self::suggestedAmount($quantity, $unitAmount);

        return $suggested !== null && Decimal::compare($suggested, $amount, 2) !== 0;
    }

    private static function trim(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private static function nullableTrim(mixed $value): mixed
    {
        $value = self::trim($value);

        return $value === '' ? null : $value;
    }
}
