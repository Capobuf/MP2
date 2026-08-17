<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\Operations\UpdateExpense;
use App\Domain\Expenses\ExpenseImpactPlan;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('timeline')
                ->label('Timeline della Spesa')
                ->url(fn (Expense $record): string => CompanyAudit::getUrl([
                    'tenant' => $record->company,
                    'expense' => $record->id,
                ])),
            EditAction::make()->label('Modifica descrizione e note'),
            Action::make('moveOrReclassify')
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
                    $record->refresh();
                }),
            ExpenseResource::reverseAction(),
            ExpenseResource::restoreAction(),
        ];
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
            Select::make('supplier_id')->label('Nuovo Fornitore')->options($this->supplierOptions($expense))
                ->default($expense->supplier_id)->placeholder('Nessuno')->live()->afterStateUpdated($invalidate),
            Select::make('direct_cost_center_id')->label('Nuovo Centro di Costo')->options($this->costCenterOptions($expense))
                ->default($expense->direct_cost_center_id)->placeholder('Nessuno')->live()->afterStateUpdated($invalidate),
            Textarea::make('reason')->label('Motivo')->live()->afterStateUpdated($invalidate),
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
                            'reason' => $get('reason'),
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
        $rows = [];
        foreach ($plan->exerciseImpacts as $impact) {
            $rows[] = "Esercizio {$impact['year']}: Allocato {$impact['allocation_before']} → {$impact['allocation_after']} ({$impact['allocation_delta']}); Effettivo {$impact['actual_before']} → {$impact['actual_after']} ({$impact['actual_delta']})";
        }

        return implode(' · ', $rows);
    }
}
