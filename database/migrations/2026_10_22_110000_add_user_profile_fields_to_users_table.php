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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable()->unique();
            }

            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id', 191)->nullable()->unique();
            }

            if (! Schema::hasColumn('users', 'facebook_id')) {
                $table->string('facebook_id', 191)->nullable()->unique();
            }

            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['phone', 'google_id', 'facebook_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropUnique([$column]);
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('users', 'phone_verified_at')) {
                $table->dropColumn('phone_verified_at');
            }
        });
    }
};
