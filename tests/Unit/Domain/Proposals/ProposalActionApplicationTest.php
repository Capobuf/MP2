<?php

use App\Actions\Proposals\ApplyExpensePlan;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('maps a new expense item to one live identity with estimate lines only', function (): void {
    $item = ProposalItem::factory()->create(['source_type' => 'expense']);
    $item->updateQuietly(['result' => ['description' => 'Piano', 'exercise_id' => $item->proposal->exercise_id, 'estimate_lines' => [['proposal_line_id' => fake()->uuid(), 'line_id' => null, 'amount' => '3.00', 'note' => null, 'annulled' => false]]]]);
    $item->load('proposal');
    $expense = app(ApplyExpensePlan::class)->execute($item, [], User::factory()->create());
    expect($expense->allocation())->toBe('3.00')->and($expense->actual())->toBe('0.00');
});
