<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

        protected $fillable = [
        'product_id',
        'sku',
        'name',
        'attributes',
        'price',
        'cost_price',
        'status',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'attributes' => 'array',
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }   
}
