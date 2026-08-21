<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Actions\Operations\AnnulProjectTransition;
use App\Actions\Operations\CreateProjectTransition;
use App\Actions\Operations\ReplaceProjectTransition;
use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProjectTransitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transitions';

    protected static ?string $title = 'Transizioni';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Project && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_state')->label('Da')->formatStateUsing(fn ($state): string => $state instanceof ProjectState ? $state->label() : ProjectState::from($state)->label()),
                TextColumn::make('to_state')->label('A')->formatStateUsing(fn ($state): string => $state instanceof ProjectState ? $state->label() : ProjectState::from($state)->label()),
                TextColumn::make('effective_date')->label('Data efficacia')->date('d/m/Y')->sortable(),
                TextColumn::make('status')->label('Stato')->state(fn (ProjectTransition $record): string => $record->status($this->today())->label())->badge(),
                TextColumn::make('reason')->label('Motivo')->placeholder('—')->wrap(),
                TextColumn::make('creator.name')->label('Autore'),
            ])
            ->headerActions([
                Action::make('createTransition')
                    ->label('Pianifica transizione')
                    ->modalHeading('Pianifica transizione di Progetto')
                    ->modalSubmitActionLabel('Registra transizione')
                    ->visible(fn (): bool => $this->canMutateOwner())
                    ->form($this->transitionFields())
                    ->action(function (array $data): void {
                        $actor = auth()->user();
                        $project = $this->getOwnerRecord();
                        abort_unless($actor instanceof User && $project instanceof Project, 403);
                        $operationId = (string) $data['operation_id'];
                        unset($data['operation_id']);

                        app(CreateProjectTransition::class)->execute($actor, $project, $data, $operationId);
                        $project->refresh();
                    }),
            ])
            ->recordActions([
                Action::make('annul')
                    ->label('Annulla')
                    ->color('warning')
                    ->modalHeading('Annulla transizione futura')
                    ->modalSubmitActionLabel('Annulla transizione')
                    ->visible(fn (ProjectTransition $record): bool => $this->canChangeFuture($record))
                    ->form([
                        Textarea::make('reason')->label('Motivo annullamento')->required(),
                        Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                    ])
                    ->action(function (ProjectTransition $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(AnnulProjectTransition::class)->execute(
                            $actor,
                            $record,
                            (string) $data['reason'],
                            (string) $data['operation_id'],
                        );
                        $this->getOwnerRecord()->refresh();
                    }),
                Action::make('replace')
                    ->label('Sostituisci')
                    ->modalHeading('Sostituisci transizione futura')
                    ->modalSubmitActionLabel('Sostituisci transizione')
                    ->visible(fn (ProjectTransition $record): bool => $this->canChangeFuture($record))
                    ->form([
                        ...$this->transitionFields(),
                        Textarea::make('replacement_reason')->label('Motivo sostituzione')->required(),
                    ])
                    ->action(function (ProjectTransition $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        $operationId = (string) $data['operation_id'];
                        unset($data['operation_id']);

                        app(ReplaceProjectTransition::class)->execute($actor, $record, $data, $operationId);
                        $this->getOwnerRecord()->refresh();
                    }),
            ])
            ->defaultSort('effective_date')
            ->emptyStateHeading('Nessuna transizione')
            ->emptyStateDescription('Le transizioni pianificate ed efficaci compariranno qui.');
    }

    /** @return array<int, mixed> */
    private function transitionFields(): array
    {
        return [
            DatePicker::make('effective_date')->label('Data di efficacia')->required()->live(),
            Placeholder::make('state_before')
                ->label('Stato immediatamente precedente')
                ->content(function (Get $get, ?ProjectTransition $record): string {
                    $state = $this->stateImmediatelyBefore($get('effective_date'), $record);

                    return $state?->label() ?? 'Assente alla data o data non selezionata';
                }),
            Hidden::make('from_state')
                ->dehydrateStateUsing(fn (mixed $state, Get $get, ?ProjectTransition $record): ?string => $this
                    ->stateImmediatelyBefore($get('effective_date'), $record)?->value),
            Select::make('to_state')->label('Nuovo stato')
                ->options(function (Get $get, ?ProjectTransition $record): array {
                    $from = $this->stateImmediatelyBefore($get('effective_date'), $record);

                    return $from === null
                        ? []
                        : collect(ProjectState::cases())
                            ->filter(fn (ProjectState $state): bool => $from->canTransitionTo($state))
                            ->mapWithKeys(fn (ProjectState $state): array => [$state->value => $state->label()])
                            ->all();
                })->required(),
            Textarea::make('reason')->label('Motivo')
                ->required(function (Get $get, ?ProjectTransition $record): bool {
                    $from = $this->stateImmediatelyBefore($get('effective_date'), $record);
                    $to = is_string($get('to_state')) ? ProjectState::tryFrom($get('to_state')) : null;

                    return $from !== null && $to !== null && $from->transitionRequiresReason($to);
                }),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    private function stateImmediatelyBefore(mixed $effectiveDate, ?ProjectTransition $replaced = null): ?ProjectState
    {
        if (! is_string($effectiveDate) || $effectiveDate === '') {
            return null;
        }

        $project = $this->getOwnerRecord();
        abort_unless($project instanceof Project, 404);
        $transitions = $project->transitions()
            ->when($replaced !== null, fn ($query) => $query->where('id', '!=', $replaced->id))
            ->get();
        $project->setRelation('transitions', $transitions);

        return $project->stateAtDate(CarbonImmutable::parse($effectiveDate)->subDay()->toDateString());
    }

    private function canMutateOwner(): bool
    {
        $actor = auth()->user();
        $project = $this->getOwnerRecord();

        return $actor instanceof User && $project instanceof Project
            && ! $project->isArchived()
            && $actor->hasCapability($project->company, Capability::ManageOperations);
    }

    private function canChangeFuture(ProjectTransition $transition): bool
    {
        return $this->canMutateOwner()
            && $transition->annulledAt() === null
            && $transition->effectiveDate()->startOfDay()->greaterThan($this->today());
    }

    private function today(): CarbonImmutable
    {
        $project = $this->getOwnerRecord();
        abort_unless($project instanceof Project, 404);

        return CarbonImmutable::now($project->company->timezone)->startOfDay();
    }
}
