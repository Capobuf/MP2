<?php

namespace App\Models;

use App\Domain\Proposals\ProposalActionStatus;
use App\Domain\Proposals\ProposalActionType;
use Database\Factories\ProposalActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $company_id
 * @property int $proposal_id
 * @property ProposalActionType $action_type
 * @property ProposalActionStatus $status
 * @property array<string, mixed> $payload
 */
#[Fillable(['company_id', 'proposal_id', 'proposal_item_id', 'sequence', 'action_type', 'payload_version', 'payload', 'reason', 'status', 'created_by_id', 'withdrawn_by_id', 'withdrawn_at', 'withdraw_operation_id', 'withdraw_reason', 'operation_id'])]
class ProposalAction extends Model
{
    /** @use HasFactory<ProposalActionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $action): void {
            if (($action->status ?? ProposalActionStatus::Active) !== ProposalActionStatus::Active) {
                throw new \LogicException('Proposal actions must be created active.');
            }
        });
        static::updating(function (self $action): void {
            $allowed = ['status', 'withdrawn_by_id', 'withdrawn_at', 'withdraw_operation_id', 'withdraw_reason', 'updated_at'];
            $changed = array_keys($action->getDirty());
            $from = $action->getRawOriginal('status') ?? ProposalActionStatus::Active->value;
            $to = $action->status->value;

            if ($from !== ProposalActionStatus::Active->value
                || $to !== ProposalActionStatus::Withdrawn->value
                || array_diff($changed, $allowed) !== []
                || ! $action->withdrawn_by_id
                || ! $action->withdrawn_at
                || ! $action->withdraw_operation_id) {
                throw new \LogicException('Proposal actions are append-only except for one-way withdrawal.');
            }
        });
        static::deleting(fn (): never => throw new \LogicException('Proposal actions cannot be deleted.'));
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

    /** @return BelongsTo<ProposalItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ProposalItem::class, 'proposal_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by_id');
    }

    protected function casts(): array
    {
        return ['action_type' => ProposalActionType::class, 'status' => ProposalActionStatus::class, 'payload' => 'array', 'payload_version' => 'integer', 'sequence' => 'integer', 'withdrawn_at' => 'datetime'];
    }
}
