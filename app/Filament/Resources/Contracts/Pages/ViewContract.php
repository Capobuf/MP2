<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Operations\SetContractArchived;
use App\Domain\Contracts\ContractState;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;

class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Panoramica';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('timeline')->label('Timeline del Contratto')->url(fn (Contract $record): string => CompanyAudit::getUrl([
                'tenant' => $record->company,
                'contract' => $record->id,
            ])),
            EditAction::make()->visible(fn (): bool => ! $this->contract()->isArchived()),
            Action::make('archive')->label('Archivia')->color('warning')->requiresConfirmation()
                ->visible(fn (): bool => $this->canArchive())
                ->form([
                    Hidden::make('contract_revision')->default(fn (): int => $this->contract()->revision),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])->action(fn (array $data) => $this->setArchived(true, $data)),
            Action::make('restore')->label('Ripristina')->color('success')->requiresConfirmation()
                ->visible(fn (): bool => $this->canRestore())
                ->form([
                    Hidden::make('contract_revision')->default(fn (): int => $this->contract()->revision),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])->action(fn (array $data) => $this->setArchived(false, $data)),
        ];
    }

    private function contract(): Contract
    {
        $record = $this->getRecord();
        if (! $record instanceof Contract) {
            throw new \UnexpectedValueException('Invalid Contract record.');
        }

        return $record;
    }

    private function canArchive(): bool
    {
        $contract = $this->contract();
        $actor = auth()->user();
        $today = now($contract->company->timezone)->toDateString();

        return $actor instanceof User && $actor->can('update', $contract) && ! $contract->isArchived()
            && in_array($contract->stateAtDate($today), [ContractState::Cessated, ContractState::Cancelled], true);
    }

    private function canRestore(): bool
    {
        $contract = $this->contract();

        return auth()->user()?->can('update', $contract) === true && $contract->isArchived();
    }

    /** @param array<string, mixed> $data */
    private function setArchived(bool $archived, array $data): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $this->record = app(SetContractArchived::class)->execute(
            $actor,
            $this->contract(),
            $archived,
            (string) $data['operation_id'],
            (int) $data['contract_revision'],
        );
        Notification::make()->success()->title($archived ? 'Contratto archiviato' : 'Contratto ripristinato')->send();
    }
}
