<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('brand_id')
                ->nullable()
                ->constrained('brands')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('name', 200);
            $table->string('slug', 220)->unique();
            $table->string('sku', 100)->unique();
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();

            $table->enum('product_type', [
                'laptop',
                'desktop',
                'monitor',
                'component',
                'accessory',
                'other',
            ])->default('other');

            $table->decimal('price', 12, 2);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();

            $table->char('currency', 3)->default('USD');

            $table->unsignedSmallInteger('warranty_months')
                ->default(0);

            $table->json('specifications')->nullable();

            $table->boolean('track_inventory')->default(true);
            $table->boolean('allow_backorder')->default(false);

            $table->enum('status', [
                'draft',
                'active',
                'inactive',
            ])->default('draft');

            $table->timestamps();

            $table->index(
                ['category_id', 'status'],
                'idx_products_category_status'
            );

            $table->index(
                ['brand_id', 'status'],
                'idx_products_brand_status'
            );

            $table->index(
                ['product_type', 'status'],
                'idx_products_type_status'
            );

            $table->index('name', 'idx_products_name');

            $table->fullText(
                ['name', 'short_description', 'description'],
                'ft_products_search'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
