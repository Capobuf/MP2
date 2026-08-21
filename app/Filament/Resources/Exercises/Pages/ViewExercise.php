<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\Capability;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;

class ViewExercise extends ViewRecord
{
    protected static string $resource = ExerciseResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Exercise $exercise */
        $exercise = $this->record;

        return [
            Action::make('viewProposal')->label('Apri Proposta')->url(fn (): string => ProposalResource::getUrl('view', ['record' => Proposal::query()->where('exercise_id', $this->exerciseRecord()->id)->latest('id')->firstOrFail()]))->visible(fn (): bool => Proposal::query()->where('exercise_id', $this->exerciseRecord()->id)->exists()),
            Action::make('viewBudget')->label('Apri Budget')->url(fn (): string => BudgetResource::getUrl('view', ['record' => BudgetSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->latest('version')->firstOrFail()]))->visible(fn (): bool => BudgetSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists()),
            Action::make('initializeProposal')
                ->label('Inizializza proposta')
                ->requiresConfirmation()
                ->modalHeading('Inizializza Proposta di Budget')
                ->modalDescription('La Proposta resta isolata: gli Effettivi sono mostrati in sola lettura e non vengono modificati.')
                ->modalSubmitActionLabel('Inizializza proposta')
                ->visible(fn (): bool => $this->canManageProposals())
                ->disabled(fn (): bool => $this->proposalDisabledReason() !== null)
                ->tooltip(fn (): ?string => $this->proposalDisabledReason())
                ->form([Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid())])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $company = Filament::getTenant();
                    abort_unless($actor instanceof User && $company instanceof Company, 403);
                    $proposal = app(InitializeProposal::class)->execute($actor, $company, $this->exerciseRecord(), (string) $data['operation_id']);
                    $this->redirect(ProposalResource::getUrl('view', ['record' => $proposal], tenant: $company));
                }),
            Action::make('createExpense')
                ->label('Nuova spesa')
                ->url(ExpenseResource::getUrl('create', ['exercise' => $exercise->id])),
        ];
    }

    private function exerciseRecord(): Exercise
    {
        if (! $this->record instanceof Exercise) {
            throw new \UnexpectedValueException('Invalid Exercise record.');
        }

        return $this->record;
    }

    private function canManageProposals(): bool
    {
        $actor = auth()->user();
        $company = Filament::getTenant();

        return $actor instanceof User && $company instanceof Company && $actor->hasCapability($company, Capability::ManageProposals);
    }

    private function proposalDisabledReason(): ?string
    {
        $exercise = $this->exerciseRecord();
        if (! $exercise->isOpen()) {
            return 'L’Esercizio non è Aperto.';
        }
        if (BudgetSnapshot::query()->where('exercise_id', $exercise->id)->exists()) {
            return 'L’Esercizio possiede già un Budget.';
        }
        if (Proposal::query()->where('exercise_id', $exercise->id)->where('status', 'draft')->exists()) {
            return 'Esiste già una Proposta attiva.';
        }

        return null;
    }
}
