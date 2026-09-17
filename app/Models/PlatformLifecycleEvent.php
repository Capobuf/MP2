<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['operation_id', 'operation', 'actor_id', 'actor_name', 'tenant_id', 'occurred_at', 'outcome', 'file_count'])]
class PlatformLifecycleEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Platform lifecycle events cannot be updated.');
        });
        static::deleting(function (): never {
            throw new \LogicException('Platform lifecycle events cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'tenant_id' => 'integer', 'occurred_at' => 'immutable_datetime', 'file_count' => 'integer'];
    }
}
