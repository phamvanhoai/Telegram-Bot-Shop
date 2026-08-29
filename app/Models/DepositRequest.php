<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'provider_payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(DepositMethod::class, 'deposit_method_id');
    }
}
