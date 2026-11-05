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
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->foreign('order_item_id', 'fk_serial_order_item')
                ->references('id')
                ->on('order_items')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_serial_numbers', function (Blueprint $table) {
             $table->dropForeign('fk_serial_order_item');
        });
    }
};
