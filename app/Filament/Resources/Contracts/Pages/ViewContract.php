<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Actions\Operations\SetContractArchived;
use App\Domain\Contracts\ContractState;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Schemas\ContractInfolist;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Contract;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    public function getHeader(): ?View
    {
        $contract = $this->contract();
        $today = now($contract->company->timezone)->toImmutable()->startOfDay();

        return view('filament.resources.contracts.components.object-header', [
            'contract' => $contract,
            'currentState' => $contract->stateAtDate($today->toDateString()),
            'today' => $today,
            'contractsUrl' => ContractResource::getUrl('index', tenant: $contract->company),
        ]);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return ['class' => 'mp2-object-page mp2-contract-object-page'];
    }

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
            Action::make('timeline')->label('Timeline del Contratto')->icon('heroicon-m-chart-bar')->color('gray')->outlined()->url(fn (Contract $record): string => CompanyAudit::getUrl([
                'tenant' => $record->company,
                'contract' => $record->id,
            ])),
            EditAction::make()->label('Modifica')->icon('heroicon-m-pencil-square')->color('gray')->outlined()
                ->visible(fn (): bool => ! $this->contract()->isArchived()),
            Action::make('createContractActual')->label('Nuova Spesa')->icon('heroicon-m-plus')
                ->extraAttributes(['class' => 'mp2-contract-primary-action'])
                ->url(fn (): string => ExpenseResource::getUrl('create', ['contract' => $this->contract()->getKey()]))
                ->visible(fn (): bool => $this->canCreateActual()),
            ActionGroup::make([
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
            ])->label('Altre Azioni')->icon('heroicon-m-ellipsis-horizontal')->color('gray')->button()->outlined(),
        ];
    }

    public function allocationDetailAction(): Action
    {
        return Action::make('allocationDetail')
            ->label(fn (array $arguments): string => isset($arguments['count'])
                ? 'Vedi Tutti i '.((int) $arguments['count']).' Cicli'
                : 'Dettaglio Allocato '.((int) ($arguments['year'] ?? 0)))
            ->icon('heroicon-m-list-bullet')
            ->color('gray')
            ->link()
            ->modalHeading(fn (array $arguments): string => 'Dettaglio Allocato '.((int) ($arguments['year'] ?? 0)))
            ->modalDescription('Composizione completa dei cicli prodotti dalle condizioni economiche del Contratto.')
            ->modalContent(fn (array $arguments): View => view(
                'filament.resources.contracts.components.allocation-detail',
                ['detail' => $this->allocationDetail($arguments)],
            ))
            ->modalWidth(Width::Large)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Chiudi');
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

    private function canCreateActual(): bool
    {
        $contract = $this->contract();
        $actor = auth()->user();

        return $actor instanceof User && ! $contract->isArchived()
            && $actor->can('update', $contract);
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function allocationDetail(array $arguments): array
    {
        $year = filter_var($arguments['year'] ?? null, FILTER_VALIDATE_INT);
        abort_unless(is_int($year), 404);

        return ContractInfolist::allocationDetail($this->contract(), $year);
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
        Notification::make()->success()->title($archived ? 'Contratto Archiviato' : 'Contratto Ripristinato')->send();
    }
}
