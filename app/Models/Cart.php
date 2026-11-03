<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
    ];

    // Cart belongs to one user
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Cart has many cart items
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
