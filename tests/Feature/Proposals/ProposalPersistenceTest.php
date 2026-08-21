<?php

use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows only one draft proposal per company exercise', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $user = User::factory()->create();

    Proposal::factory()->for($company)->for($exercise)->for($user, 'creator')->create();

    expect(fn () => Proposal::factory()->for($company)->for($exercise)->for($user, 'creator')->create())
        ->toThrow(QueryException::class);
});

it('rejects physical proposal deletion', function (): void {
    $proposal = Proposal::factory()->create();

    expect(fn () => $proposal->delete())->toThrow(LogicException::class);
});
