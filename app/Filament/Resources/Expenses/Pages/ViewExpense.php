<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\Operations\UpdateExpense;
use App\Domain\Contracts\ContractActualKind;
use App\Domain\Expenses\ExpenseImpactPlan;
use App\Domain\Projects\ProjectActualKind;
use App\Domain\Projects\ProjectOverspend;
use App\Domain\Projects\ProjectOverspendResult;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\ProjectOverspendNotifier;
use App\Livewire\ExpenseDetail;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    public function getHeader(): ?View
    {
        $expense = $this->expenseRecord();

        return view('filament.resources.expenses.components.object-header', [
            'expense' => $expense,
            'expensesUrl' => ExpenseResource::getUrl('index', tenant: $expense->company),
            'money' => [
                'allocation' => Number::currency((float) $expense->allocation(), in: 'EUR', locale: 'it'),
                'actual' => Number::currency((float) $expense->actual(), in: 'EUR', locale: 'it'),
                'variance' => Number::currency((float) $expense->operationalVariance(), in: 'EUR', locale: 'it'),
            ],
        ]);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return ['class' => 'mp2-object-page mp2-expense-object-page'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(ExpenseDetail::class, fn (): array => [
                'expenseId' => $this->getRecord()->getKey(),
                'compact' => false,
            ])->key(fn (): string => 'expense-full-'.$this->getRecord()->getKey()),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Modifica')->icon('heroicon-m-pencil-square')->color('primary')
                ->extraAttributes(['class' => 'mp2-object-primary-action']),
            ActionGroup::make([
                $this->moveOrReclassifyAction(),
                ExpenseResource::reverseAction(),
                ExpenseResource::restoreAction(),
            ])->label('Azioni')->icon('heroicon-m-chevron-down')->color('gray')->button()->outlined(),
        ];
    }

    private function moveOrReclassifyAction(): Action
    {
        return Action::make('moveOrReclassify')
            ->label('Sposta o riclassifica')
            ->visible(fn (Expense $record): bool => ExpenseResource::canEdit($record))
            ->modalHeading('Sposta o riclassifica la Spesa')
            ->modalSubmitActionLabel('Conferma modifica')
            ->form($this->impactForm())
            ->action(function (array $data, Expense $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $preview = app(UpdateExpense::class)->preview($actor, $record, $data);
                app(UpdateExpense::class)->confirm($actor, $record, $preview, (string) $data['operation_id']);
                ProjectOverspendNotifier::sendForOperation((string) $data['operation_id']);
                $record->refresh();
            });
    }

    private function expenseRecord(): Expense
    {
        $record = $this->getRecord();
        if (! $record instanceof Expense) {
            throw new \UnexpectedValueException('Invalid Expense record.');
        }

        return $record;
    }

    /** @return array<int, mixed> */
    private function impactForm(): array
    {
        /** @var Expense $expense */
        $expense = $this->record;
        $invalidate = fn (Set $set): mixed => $set('impact_confirmed', false);

        return [
            Select::make('exercise_id')->label('Nuovo Esercizio')
                ->options(Exercise::query()->where('company_id', $expense->company_id)->open()->orderByDesc('year')->pluck('year', 'id')->all())
                ->default($expense->exercise_id)->required()->live()->afterStateUpdated($invalidate),
            Select::make('project_id')->label('Nuovo Progetto')
                ->options(Project::query()->where('company_id', $expense->company_id)->active()->orderBy('title')->pluck('title', 'id')->all())
                ->default($expense->project_id)->placeholder('Nessuno')->live()
                ->afterStateUpdated(function (Set $set, mixed $state) use ($invalidate): void {
                    if (filled($state)) {
                        $set('contract_id', null);
                    }
                    $invalidate($set);
                }),
            Select::make('contract_id')->label('Nuovo Contratto')
                ->options(Contract::query()->where('company_id', $expense->company_id)->active()->orderBy('title')->pluck('title', 'id')->all())
                ->default($expense->contract_id)->placeholder('Nessuno')->live()
                ->afterStateUpdated(function (Set $set, mixed $state) use ($invalidate): void {
                    if (filled($state)) {
                        $set('project_id', null);
                    }
                    $invalidate($set);
                }),
            Select::make('supplier_id')->label('Nuovo Fornitore')->options($this->supplierOptions($expense))
                ->default($expense->supplier_id)->placeholder('Nessuno')->live()->afterStateUpdated($invalidate)
                ->visible(fn (Get $get): bool => blank($get('contract_id'))),
            Select::make('direct_cost_center_id')->label('Nuovo Centro di Costo')->options($this->costCenterOptions($expense))
                ->default($expense->direct_cost_center_id)->placeholder('Non classificata')->live()->afterStateUpdated($invalidate)
                ->visible(fn (Get $get): bool => blank($get('project_id')) && blank($get('contract_id'))),
            Textarea::make('reason')->label('Motivo')->live()->afterStateUpdated($invalidate),
            Select::make('actual_kind')->label('Dichiarazione Effettivo')
                ->options(fn (Get $get): array => filled($get('contract_id')) ? ContractActualKind::options() : ProjectActualKind::options())
                ->placeholder('Ordinario')->visible(fn (Get $get): bool => filled($get('project_id')) || filled($get('contract_id')))
                ->live()->afterStateUpdated($invalidate),
            Checkbox::make('open_project')->label('Conferma apertura atomica se il Progetto è Pianificato')
                ->visible(fn (Get $get): bool => filled($get('project_id')))->live()->afterStateUpdated($invalidate),
            Textarea::make('activity_note')->label('Nota attività tardiva, rimborso o correzione')
                ->visible(fn (Get $get): bool => filled($get('project_id')) || filled($get('contract_id')))->live()->afterStateUpdated($invalidate),
            Textarea::make('overspend_note')->label('Nota di sovraspesa')->visible(fn (Get $get): bool => filled($get('project_id')))
                ->live()->afterStateUpdated($invalidate),
            Checkbox::make('supplier_replacement_acknowledged')->label('Confermo la sostituzione del Fornitore con quello del Contratto')
                ->visible(fn (Get $get): bool => filled($get('contract_id')))->live()->afterStateUpdated($invalidate),
            Placeholder::make('impact_preview')->label('Anteprima impatto')
                ->content(function (Get $get) use ($expense): string {
                    $actor = auth()->user();
                    if (! $actor instanceof User || $get('exercise_id') === null) {
                        return 'Selezionare i nuovi riferimenti per calcolare l’anteprima.';
                    }
                    try {
                        $plan = app(UpdateExpense::class)->preview($actor, $expense, [
                            'exercise_id' => $get('exercise_id'),
                            'supplier_id' => $get('supplier_id'),
                            'direct_cost_center_id' => $get('direct_cost_center_id'),
                            'project_id' => $get('project_id'),
                            'contract_id' => $get('contract_id'),
                            'reason' => $get('reason'),
                            'actual_kind' => $get('actual_kind'),
                            'open_project' => $get('open_project'),
                            'activity_note' => $get('activity_note'),
                            'overspend_note' => $get('overspend_note'),
                            'supplier_replacement_acknowledged' => $get('supplier_replacement_acknowledged'),
                        ]);
                    } catch (ValidationException $exception) {
                        return collect($exception->errors())->flatten()->first()
                            ?? 'Non è possibile calcolare l’anteprima.';
                    }

                    return $this->formatImpact($plan);
                }),
            Checkbox::make('impact_confirmed')->label('Confermo l’anteprima corrente')->accepted()->required(),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    /** @return array<int, string> */
    private function supplierOptions(Expense $expense): array
    {
        $options = Supplier::query()->where('company_id', $expense->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all();
        if ($expense->supplier !== null && $expense->supplier->isArchived()) {
            $options[$expense->supplier->id] = $expense->supplier->legal_name.' · Archiviato';
        }

        return $options;
    }

    /** @return array<int, string> */
    private function costCenterOptions(Expense $expense): array
    {
        $options = CostCenter::query()->where('company_id', $expense->company_id)->active()->orderBy('name')->pluck('name', 'id')->all();
        if ($expense->directCostCenter !== null && $expense->directCostCenter->isArchived()) {
            $options[$expense->directCostCenter->id] = $expense->directCostCenter->name.' · Archiviato';
        }

        return $options;
    }

    private function formatImpact(ExpenseImpactPlan $plan): string
    {
        $rows = ["Identità preservate: {$plan->originKey}; Righe ".implode(', ', $plan->lineIds).'.'];
        $rows[] = 'Contenitore: '.$this->ownerLabel($plan->sourceProjectId, $plan->sourceContractId).' → '.$this->ownerLabel($plan->targetProjectId, $plan->targetContractId).'.';
        $rows[] = 'Centro di Costo: '.$this->costCenterLabel($plan->sourceCostCenterId).' → '.$this->costCenterLabel($plan->targetCostCenterId).'.';
        if ($plan->targetProjectId !== null) {
            $project = Project::query()->find($plan->targetProjectId);
            if ($project !== null) {
                $state = $project->stateAtDate(now($project->company->timezone)->toDateString());
                $rows[] = 'Ammissibilità destinazione: '.($state?->label() ?? 'Assente alla data')
                    .($plan->openProject ? ', con apertura atomica confermata.' : '.');
            }
        }
        foreach ($plan->exerciseImpacts as $impact) {
            $rows[] = "Esercizio {$impact['year']}: Allocato {$impact['allocation_before']} → {$impact['allocation_after']} ({$impact['allocation_delta']}); Effettivo {$impact['actual_before']} → {$impact['actual_after']} ({$impact['actual_delta']})";
        }
        if ($plan->sourceExerciseId === $plan->targetExerciseId) {
            $rows[] = 'Totale dell’Esercizio invariato: lo spostamento cambia solo il contenitore di primo livello.';
        }
        foreach ($plan->projectImpacts as $projectId => $impact) {
            $overspend = ProjectOverspend::detect((string) $impact['variance_before'], (string) $impact['variance_after']);
            $warning = match ($overspend) {
                ProjectOverspendResult::Created => '; Sovraspesa creata',
                ProjectOverspendResult::Increased => '; Sovraspesa aumentata',
                ProjectOverspendResult::None => '',
            };
            $rows[] = "Progetto {$projectId}: Allocato {$impact['allocation_before']} → {$impact['allocation_after']}; Effettivo {$impact['actual_before']} → {$impact['actual_after']}; Scostamento {$impact['variance_before']} → {$impact['variance_after']}{$warning}";
        }
        foreach ($plan->contractImpacts as $contractId => $impact) {
            $rows[] = "Contratto {$contractId}: Allocato {$impact['allocation_before']} → {$impact['allocation_after']}; Effettivo {$impact['actual_before']} → {$impact['actual_after']}.";
        }

        return implode(' · ', $rows);
    }

    private function costCenterLabel(?int $costCenterId): string
    {
        if ($costCenterId === null) {
            return 'Non classificato';
        }

        $costCenter = CostCenter::query()->find($costCenterId);

        return $costCenter === null
            ? 'Centro di Costo #'.$costCenterId
            : $costCenter->name.($costCenter->isArchived() ? ' · Archiviato' : '');
    }

    private function ownerLabel(?int $projectId, ?int $contractId): string
    {
        if ($projectId !== null) {
            return 'Progetto '.$projectId;
        }

        return $contractId === null ? 'Autonoma' : 'Contratto '.$contractId;
    }
}
