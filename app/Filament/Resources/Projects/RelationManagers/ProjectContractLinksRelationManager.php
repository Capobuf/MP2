<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Actions\Operations\CreateProjectContractLink;
use App\Actions\Operations\SetProjectContractLinkArchived;
use App\Filament\Resources\Contracts\ContractResource;
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
    protected static string $relationship = 'contractLinks';

    protected static ?string $title = 'Contratti collegati';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Project && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('contract.title')->label('Contratto')->url(fn (ProjectContractLink $record): string => ContractResource::getUrl('view', ['record' => $record->contract])),
            TextColumn::make('note')->label('Nota')->placeholder('—')->wrap(),
            TextColumn::make('state')->label('Stato')->state(fn (ProjectContractLink $record): string => $record->isArchived() ? 'Archiviato' : 'Attivo')->badge(),
        ])->headerActions([
            Action::make('linkContract')->label('Collega Contratto')->visible(fn (): bool => $this->canManage())
                ->form([
                    Select::make('contract_id')->label('Contratto')->required()->searchable()->options(fn (): array => Contract::query()
                        ->where('company_id', $this->project()->company_id)->active()->orderBy('title')->pluck('title', 'id')->all()),
                    Textarea::make('note')->label('Nota'),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(CreateProjectContractLink::class)->execute(
                        $actor,
                        $this->project(),
                        Contract::query()->findOrFail((int) $data['contract_id']),
                        $data['note'] ?? null,
                        (string) $data['operation_id'],
                    );
                }),
        ])->recordActions([
            Action::make('archive')->label('Archivia collegamento')->requiresConfirmation()
                ->visible(fn (ProjectContractLink $record): bool => ! $record->isArchived() && $this->canManage())
                ->action(fn (ProjectContractLink $record) => $this->setArchived($record, true)),
            Action::make('restore')->label('Ripristina collegamento')->requiresConfirmation()
                ->visible(fn (ProjectContractLink $record): bool => $record->isArchived() && $this->canManage())
                ->action(fn (ProjectContractLink $record) => $this->setArchived($record, false)),
        ]);
    }

    private function setArchived(ProjectContractLink $link, bool $archived): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        app(SetProjectContractLinkArchived::class)->execute($actor, $link, $archived, (string) Str::uuid(), $link->revision);
    }

    private function project(): Project
    {
        $record = $this->getOwnerRecord();
        abort_unless($record instanceof Project, 404);

        return $record;
    }

    private function canManage(): bool
    {
        return ! $this->project()->isArchived() && auth()->user()?->can('update', $this->project()) === true;
    }
}
