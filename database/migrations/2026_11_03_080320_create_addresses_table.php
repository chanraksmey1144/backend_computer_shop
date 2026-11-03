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
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('recipient_name', 150);
            $table->string('phone', 30);

            $table->string('province', 120);
            $table->string('district', 120)->nullable();
            $table->string('commune', 120)->nullable();
            $table->string('village', 120)->nullable();

            $table->string('address_line', 500);

            $table->string('delivery_note', 500)->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index('user_id', 'idx_addresses_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
