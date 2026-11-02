<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasFactory;
    
    protected $table = 'inventory_movements';

    public $timestamps = false;

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'variant_id',
        'user_id',
        'movement_type',
        'quantity_change',
        'reference_type',
        'reference_id',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'user_id' => 'integer',
        'quantity_change' => 'integer',
        'reference_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
