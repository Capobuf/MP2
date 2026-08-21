<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Expenses\ManualExpenseLine;
use App\Domain\Projects\ProjectActualKind;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\Supplier;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Spesa')->schema([
                TextInput::make('description')->label('Descrizione')->required()->maxLength(255),
                Textarea::make('notes')->label('Note')->columnSpanFull(),
                Select::make('exercise_id')
                    ->label('Esercizio')
                    ->options(fn (): array => self::company() instanceof Company
                        ? Exercise::query()->whereBelongsTo(self::company(), 'company')->open()->orderByDesc('year')->pluck('year', 'id')->all()
                        : [])
                    ->default(fn (): ?int => request()->integer('exercise') ?: null)
                    ->required(),
                Select::make('project_id')
                    ->label('Contenitore Progetto')
                    ->options(fn (): array => self::company() instanceof Company
                        ? Project::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('title')->pluck('title', 'id')->all()
                        : [])
                    ->default(fn (): ?int => request()->integer('project') ?: null)
                    ->searchable()
                    ->live()
                    ->placeholder('Autonoma'),
                Select::make('supplier_id')
                    ->label('Fornitore')
                    ->options(fn (): array => self::company() instanceof Company
                        ? Supplier::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all()
                        : [])
                    ->searchable()
                    ->placeholder('Nessuno'),
                Select::make('direct_cost_center_id')
                    ->label('Centro di Costo')
                    ->options(fn (): array => self::company() instanceof Company
                        ? CostCenter::query()->whereBelongsTo(self::company(), 'company')->active()->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->searchable()
                    ->placeholder('Non classificata')
                    ->visible(fn (Get $get): bool => blank($get('project_id')))
                    ->dehydrated(fn (Get $get): bool => blank($get('project_id'))),
            ])->columns(2),
            Section::make('Attività Effettiva del Progetto')->schema([
                Select::make('actual_kind')->label('Dichiarazione Effettivo')->options(ProjectActualKind::options())->placeholder('Ordinario'),
                Checkbox::make('open_project')->label('Conferma apertura atomica se il Progetto è Pianificato'),
                Textarea::make('activity_note')->label('Nota attività tardiva, rimborso o correzione')->columnSpanFull(),
                Textarea::make('overspend_note')->label('Nota di sovraspesa')->columnSpanFull(),
            ])->columns(2)->visible(fn (Get $get): bool => filled($get('project_id'))),
            Section::make('Righe iniziali')->schema([
                Repeater::make('lines')
                    ->label('Righe')
                    ->schema(self::lineFields())
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Aggiungi riga')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    /** @return array<int, mixed> */
    public static function lineFields(): array
    {
        return [
            ...self::economicLineFields(),
            ...self::descriptiveLineFields(),
            ...self::lineNoteFields(),
        ];
    }

    /** @return array<int, mixed> */
    public static function lineFormSections(): array
    {
        return [
            Section::make('Valore economico')->description('Il Tipo appartiene alla Riga. L’Importo resta il valore autoritativo.')
                ->schema(self::economicLineFields())->columns(2),
            Section::make('Dettagli descrittivi')->description('Quantità e unitario aiutano la lettura ma non sostituiscono l’Importo.')
                ->schema(self::descriptiveLineFields())->columns(3),
            Section::make('Nota e verifica')->schema(self::lineNoteFields()),
        ];
    }

    public static function projectActivitySection(bool $visible): Section
    {
        return Section::make('Impatto sul Progetto')
            ->description('Richiesto solo quando la Riga Effettivo modifica lo stato o l’equilibrio economico del Progetto.')
            ->schema(self::projectActivityFields($visible))
            ->columns(2)
            ->visible($visible);
    }

    /** @return array<int, mixed> */
    public static function projectActivityFields(bool $visible): array
    {
        return [
            Select::make('actual_kind')
                ->label('Dichiarazione Effettivo')
                ->options(ProjectActualKind::options())
                ->placeholder('Ordinario (predefinito)')
                ->visible($visible),
            Checkbox::make('open_project')
                ->label('Conferma apertura atomica se il Progetto è Pianificato')
                ->visible($visible),
            Textarea::make('activity_note')
                ->label('Nota attività tardiva, rimborso o correzione')
                ->visible($visible),
            Textarea::make('overspend_note')
                ->label('Nota di sovraspesa')
                ->visible($visible),
        ];
    }

    /** @return array<int, mixed> */
    private static function economicLineFields(): array
    {
        return [
            Select::make('type')->label('Tipo Riga')->options(ExpenseLineType::options())->required()->native(false)->live(),
            TextInput::make('amount')->label('Importo')->helperText('Valore autoritativo in EUR, netto IVA.')
                ->suffix('EUR')->inputMode('decimal')->required()->regex('/^-?\d{1,17}(\.\d{1,2})?$/')->live(),
        ];
    }

    /** @return array<int, mixed> */
    private static function descriptiveLineFields(): array
    {
        return [
            TextInput::make('quantity')->label('Quantità')->inputMode('decimal')->regex('/^-?\d{1,14}(\.\d{1,6})?$/')->live(),
            TextInput::make('unit_amount')->label('Importo unitario')->suffix('EUR')->inputMode('decimal')
                ->regex('/^-?\d{1,14}(\.\d{1,6})?$/')->live(),
            TextInput::make('unit_of_measure')->label('Unità di misura')->maxLength(64),
        ];
    }

    /** @return array<int, mixed> */
    private static function lineNoteFields(): array
    {
        return [
            Textarea::make('note')->label('Nota')
                ->helperText('Obbligatoria per un Effettivo negativo e normalmente richiesta per una nuova Riga a zero.'),
            Checkbox::make('amount_warning_acknowledged')
                ->label('Salva comunque l’Importo indicato')
                ->helperText(fn (Get $get): string => self::amountMismatchMessage($get))
                ->visible(fn (Get $get): bool => self::hasAmountMismatch($get)),
        ];
    }

    private static function hasAmountMismatch(Get $get): bool
    {
        $quantity = is_string($get('quantity')) ? $get('quantity') : null;
        $unitAmount = is_string($get('unit_amount')) ? $get('unit_amount') : null;
        $amount = is_string($get('amount')) ? $get('amount') : null;

        return $amount !== null && ManualExpenseLine::hasAmountMismatch($quantity, $unitAmount, $amount);
    }

    private static function amountMismatchMessage(Get $get): string
    {
        $quantity = is_string($get('quantity')) ? $get('quantity') : null;
        $unitAmount = is_string($get('unit_amount')) ? $get('unit_amount') : null;
        $amount = is_string($get('amount')) ? $get('amount') : null;
        $suggested = ManualExpenseLine::suggestedAmount($quantity, $unitAmount);

        return "Quantità × importo unitario suggerisce € {$suggested}; l’Importo autoritativo indicato è € {$amount}.";
    }

    private static function company(): ?Company
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? $company : null;
    }
}
