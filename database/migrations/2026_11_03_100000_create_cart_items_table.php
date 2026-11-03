<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')
                ->constrained('carts')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->index('cart_id', 'idx_cart_items_cart');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
