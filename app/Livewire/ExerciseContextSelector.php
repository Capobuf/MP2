<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Exercise;
use App\Models\TenantCompany;
use App\Support\BudgetContext;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ExerciseContextSelector extends Component
{
    public ?int $exerciseId = null;

    public ?int $budgetId = null;

    #[Locked]
    public string $returnUrl = '';

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        if (! $company instanceof Company) {
            return;
        }

        $exercise = app(ExerciseContext::class)->current($company);
        $this->exerciseId = $exercise?->id;
        $this->budgetId = $exercise instanceof Exercise
            ? app(BudgetContext::class)->current($company, $exercise)?->id
            : null;
        $this->returnUrl = request()->fullUrl();
    }

    public function updatedExerciseId(mixed $exerciseId): void
    {
        $this->selectExercise((int) $exerciseId);
    }

    public function selectExercise(int $exerciseId): void
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        abort_unless($company instanceof Company, 404);

        app(ExerciseContext::class)->select($company, $exerciseId);
        $this->exerciseId = $exerciseId;
        $this->redirect($this->returnUrl, navigate: true);
    }

    public function selectBudget(int $budgetId): void
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        abort_unless($company instanceof Company, 404);
        $exercise = app(ExerciseContext::class)->current($company);
        abort_unless($exercise instanceof Exercise, 404);

        app(BudgetContext::class)->select($company, $exercise, $budgetId);
        $this->budgetId = $budgetId;
        $this->redirect($this->returnUrl, navigate: true);
    }

    public function clearBudget(): void
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        abort_unless($company instanceof Company, 404);
        $exercise = app(ExerciseContext::class)->current($company);
        abort_unless($exercise instanceof Exercise, 404);

        app(BudgetContext::class)->clear($company, $exercise);
        $this->budgetId = null;
        $this->redirect($this->returnUrl, navigate: true);
    }

    public function render(): View
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        $currentCompany = $company instanceof Company ? $company : null;
        $currentExercise = $currentCompany
            ? app(ExerciseContext::class)->current($currentCompany)
            : null;

        return view('livewire.exercise-context-selector', [
            'company' => $currentCompany,
            'exercises' => $currentCompany
                ? $currentCompany->exercises()->orderByDesc('year')->get()
                : collect(),
            'budgets' => $currentCompany && $currentExercise
                ? $currentExercise->budgets()->orderByDesc('version')->get()
                : collect(),
        ]);
    }
}
