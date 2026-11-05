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
        Schema::create('orders', function (Blueprint $table) {
         $table->id();

            $table->string('order_number', 40)->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('address_id')
                ->nullable()
                ->constrained('addresses')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('coupon_id')
                ->nullable()
                ->constrained('coupons')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->enum('status', [
                'pending',
                'confirmed',
                'processing',
                'shipped',
                'delivered',
                'cancelled',
                'refunded'
            ])->default('pending');

            $table->enum('payment_status', [
                'unpaid',
                'pending',
                'paid',
                'failed',
                'partially_refunded',
                'refunded'
            ])->default('unpaid');

            $table->char('currency', 3)->default('USD');

            $table->decimal('subtotal', 12, 2);

            $table->decimal('discount_amount', 12, 2)
                ->default(0);

            $table->decimal('delivery_fee', 12, 2)
                ->default(0);

            $table->decimal('tax_amount', 12, 2)
                ->default(0);

            $table->decimal('total_amount', 12, 2);

            $table->string('recipient_name', 150);

            $table->string('recipient_phone', 30);

            $table->text('shipping_address');

            $table->string('customer_note', 1000)->nullable();

            $table->dateTime('placed_at')->nullable();

            $table->dateTime('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(
                ['user_id', 'created_at'],
                'idx_orders_user_date'
            );

            $table->index(
                ['status', 'created_at'],
                'idx_orders_status_date'
            );

            $table->index(
                'payment_status',
                'idx_orders_payment_status'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
