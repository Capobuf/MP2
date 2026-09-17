<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Actions\Proposals\InitializeProposal;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\TenantCompany;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ListProposals extends ListRecords
{
    protected static string $resource = ProposalResource::class;

    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        $actor = auth()->user();

        return [
            Action::make('initializeProposal')
                ->label('Nuova proposta')
                ->modalHeading('Nuova Proposta')
                ->modalDescription('La Proposta parte dalla realtà corrente, con gli Effettivi in sola lettura. Se esiste un Budget approvato, viene creata una Revisione e l’ultimo Budget resta il riferimento di confronto.')
                ->modalSubmitActionLabel('Crea proposta')
                ->visible($actor instanceof User && $company instanceof Company && $actor->can('create', [Proposal::class, $company]))
                ->schema([
                    Select::make('exercise_id')
                        ->label('Esercizio')
                        ->options(fn (): array => Exercise::query()
                            ->where('company_id', $company?->id)
                            ->open()
                            ->whereDoesntHave('proposals', fn (Builder $query) => $query->where('status', 'draft'))
                            ->orderByDesc('year')
                            ->pluck('year', 'id')
                            ->all())
                        ->helperText('Sono disponibili gli Esercizi Aperti dell’Azienda senza una Proposta in Bozza.')
                        ->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (array $data) use ($actor, $company): void {
                    abort_unless($actor instanceof User && $company instanceof Company, 403);
                    $exercise = Exercise::query()->whereBelongsTo($company)->findOrFail($data['exercise_id']);
                    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) $data['operation_id']);

                    $this->redirect(ProposalResource::getUrl('view', ['record' => $proposal], tenant: $company->tenantCompany));
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Decisioni di Piano Isolate dalla Realtà Effettiva fino all’Approvazione.';
    }
}
