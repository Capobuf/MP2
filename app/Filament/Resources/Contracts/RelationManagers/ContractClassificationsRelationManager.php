<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\UpdateContractClassification;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\CostCenter;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContractClassificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'classifications';

    protected static ?string $title = 'Classificazioni annuali';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Contract && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('exercise.year')->label('Esercizio')->sortable(),
            TextColumn::make('cost_center')->label('Centro di Costo')->state(fn (ContractExerciseClassification $record): string => $record->costCenter === null
                ? 'Non classificato'
                : $record->costCenter->name.($record->costCenter->isArchived() ? ' · Archiviato' : '')),
        ])->recordActions([
            Action::make('reclassify')
                ->label('Riclassifica')
                ->visible(fn (ContractExerciseClassification $record): bool => $record->exercise->isOpen() && $this->canManage())
                ->form([
                    Select::make('cost_center_id')->label('Nuovo Centro di Costo')->placeholder('Non classificato')
                        ->options(fn (): array => CostCenter::query()->where('company_id', $this->contract()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())
                        ->live(),
                    Placeholder::make('impact_preview')->label('Anteprima esatta')->content(function (Get $get, ContractExerciseClassification $record): string {
                        $actor = auth()->user();
                        if (! $actor instanceof User) {
                            return 'Anteprima non disponibile.';
                        }
                        try {
                            $costCenterId = filled($get('cost_center_id')) ? (int) $get('cost_center_id') : null;
                            $plan = app(UpdateContractClassification::class)->preview($actor, $this->contract(), $record->exercise, $costCenterId);
                        } catch (ValidationException $exception) {
                            return collect($exception->errors())->flatten()->first() ?? 'Anteprima non disponibile.';
                        }

                        return count($plan->expenseIds).' Spese conservano identità e importi; € '.$plan->allocation
                            .' di Allocato ed € '.$plan->actual.' di Effettivo cambiano insieme classificazione annuale.';
                    }),
                    Checkbox::make('impact_confirmed')->label('Confermo l’anteprima corrente')->accepted()->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (ContractExerciseClassification $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $costCenterId = filled($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null;
                    $action = app(UpdateContractClassification::class);
                    $plan = $action->preview($actor, $this->contract(), $record->exercise, $costCenterId);
                    $action->confirm($actor, $this->contract(), $plan, (string) $data['operation_id']);
                }),
        ])->defaultSort('exercise.year');
    }

    private function contract(): Contract
    {
        $record = $this->getOwnerRecord();
        abort_unless($record instanceof Contract, 404);

        return $record;
    }

    private function canManage(): bool
    {
        return ! $this->contract()->isArchived() && auth()->user()?->can('update', $this->contract()) === true;
    }
}
