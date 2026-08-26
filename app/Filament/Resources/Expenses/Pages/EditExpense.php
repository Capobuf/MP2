<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\UpdateExpense;
use App\Actions\Operations\UpdateExpenseLine;
use App\Domain\Contracts\ContractActualKind;
use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Expenses\ManualExpenseLine;
use App\Domain\Projects\ProjectActualKind;
use App\Domain\Projects\ProjectExpenseActivity;
use App\Domain\Projects\ProjectOverspend;
use App\Domain\Projects\ProjectOverspendResult;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Support\ProjectOverspendNotifier;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected static ?string $title = 'Modifica spesa';

    public string $operationId;

    public function mount(int|string $record): void
    {
        $this->operationId = (string) Str::uuid();
        parent::mount($record);
    }

    public function getSubheading(): ?string
    {
        $expense = $this->expenseRecord();

        return "Esercizio {$expense->exercise->year} · {$expense->exercise->status()->label()} · {$expense->containerLabel()}";
    }

    public function form(Schema $schema): Schema
    {
        $expense = $this->expenseRecord();

        return $schema->components([
            Section::make('Spesa')->schema([
                TextInput::make('description')->label('Descrizione')->required()->maxLength(255)->live(onBlur: true),
                Select::make('container')
                    ->label('Contenitore')
                    ->options([
                        'autonomous' => 'Autonoma',
                        'project' => 'Progetto',
                        'contract' => 'Contratto',
                    ])
                    ->disabled()
                    ->dehydrated(false),
                Select::make('project_id')
                    ->label('Progetto')
                    ->options($expense->project === null ? [] : [$expense->project->id => $expense->project->title])
                    ->visible(fn (Get $get): bool => $get('container') === 'project')
                    ->disabled()
                    ->dehydrated(false),
                Select::make('contract_id')
                    ->label('Contratto')
                    ->options($expense->contract === null ? [] : [$expense->contract->id => $expense->contract->title])
                    ->visible(fn (Get $get): bool => $get('container') === 'contract')
                    ->disabled()
                    ->dehydrated(false),
                Select::make('supplier_id')
                    ->label('Fornitore')
                    ->options($expense->supplier === null ? [] : [$expense->supplier->id => $expense->supplier->legal_name])
                    ->placeholder('Nessuno')
                    ->visible(fn (Get $get): bool => $get('container') !== 'contract')
                    ->disabled()
                    ->dehydrated(false),
                Select::make('direct_cost_center_id')
                    ->label('Centro di Costo')
                    ->options($expense->directCostCenter === null ? [] : [$expense->directCostCenter->id => $expense->directCostCenter->name])
                    ->placeholder('Non classificata')
                    ->visible(fn (Get $get): bool => $get('container') === 'autonomous')
                    ->disabled()
                    ->dehydrated(false),
                Placeholder::make('classification_notice')
                    ->hiddenLabel()
                    ->content('Esercizio, contenitore, fornitore e Centro di Costo si modificano con “Sposta o riclassifica”, che mostra prima l’impatto economico.')
                    ->columnSpanFull(),
                Textarea::make('notes')->label('Note')->columnSpanFull(),
                Textarea::make('change_reason')
                    ->label('Motivo della modifica')
                    ->helperText('Richiesto dopo un Budget approvato quando cambia la Descrizione o viene aggiunta o modificata una Stima.')
                    ->visible(fn (Get $get): bool => $this->changeReasonRequired($get))
                    ->required(fn (Get $get): bool => $this->changeReasonRequired($get))
                    ->dehydrated(fn (Get $get): bool => $this->changeReasonRequired($get))
                    ->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
            Section::make('Righe')
                ->description('Le Righe persistite conservano la propria identità e non possono essere eliminate. Puoi modificarle, aggiungerne altre o duplicarle; annullamento e ripristino restano disponibili nel dettaglio della Spesa.')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->schema(ExpenseForm::repeaterLineFields(
                            contractActualOnly: $expense->contract_id !== null,
                            preserveIdentity: true,
                        ))
                        ->columns(12)
                        ->cloneable()
                        ->cloneAction(fn (Action $action): Action => $action->after(function (Repeater $component): void {
                            $items = $component->getRawState();
                            $clonedKey = array_key_last($items);
                            if ($clonedKey === null || ! is_array($items[$clonedKey] ?? null)) {
                                return;
                            }

                            $items[$clonedKey]['line_id'] = null;
                            $component->rawState($items);
                        }))
                        ->deletable()
                        ->deleteAction(fn (Action $action): Action => $action
                            ->label('Rimuovi riga')
                            ->visible(function (array $arguments, Repeater $component): bool {
                                $item = $component->getRawState()[$arguments['item']] ?? null;

                                return is_array($item) && blank($item['line_id'] ?? null);
                            }))
                        ->reorderable(false)
                        ->minItems(1)
                        ->addActionLabel('Aggiungi riga')
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Informazioni aggiuntive')
                ->description('Sono richieste soltanto quando le modifiche alle Righe Effettivo incidono sullo stato del contenitore o sulla sovraspesa.')
                ->schema([
                    Select::make('actual_kind')
                        ->label('Tipo di Effettivo')
                        ->options(fn (): array => $this->terminalActualOptions())
                        ->visible(fn (Get $get): bool => $this->requiresTerminalDeclaration($get))
                        ->required(fn (Get $get): bool => $this->requiresTerminalDeclaration($get))
                        ->dehydrated(fn (Get $get): bool => $this->requiresTerminalDeclaration($get)),
                    Checkbox::make('open_project')
                        ->label('Confermo l’apertura del Progetto')
                        ->accepted()
                        ->visible(fn (Get $get): bool => $this->requiresProjectOpening($get))
                        ->required(fn (Get $get): bool => $this->requiresProjectOpening($get))
                        ->dehydrated(fn (Get $get): bool => $this->requiresProjectOpening($get)),
                    Textarea::make('activity_note')
                        ->label('Motivo dell’attività tardiva o correttiva')
                        ->visible(fn (Get $get): bool => $this->requiresTerminalDeclaration($get))
                        ->required(fn (Get $get): bool => $this->requiresTerminalDeclaration($get))
                        ->dehydrated(fn (Get $get): bool => $this->requiresTerminalDeclaration($get))
                        ->columnSpanFull(),
                    Textarea::make('overspend_note')
                        ->label('Nota di sovraspesa')
                        ->visible(fn (Get $get): bool => $this->requiresOverspendNote($get))
                        ->required(fn (Get $get): bool => $this->requiresOverspendNote($get))
                        ->dehydrated(fn (Get $get): bool => $this->requiresOverspendNote($get))
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => $this->requiresTerminalDeclaration($get)
                    || $this->requiresProjectOpening($get)
                    || $this->requiresOverspendNote($get))
                ->columnSpanFull(),
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $expense = $this->expenseRecord();
        $expense->loadMissing('lines');

        $data['container'] = $expense->contract_id !== null
            ? 'contract'
            : ($expense->project_id !== null ? 'project' : 'autonomous');
        $data['lines'] = $expense->lines
            ->sortBy('id')
            ->map(fn (ExpenseLine $line): array => [
                'line_id' => $line->id,
                'type' => $line->lineType()->value,
                'amount' => (string) $line->amount,
                'quantity' => $this->displayDecimal($line->getRawOriginal('quantity')),
                'unit_amount' => $this->displayDecimal($line->getRawOriginal('unit_amount')),
                'unit_of_measure' => $line->unit_of_measure,
                'note' => $line->note,
                'suggested_amount' => ManualExpenseLine::suggestedAmount(
                    $line->getRawOriginal('quantity'),
                    $line->getRawOriginal('unit_amount'),
                ),
                'amount_warning_acknowledged' => false,
            ])
            ->values()
            ->all();

        if (request()->boolean('addLine')) {
            $data['lines'][] = [
                'line_id' => null,
                'type' => $expense->contract_id === null ? null : ExpenseLineType::Actual->value,
                'amount' => null,
                'quantity' => null,
                'unit_amount' => null,
                'unit_of_measure' => null,
                'note' => null,
            ];
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $record instanceof Expense, 403);

        $submittedLines = $data['lines'] ?? null;
        if (! is_array($submittedLines)) {
            throw ValidationException::withMessages(['lines' => 'Le Righe della Spesa non sono valide.']);
        }

        $record->load('lines');
        $mutations = $this->lineMutations($record, $submittedLines);
        $shared = [
            'change_reason' => $data['change_reason'] ?? null,
            'actual_kind' => $data['actual_kind'] ?? null,
            'open_project' => $data['open_project'] ?? false,
            'activity_note' => $data['activity_note'] ?? null,
            'overspend_note' => $data['overspend_note'] ?? null,
        ];
        $notifierOperationIds = [];

        $updated = DB::transaction(function () use ($actor, $record, $data, $mutations, $shared, &$notifierOperationIds): Expense {
            $updated = $record;
            if ($this->detailsChanged($record, $data)) {
                $updated = app(UpdateExpense::class)->updateDetails($actor, $record, [
                    'description' => $data['description'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'change_reason' => $data['change_reason'] ?? null,
                ], Uuid::uuid5($this->operationId, 'expense-details')->toString());
            }

            foreach ($mutations as $index => $mutation) {
                $operationId = Uuid::uuid5(
                    $this->operationId,
                    "line:{$index}:{$mutation['mode']}:{$mutation['key']}",
                )->toString();
                $lineData = [...$mutation['data'], ...$shared];

                try {
                    if ($mutation['mode'] === 'update') {
                        app(UpdateExpenseLine::class)->execute($actor, $mutation['line'], $lineData, $operationId);
                    } else {
                        app(CreateExpenseLine::class)->execute($actor, $updated, $lineData, $operationId);
                    }
                } catch (ValidationException $exception) {
                    throw $this->lineValidationException($exception, $mutation['key']);
                }

                $notifierOperationIds[] = $operationId;
            }

            return $updated->refresh();
        });

        foreach ($notifierOperationIds as $operationId) {
            ProjectOverspendNotifier::sendForOperation($operationId);
        }

        return $updated;
    }

    protected function afterSave(): void
    {
        $this->operationId = (string) Str::uuid();
    }

    protected function getRedirectUrl(): string
    {
        return ExpenseResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    private function expenseRecord(): Expense
    {
        $record = $this->getRecord();
        if (! $record instanceof Expense) {
            throw new \UnexpectedValueException('Invalid Expense record.');
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $submittedLines
     * @return array<int, array{mode: 'create'|'update', key: string, data: array<string, mixed>, line: ExpenseLine|null, delta: string}>
     */
    private function lineMutations(Expense $expense, array $submittedLines): array
    {
        $existing = $expense->lines->keyBy('id');
        $processed = [];
        $mutations = [];

        foreach ($submittedLines as $key => $submittedLine) {
            if (! is_array($submittedLine)) {
                throw ValidationException::withMessages(["lines.{$key}" => 'La Riga non è valida.']);
            }

            $rawLineId = $submittedLine['line_id'] ?? null;
            $lineId = $this->submittedLineId($rawLineId);
            if ($rawLineId !== null && $rawLineId !== '' && $lineId === null) {
                throw ValidationException::withMessages(["lines.{$key}.line_id" => 'L’identificativo della Riga non è valido.']);
            }
            if ($lineId !== null && ! $existing->has($lineId)) {
                throw ValidationException::withMessages(["lines.{$key}.line_id" => 'La Riga non appartiene a questa Spesa.']);
            }

            unset($submittedLine['line_id'], $submittedLine['suggested_amount']);
            $line = $lineId === null ? null : $existing->get($lineId);
            if ($line instanceof ExpenseLine && ! isset($processed[$lineId])) {
                $processed[$lineId] = true;
                if (! $this->lineChanged($line, $submittedLine)) {
                    continue;
                }

                $mutations[] = [
                    'mode' => 'update',
                    'key' => (string) $key,
                    'data' => $submittedLine,
                    'line' => $line,
                    'delta' => $this->lineVarianceDelta($line, $submittedLine),
                ];

                continue;
            }

            $mutations[] = [
                'mode' => 'create',
                'key' => (string) $key,
                'data' => $submittedLine,
                'line' => null,
                'delta' => $this->lineVarianceContribution($submittedLine),
            ];
        }

        if ($expense->project_id !== null) {
            usort($mutations, fn (array $left, array $right): int => Decimal::compare($left['delta'], $right['delta'], 2));
        }

        return $mutations;
    }

    /** @param array<string, mixed> $data */
    private function detailsChanged(Expense $expense, array $data): bool
    {
        $description = is_string($data['description'] ?? null) ? trim($data['description']) : $data['description'] ?? null;
        $notes = $this->nullableTrim($data['notes'] ?? null);

        return $description !== $expense->description || $notes !== $expense->notes;
    }

    /** @param array<string, mixed> $data */
    private function lineChanged(ExpenseLine $line, array $data): bool
    {
        return ($data['type'] ?? null) !== $line->lineType()->value
            || $this->decimalChanged($data['amount'] ?? null, (string) $line->amount, 2)
            || $this->decimalChanged($data['quantity'] ?? null, $line->getRawOriginal('quantity'), 6)
            || $this->decimalChanged($data['unit_amount'] ?? null, $line->getRawOriginal('unit_amount'), 6)
            || $this->nullableTrim($data['unit_of_measure'] ?? null) !== $line->unit_of_measure
            || $this->nullableTrim($data['note'] ?? null) !== $line->note;
    }

    private function decimalChanged(mixed $newValue, mixed $currentValue, int $scale): bool
    {
        $normalized = Decimal::normalizeInput($newValue);
        if ($normalized === null || $normalized === '') {
            return $currentValue !== null;
        }
        if ((! is_string($normalized) && ! is_int($normalized) && ! is_float($normalized))
            || preg_match('/^-?\d+(?:\.\d+)?$/', (string) $normalized) !== 1) {
            return true;
        }
        if ($currentValue === null) {
            return true;
        }

        return Decimal::compare((string) $normalized, (string) $currentValue, $scale) !== 0;
    }

    /** @param array<string, mixed> $data */
    private function lineVarianceDelta(ExpenseLine $line, array $data): string
    {
        if ($line->isAnnulled()) {
            return '0.00';
        }

        return Decimal::subtract(
            $this->lineVarianceContribution($data),
            $this->varianceContribution($line->lineType(), (string) $line->amount),
        );
    }

    /** @param array<string, mixed> $data */
    private function lineVarianceContribution(array $data): string
    {
        $type = ExpenseLineType::tryFrom((string) ($data['type'] ?? ''));
        $amount = Decimal::normalizeInput($data['amount'] ?? null);
        if (! $type instanceof ExpenseLineType
            || ! is_string($amount)
            || preg_match('/^-?\d+(?:\.\d+)?$/', $amount) !== 1) {
            return '0.00';
        }

        return $this->varianceContribution($type, $amount);
    }

    private function varianceContribution(ExpenseLineType $type, string $amount): string
    {
        return $type === ExpenseLineType::Actual
            ? Decimal::money($amount)
            : Decimal::subtract('0.00', $amount);
    }

    private function changeReasonRequired(Get $get): bool
    {
        $expense = $this->expenseRecord();
        if (! $expense->exercise->hasApprovedBudget()) {
            return false;
        }
        if (is_string($get('description')) && trim($get('description')) !== $expense->description) {
            return true;
        }

        $existing = $expense->lines->keyBy('id');
        $processed = [];
        foreach ((array) $get('lines') as $lineData) {
            if (! is_array($lineData)) {
                continue;
            }
            $lineId = $this->submittedLineId($lineData['line_id'] ?? null);
            $line = $lineId === null ? null : $existing->get($lineId);
            $newType = ExpenseLineType::tryFrom((string) ($lineData['type'] ?? ''));
            if ($line instanceof ExpenseLine && ! isset($processed[$lineId])) {
                $processed[$lineId] = true;
                if ($this->lineChanged($line, $lineData)
                    && ($line->lineType() === ExpenseLineType::Estimate || $newType === ExpenseLineType::Estimate)) {
                    return true;
                }

                continue;
            }
            if ($newType === ExpenseLineType::Estimate) {
                return true;
            }
        }

        return false;
    }

    private function requiresTerminalDeclaration(Get $get): bool
    {
        if (! $this->hasMutatedActiveActual($get)) {
            return false;
        }

        $expense = $this->expenseRecord();
        $today = now($expense->company->timezone)->toDateString();

        return ($expense->project !== null && in_array($expense->project->stateAtDate($today), [ProjectState::Closed, ProjectState::Cancelled], true))
            || ($expense->contract !== null && in_array($expense->contract->stateAtDate($today), [ContractState::Cessated, ContractState::Cancelled], true));
    }

    private function requiresProjectOpening(Get $get): bool
    {
        $expense = $this->expenseRecord();

        return $this->hasMutatedActiveActual($get)
            && $expense->project !== null
            && $expense->project->stateAtDate(now($expense->company->timezone)->toDateString()) === ProjectState::Planned;
    }

    private function hasMutatedActiveActual(Get $get): bool
    {
        $expense = $this->expenseRecord();
        $existing = $expense->lines->keyBy('id');
        $processed = [];
        foreach ((array) $get('lines') as $lineData) {
            if (! is_array($lineData) || ($lineData['type'] ?? null) !== ExpenseLineType::Actual->value) {
                continue;
            }
            $lineId = $this->submittedLineId($lineData['line_id'] ?? null);
            $line = $lineId === null ? null : $existing->get($lineId);
            if (! $line instanceof ExpenseLine || isset($processed[$lineId])) {
                return true;
            }
            $processed[$lineId] = true;
            if (! $line->isAnnulled() && $this->lineChanged($line, $lineData)) {
                return true;
            }
        }

        return false;
    }

    private function requiresOverspendNote(Get $get): bool
    {
        $expense = $this->expenseRecord();
        if (! $expense->company->overspend_note_required || $expense->project === null) {
            return false;
        }

        $before = ProjectExpenseActivity::annualVariance($expense->project, $expense->exercise);
        $after = $before;
        $existing = $expense->lines->keyBy('id');
        $processed = [];
        foreach ((array) $get('lines') as $lineData) {
            if (! is_array($lineData)) {
                continue;
            }
            $lineId = $this->submittedLineId($lineData['line_id'] ?? null);
            $line = $lineId === null ? null : $existing->get($lineId);
            if ($line instanceof ExpenseLine && ! isset($processed[$lineId])) {
                $processed[$lineId] = true;
                if (! $line->isAnnulled() && $this->lineChanged($line, $lineData)) {
                    $after = Decimal::subtract($after, $this->varianceContribution($line->lineType(), (string) $line->amount));
                    $after = Decimal::add($after, $this->lineVarianceContribution($lineData));
                }

                continue;
            }
            $after = Decimal::add($after, $this->lineVarianceContribution($lineData));
        }

        return ProjectOverspend::detect($before, $after) !== ProjectOverspendResult::None;
    }

    /** @return array<string, string> */
    private function terminalActualOptions(): array
    {
        $options = $this->expenseRecord()->contract_id !== null
            ? ContractActualKind::options()
            : ProjectActualKind::options();
        unset($options[ProjectActualKind::Ordinary->value]);

        return $options;
    }

    private function submittedLineId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $lineId = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($lineId) && $lineId > 0 ? $lineId : null;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function displayDecimal(mixed $value): mixed
    {
        if (! is_string($value) || ! str_contains($value, '.')) {
            return $value;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '-0' ? '0' : $value;
    }

    private function lineValidationException(ValidationException $exception, string $key): ValidationException
    {
        $lineFields = ['type', 'amount', 'quantity', 'unit_amount', 'unit_of_measure', 'note', 'amount_warning_acknowledged'];
        $sharedFields = ['change_reason', 'actual_kind', 'open_project', 'activity_note', 'overspend_note'];
        $messages = [];
        foreach ($exception->errors() as $field => $errors) {
            $target = match (true) {
                in_array($field, $lineFields, true) => "data.lines.{$key}.{$field}",
                in_array($field, $sharedFields, true) => "data.{$field}",
                default => $field,
            };
            foreach ($errors as $error) {
                $messages[$target][] = $error;
            }
        }

        return ValidationException::withMessages($messages);
    }
}
