<?php

namespace App\Support;

use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use Illuminate\Validation\ValidationException;

final class BudgetContext
{
    /** @var array<string, BudgetSnapshot|null> */
    private array $resolved = [];

    public function current(Company $company, Exercise $exercise): ?BudgetSnapshot
    {
        $key = $this->cacheKey($company, $exercise);

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $budgetId = session()->get($this->sessionKey($company, $exercise));
        if (! is_numeric($budgetId)) {
            return $this->resolved[$key] = null;
        }

        $budget = BudgetSnapshot::query()
            ->whereBelongsTo($company, 'company')
            ->whereBelongsTo($exercise, 'exercise')
            ->find((int) $budgetId);

        if (! $budget instanceof BudgetSnapshot) {
            session()->forget($this->sessionKey($company, $exercise));
        }

        return $this->resolved[$key] = $budget;
    }

    public function select(Company $company, Exercise $exercise, int $budgetId): BudgetSnapshot
    {
        $budget = BudgetSnapshot::query()
            ->whereBelongsTo($company, 'company')
            ->whereBelongsTo($exercise, 'exercise')
            ->find($budgetId);

        if (! $budget instanceof BudgetSnapshot) {
            throw ValidationException::withMessages([
                'budget_id' => 'Il Budget selezionato non appartiene all’Azienda e all’Esercizio correnti.',
            ]);
        }

        session()->put($this->sessionKey($company, $exercise), $budget->id);
        $this->resolved[$this->cacheKey($company, $exercise)] = $budget;

        return $budget;
    }

    public function clear(Company $company, Exercise $exercise): void
    {
        session()->forget($this->sessionKey($company, $exercise));
        $this->resolved[$this->cacheKey($company, $exercise)] = null;
    }

    private function sessionKey(Company $company, Exercise $exercise): string
    {
        return "mp2.budget_context.{$company->id}.{$exercise->id}";
    }

    private function cacheKey(Company $company, Exercise $exercise): string
    {
        return "{$company->id}:{$exercise->id}";
    }
}
