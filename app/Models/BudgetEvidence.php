<?php

namespace App\Models;

use Database\Factories\BudgetEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $company_id */
#[Fillable(['company_id', 'budget_snapshot_id', 'external_subject', 'external_venue', 'reason', 'attachment_id', 'storage_disk', 'storage_path', 'original_name', 'media_type', 'size_bytes', 'sha256'])]
class BudgetEvidence extends Model
{
    /** @use HasFactory<BudgetEvidenceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Budget evidence is immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Budget evidence cannot be deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(BudgetSnapshot::class, 'budget_snapshot_id');
    }

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
