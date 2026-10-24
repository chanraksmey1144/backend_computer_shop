<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

     protected $fillable = [
        'category_id',
        'brand_id',
        'name',
        'slug',
        'sku',
        'short_description',
        'description',
        'product_type',
        'price',
        'compare_at_price',
        'cost_price',
        'currency',
        'warranty_months',
        'specifications',
        'track_inventory',
        'allow_backorder',
        'status',
    ];

      protected $casts = [
        'category_id' => 'integer',
        'brand_id' => 'integer',

        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'cost_price' => 'decimal:2',

        'warranty_months' => 'integer',
        'specifications' => 'array',

        'track_inventory' => 'boolean',
        'allow_backorder' => 'boolean',
    ];

      /**
     * A product belongs to a category.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * A product belongs to a brand.
     */
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
