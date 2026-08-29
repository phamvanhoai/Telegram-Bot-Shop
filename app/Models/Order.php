<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:8', 'discount' => 'decimal:8', 'total' => 'decimal:8', 'completed_at' => 'datetime'];
    }
}
