<?php

namespace App\Filament\Resources\Expenses\Actions;

use App\Actions\Operations\CreateExpense;
use App\Domain\Expenses\Decimal;
use App\Filament\Forms\DecimalInput;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\TenantCompany;
use App\Models\User;
use App\Support\ExerciseContext;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RegisterContractPayment
{
    public static function make(?Contract $contract = null): Action
    {
        return Action::make('registerContractPayment')
            ->label('Registra Pagamento')
            ->icon('heroicon-o-banknotes')
            ->visible(fn (): bool => self::company() !== null
                && auth()->user()?->can('create', [Expense::class, self::company()]) === true
                && ($contract === null || ! $contract->isArchived()))
            ->disabled(fn (): bool => self::disabledReason() !== null)
            ->tooltip(fn (): ?string => self::disabledReason())
            ->modalHeading('Registra Pagamento')
            ->modalDescription(fn (): string => 'Registra un Effettivo del Contratto per l’Esercizio '.self::exercise()?->year.'.')
            ->modalSubmitActionLabel('Registra Pagamento')
            ->schema([
                Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                Hidden::make('container')->default('contract')->dehydrated(false),
                Select::make('contract_id')->label('Contratto')
                    ->options(fn (): array => self::company() === null ? [] : Contract::query()
                        ->whereBelongsTo(self::company(), 'company')->active()->orderBy('title')->pluck('title', 'id')->all())
                    ->default($contract?->id)
                    ->disabled($contract !== null)->dehydrated()
                    ->searchable()->required()->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('amount', self::annualEstimate($state));
                        $set('actual_kind', null);
                        $set('activity_note', null);
                    }),
                TextInput::make('description')->label('Descrizione')->required()->maxLength(255),
                DecimalInput::make('amount')->label('Importo')->suffix('EUR')->required()->live(onBlur: true)
                    ->default(fn (): ?string => self::annualEstimate($contract?->id))
                    ->helperText('Importo iniziale pari alla Stima annuale del Contratto. Puoi modificarlo. EUR, netto IVA.'),
                Textarea::make('note')->label('Nota')
                    ->helperText('Obbligatoria per un rimborso, un accredito o una correzione con importo negativo, oppure per un importo zero.'),
                Textarea::make('change_reason')->label('Motivo della Variazione rispetto al Budget')
                    ->helperText('Richiesto perché l’Esercizio ha già un Budget approvato.')
                    ->visible(fn (): bool => self::exercise()?->hasApprovedBudget() === true)
                    ->required(fn (Get $get): bool => self::exercise()?->hasApprovedBudget() === true
                        && preg_match('/^-?\d+(?:\.\d+)?$/', (string) Decimal::normalizeInput($get('amount'))) === 1
                        && Decimal::compare((string) Decimal::normalizeInput($get('amount')), '0') !== 0),
                ...ExpenseForm::creationActivityFields(),
            ])
            ->action(function (array $data, Action $action, Schema $schema) use ($contract): void {
                $actor = auth()->user();
                $company = self::company();
                $exercise = self::exercise();
                abort_unless($actor instanceof User && $company instanceof Company && $exercise instanceof Exercise, 403);

                try {
                    $expense = app(CreateExpense::class)->execute($actor, $company, [
                        'exercise_id' => $exercise->id,
                        'contract_id' => $contract->id ?? $data['contract_id'],
                        'description' => $data['description'],
                        'change_reason' => $data['change_reason'] ?? null,
                        'actual_kind' => $data['actual_kind'] ?? null,
                        'activity_note' => $data['activity_note'] ?? null,
                        'lines' => [[
                            'type' => 'actual',
                            'amount' => $data['amount'],
                            'note' => $data['note'] ?? null,
                        ]],
                    ], $data['operation_id']);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Pagamento non registrato')
                        ->body(collect($exception->errors())->flatten()->implode(' '))->send();

                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])
                        ->all());
                }

                $action->successNotificationTitle('Pagamento registrato')->sendSuccessNotification();
                $action->redirect(ExpenseResource::getUrl('view', ['record' => $expense]));
            });
    }

    private static function annualEstimate(mixed $contractId): ?string
    {
        $company = self::company();
        $exercise = self::exercise();
        if ($company === null || $exercise === null || blank($contractId)) {
            return null;
        }

        $contract = Contract::query()->whereBelongsTo($company, 'company')->active()->find($contractId);

        return $contract instanceof Contract ? ($contract->annualTotals()[$exercise->id]['allocation'] ?? '0.00') : null;
    }

    private static function disabledReason(): ?string
    {
        $exercise = self::exercise();
        if ($exercise === null) {
            return 'Seleziona un Esercizio globale prima di registrare il Pagamento.';
        }
        if (! $exercise->isOpen()) {
            return 'L’Esercizio globale selezionato è Chiuso.';
        }

        return $exercise->year > now($exercise->company->timezone)->year
            ? 'Non è possibile registrare un Effettivo in un anno futuro.'
            : null;
    }

    private static function company(): ?Company
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof TenantCompany ? $tenant->company : null;
    }

    private static function exercise(): ?Exercise
    {
        $company = self::company();

        return $company === null ? null : app(ExerciseContext::class)->current($company);
    }
}
