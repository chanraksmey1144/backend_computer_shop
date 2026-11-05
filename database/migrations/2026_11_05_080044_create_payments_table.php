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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->enum('provider', [
                'cash_on_delivery',
                'aba_payway',
                'bakong',
                'bank_transfer',
                'other'
            ]);

            $table->string('provider_transaction_id', 191)
                ->nullable();

            $table->string('payment_method', 80)
                ->nullable();

            $table->decimal('amount', 12, 2);

            $table->char('currency', 3)->default('USD');

            $table->enum('status', [
                'pending',
                'processing',
                'paid',
                'failed',
                'cancelled',
                'refunded'
            ])->default('pending');

            $table->string('checkout_url', 1000)->nullable();

            $table->json('provider_response')->nullable();

            $table->dateTime('paid_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['provider', 'provider_transaction_id'],
                'uq_payment_provider_transaction'
            );

            $table->index(
                ['order_id', 'status'],
                'idx_payments_order_status'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
