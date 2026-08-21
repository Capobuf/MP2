<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Exercise;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ExerciseContext
{
    /** @var array<int, Exercise|null> */
    private array $resolved = [];

    public function current(Company $company): ?Exercise
    {
        if (array_key_exists($company->id, $this->resolved)) {
            return $this->resolved[$company->id];
        }

        $exercise = $this->fromSession($company) ?? $this->defaultFor($company);

        if ($exercise instanceof Exercise) {
            session()->put($this->sessionKey($company), $exercise->id);
        }

        return $this->resolved[$company->id] = $exercise;
    }

    public function select(Company $company, int $exerciseId): Exercise
    {
        $exercise = Exercise::query()
            ->whereBelongsTo($company, 'company')
            ->find($exerciseId);

        if (! $exercise instanceof Exercise) {
            throw ValidationException::withMessages([
                'exercise_id' => 'L’Esercizio selezionato non appartiene all’Azienda corrente.',
            ]);
        }

        session()->put($this->sessionKey($company), $exercise->id);
        $this->resolved[$company->id] = $exercise;

        return $exercise;
    }

    private function fromSession(Company $company): ?Exercise
    {
        $exerciseId = session()->get($this->sessionKey($company));

        if (! is_numeric($exerciseId)) {
            return null;
        }

        return Exercise::query()
            ->whereBelongsTo($company, 'company')
            ->find((int) $exerciseId);
    }

    private function defaultFor(Company $company): ?Exercise
    {
        $exercises = Exercise::query()
            ->whereBelongsTo($company, 'company');
        $currentYear = CarbonImmutable::now($company->timezone)->year;

        return (clone $exercises)->where('year', $currentYear)->first()
            ?? (clone $exercises)->open()->orderByDesc('year')->first()
            ?? $exercises->orderByDesc('year')->first();
    }

    private function sessionKey(Company $company): string
    {
        return "mp2.exercise_context.{$company->id}";
    }
}
