<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8', 'balance_before' => 'decimal:8', 'balance_after' => 'decimal:8'];
    }
}
