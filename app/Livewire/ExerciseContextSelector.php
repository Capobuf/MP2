<?php

namespace App\Livewire;

use App\Filament\Pages\CompanyAccess;
use App\Filament\Pages\CompanySettings;
use App\Models\Company;
use App\Models\User;
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

    #[Locked]
    public string $returnUrl = '';

    public function mount(): void
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return;
        }

        $this->companyId = $company->id;
        $this->exerciseId = app(ExerciseContext::class)->current($company)?->id;
        $this->returnUrl = request()->fullUrl();
    }

    public function updatedExerciseId(mixed $exerciseId): void
    {
        $this->selectExercise((int) $exerciseId);
    }

    public function selectExercise(int $exerciseId): void
    {
        $company = Filament::getTenant();
        abort_unless($company instanceof Company, 404);

        app(ExerciseContext::class)->select($company, $exerciseId);
        $this->exerciseId = $exerciseId;
        $this->redirect($this->returnUrl, navigate: true);
    }

    public function updatedCompanyId(mixed $companyId): void
    {
        $this->selectCompany((int) $companyId);
    }

    public function selectCompany(int $companyId): void
    {
        $user = auth()->user();
        $company = Company::query()->find($companyId);
        abort_unless($user instanceof User && $company instanceof Company && $user->canAccessTenant($company), 403);

        $url = Filament::getCurrentPanel()->getUrl($company);
        abort_unless(is_string($url), 404);

        $this->redirect($url, navigate: true);
    }

    public function render(): View
    {
        $company = Filament::getTenant();
        $user = auth()->user();
        $panel = Filament::getCurrentPanel();
        $currentCompany = $company instanceof Company ? $company : null;
        $currentUser = $user instanceof User ? $user : null;

        return view('livewire.exercise-context-selector', [
            'company' => $currentCompany,
            'companies' => $currentUser ? $currentUser->getTenants($panel) : collect(),
            'exercises' => $currentCompany
                ? $currentCompany->exercises()->orderByDesc('year')->get()
                : collect(),
            'companySettingsUrl' => $currentUser && $currentCompany
                && Gate::forUser($currentUser)->allows('manageSettings', $currentCompany)
                    ? CompanySettings::getUrl(['tenant' => $currentCompany])
                    : null,
            'companyAccessUrl' => $currentUser && $currentCompany
                && Gate::forUser($currentUser)->allows('managePermissions', $currentCompany)
                    ? CompanyAccess::getUrl(['tenant' => $currentCompany])
                    : null,
            'companyRegistrationUrl' => $currentUser
                && Gate::forUser($currentUser)->allows('create', Company::class)
                    ? $panel->getTenantRegistrationUrl()
                    : null,
        ]);
    }
}
