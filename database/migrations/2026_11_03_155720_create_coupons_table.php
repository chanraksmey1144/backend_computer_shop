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
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            $table->string('code', 80)->unique();

            $table->string('description', 255)->nullable();

            $table->enum('discount_type', [
                'fixed',
                'percentage'
            ]);

            $table->decimal('discount_value', 12, 2);

            $table->decimal('minimum_order_amount', 12, 2)
                ->default(0);

            $table->decimal('maximum_discount_amount', 12, 2)
                ->nullable();

            $table->unsignedInteger('usage_limit')->nullable();

            $table->unsignedInteger('usage_count')->default(0);

            $table->dateTime('starts_at')->nullable();

            $table->dateTime('expires_at')->nullable();

            $table->enum('status', [
                'active',
                'inactive'
            ])->default('active');

            $table->timestamps();

            $table->index([
                'status',
                'starts_at',
                'expires_at'
            ], 'idx_coupons_status_dates');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
