<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'operation_id',
    'storage_disk',
    'storage_path',
    'attempts',
    'last_attempted_at',
    'last_error',
])]
class PendingFileDeletion extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
        ];
    }
}
