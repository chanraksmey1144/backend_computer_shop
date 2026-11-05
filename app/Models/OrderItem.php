<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'product_name',
        'sku',
        'quantity',
        'unit_price',
        'discount_amount',
        'line_total',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    // OrderItem belongs to Order
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // OrderItem belongs to Product
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // OrderItem belongs to ProductVariant
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    // OrderItem has many Serial Numbers
    public function serialNumbers(): HasMany
    {
        return $this->hasMany(
            ProductSerialNumber::class,
            'order_item_id'
        );
    }
}
