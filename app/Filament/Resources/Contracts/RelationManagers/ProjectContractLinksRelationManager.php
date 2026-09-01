<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Actions\Operations\CreateProjectContractLink;
use App\Actions\Operations\SetProjectContractLinkArchived;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProjectContractLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'projectLinks';

    protected static ?string $title = 'Progetti Collegati';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Contract && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('project.title')->label('Progetto')->url(fn (ProjectContractLink $record): string => ProjectResource::getUrl('view', ['record' => $record->project])),
            TextColumn::make('note')->label('Nota')->placeholder('—')->wrap(),
            TextColumn::make('state')->label('Stato')->state(fn (ProjectContractLink $record): string => $record->isArchived() ? 'Archiviato' : 'Attivo')->badge(),
        ])->headerActions([
            Action::make('linkProject')->label('Collega Progetto')->visible(fn (): bool => $this->canManage())
                ->form([
                    Select::make('project_id')->label('Progetto')->required()->searchable()->options(fn (): array => Project::query()
                        ->where('company_id', $this->contract()->company_id)->active()->orderBy('title')->pluck('title', 'id')->all()),
                    Textarea::make('note')->label('Nota'),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(CreateProjectContractLink::class)->execute(
                        $actor,
                        Project::query()->findOrFail((int) $data['project_id']),
                        $this->contract(),
                        $data['note'] ?? null,
                        (string) $data['operation_id'],
                    );
                }),
        ])->recordActions([$this->archiveAction(), $this->restoreAction()]);
    }

    private function archiveAction(): Action
    {
        return Action::make('archive')->label('Archivia Collegamento')->requiresConfirmation()
            ->visible(fn (ProjectContractLink $record): bool => ! $record->isArchived() && $this->canManage())
            ->action(fn (ProjectContractLink $record) => $this->setArchived($record, true));
    }

    private function restoreAction(): Action
    {
        return Action::make('restore')->label('Ripristina Collegamento')->requiresConfirmation()
            ->visible(fn (ProjectContractLink $record): bool => $record->isArchived() && $this->canManage())
            ->action(fn (ProjectContractLink $record) => $this->setArchived($record, false));
    }

    private function setArchived(ProjectContractLink $link, bool $archived): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        app(SetProjectContractLinkArchived::class)->execute($actor, $link, $archived, (string) Str::uuid(), $link->revision);
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
