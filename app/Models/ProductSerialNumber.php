<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSerialNumber extends Model
{
    use HasFactory;
    protected $table = 'product_serial_numbers';

    protected $fillable = [
        'product_id',
        'variant_id',
        'warehouse_id',
        'serial_number',
        'status',
        'order_item_id',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'warehouse_id' => 'integer',
        'order_item_id' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(
            ProductVariant::class,
            'variant_id'
        );
    }
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
