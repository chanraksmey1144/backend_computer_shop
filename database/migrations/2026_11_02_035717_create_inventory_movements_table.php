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
            Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->enum('movement_type', [
                'stock_in',
                'stock_out',
                'sale',
                'return',
                'adjustment',
                'reservation',
                'release',
            ]);

            $table->integer('quantity_change');

            $table->string('reference_type', 80)->nullable();

            $table->unsignedBigInteger('reference_id')->nullable();

            $table->string('notes', 500)->nullable();

            $table->timestamp('created_at')
                ->nullable()
                ->useCurrent();

            $table->index(
                ['product_id', 'created_at'],
                'idx_movements_product_date'
            );

            $table->index(
                ['reference_type', 'reference_id'],
                'idx_movements_reference'
             
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
