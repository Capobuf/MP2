<?php

namespace App\Livewire;

use App\Filament\Pages\CompanyAccess;
use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\TenantCompany;
use App\Models\User;
use App\Support\BudgetContext;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ExerciseContextSelector extends Component
{
    public ?int $companyId = null;

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

        $this->companyId = $company->id;
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

    public function updatedCompanyId(mixed $companyId): void
    {
        $this->selectCompany((int) $companyId);
    }

    public function selectCompany(int $companyId): void
    {
        $user = auth()->user();
        $tenant = TenantCompany::query()->with('company')->find($companyId);
        abort_unless($user instanceof User && $tenant instanceof TenantCompany && $user->canAccessTenant($tenant), 403);

        $url = Filament::getCurrentPanel()->getUrl($tenant);
        abort_unless(is_string($url), 404);

        $this->redirect($url, navigate: true);
    }

    public function render(): View
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        $user = auth()->user();
        $panel = Filament::getCurrentPanel();
        $currentCompany = $company instanceof Company ? $company : null;
        $currentUser = $user instanceof User ? $user : null;
        $currentExercise = $currentCompany
            ? app(ExerciseContext::class)->current($currentCompany)
            : null;

        return view('livewire.exercise-context-selector', [
            'company' => $currentCompany,
            'companies' => $currentUser ? $currentUser->getTenants($panel) : collect(),
            'exercises' => $currentCompany
                ? $currentCompany->exercises()->orderByDesc('year')->get()
                : collect(),
            'budgets' => $currentCompany && $currentExercise
                ? $currentExercise->budgets()->orderByDesc('version')->get()
                : collect(),
            'exerciseManagementUrl' => $currentUser && $currentCompany
                && ExerciseResource::canAccess()
                    ? ExerciseResource::getUrl('index', tenant: $tenant)
                    : null,
            'exerciseCreationUrl' => $currentUser && $currentCompany
                && ExerciseResource::canCreate()
                    ? ExerciseResource::getUrl('create', tenant: $tenant)
                    : null,
            'companySettingsUrl' => $currentUser && $currentCompany
                && Gate::forUser($currentUser)->allows('manageSettings', $currentCompany)
                    ? CompanySettings::getUrl(['tenant' => $tenant])
                    : null,
            'companyAccessUrl' => $currentUser && $currentCompany
                && Gate::forUser($currentUser)->allows('managePermissions', $currentCompany)
                    ? CompanyAccess::getUrl(['tenant' => $tenant])
                    : null,
            'companyRegistrationUrl' => $currentUser
                && Gate::forUser($currentUser)->allows('create', TenantCompany::class)
                    ? $panel->getTenantRegistrationUrl()
                    : null,
        ]);
    }
}
