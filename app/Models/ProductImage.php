<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    use HasFactory;

        protected $fillable = [
        'product_id',
        'image_url',
        'cloudinary_public_id',
        'alt_text',
        'is_primary',
        'sort_order',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    
}
