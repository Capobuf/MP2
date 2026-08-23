<?php

namespace App\Models;

use App\Domain\Proposals\ProposalReadinessState;
use App\Domain\Proposals\ProposalSourceType;
use Database\Factories\ProposalItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $company_id
 * @property int $proposal_id
 * @property ProposalSourceType $source_type
 * @property array<string, mixed> $baseline
 * @property array<string, mixed> $result
 * @property ProposalReadinessState $readiness_state
 * @property array<int, array<string, mixed>> $readiness_reasons
 */
#[Fillable(['proposal_item_id', 'company_id', 'proposal_id', 'source_type', 'expense_id', 'project_id', 'contract_id', 'copied_from_origin_key', 'baseline_revision', 'baseline_fingerprint', 'baseline', 'result', 'readiness_state', 'readiness_reasons', 'read_only_source', 'last_aligned_at', 'last_aligned_by_id'])]
class ProposalItem extends Model
{
    /** @use HasFactory<ProposalItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new \LogicException('Proposal items cannot be deleted.'));
        static::updating(function (self $item): void {
            if ($item->proposal()->where('status', 'draft')->doesntExist()) {
                throw new \LogicException('Terminal proposal items are immutable.');
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return HasMany<ProposalAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ProposalAction::class)->where('status', 'active')->orderBy('sequence');
    }

    /** @return HasMany<ProposalAction, $this> */
    public function actionHistory(): HasMany
    {
        return $this->hasMany(ProposalAction::class)->orderBy('sequence');
    }

    protected function casts(): array
    {
        return ['source_type' => ProposalSourceType::class, 'baseline' => 'array', 'result' => 'array', 'readiness_state' => ProposalReadinessState::class, 'readiness_reasons' => 'array', 'read_only_source' => 'boolean', 'last_aligned_at' => 'datetime', 'baseline_revision' => 'integer'];
    }
}
