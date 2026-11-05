<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

        protected $fillable = [
        'order_id',
        'provider',
        'provider_transaction_id',
        'payment_method',
        'amount',
        'currency',
        'status',
        'checkout_url',
        'provider_response',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_response' => 'array',
        'paid_at' => 'datetime',
    ];

    // Payment belongs to Order
    public function order(): BelongsTo

    {
        return $this->belongsTo(Order::class);
    }
}
