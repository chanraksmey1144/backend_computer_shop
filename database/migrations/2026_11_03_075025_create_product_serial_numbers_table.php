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
        Schema::create('product_serial_numbers', function (Blueprint $table) {
            $table->id();
                        $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('warehouse_id')
                ->nullable()
                ->constrained('warehouses')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('serial_number', 150)->unique();

            $table->enum('status', [
                'in_stock',
                'reserved',
                'sold',
                'returned',
                'repair',
                'written_off',
            ])->default('in_stock');

            $table->unsignedBigInteger('order_item_id')
                ->nullable();

            $table->timestamps();

            $table->index(
                ['product_id', 'status'],
                'idx_serial_product_status'
            );
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_serial_numbers');
    }
};
