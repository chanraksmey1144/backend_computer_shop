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
        'payment_mode',
        'amount',
        'currency',
        'status',
        'checkout_url',
        'qr_string',
        'qr_image',
        'provider_response',
        'expires_at',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_response' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected $hidden = [
        'qr_image',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function generateAbaTransactionId(): string
    {
        return 'ABA'.now()->format('ymdHis').strtoupper(bin2hex(random_bytes(2)));
    }
}
