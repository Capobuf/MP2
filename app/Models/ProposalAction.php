<?php

namespace App\Models;

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
 * @property array<string, mixed> $payload
 */
#[Fillable(['company_id', 'proposal_id', 'proposal_item_id', 'sequence', 'action_type', 'payload_version', 'payload', 'reason', 'created_by_id', 'operation_id'])]
class ProposalAction extends Model
{
    /** @use HasFactory<ProposalActionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Proposal actions are append-only.'));
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

    protected function casts(): array
    {
        return ['action_type' => ProposalActionType::class, 'payload' => 'array', 'payload_version' => 'integer', 'sequence' => 'integer'];
    }
}
