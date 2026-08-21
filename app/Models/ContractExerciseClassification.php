<?php

namespace App\Models;

use Database\Factories\ContractExerciseClassificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'contract_id', 'exercise_id', 'cost_center_id'])]
/** @property CostCenter|null $costCenter */
class ContractExerciseClassification extends Model
{
    /** @use HasFactory<ContractExerciseClassificationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Contract classifications cannot be deleted.');
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
