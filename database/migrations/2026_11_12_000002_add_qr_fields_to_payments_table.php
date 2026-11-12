<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_mode', 20)
                ->nullable()
                ->after('payment_method');

            $table->text('qr_string')
                ->nullable()
                ->after('checkout_url');

            $table->longText('qr_image')
                ->nullable()
                ->after('qr_string');

            $table->dateTime('expires_at')
                ->nullable()
                ->after('paid_at');

            $table->index(['status', 'expires_at'], 'idx_payments_status_expires');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `payments` MODIFY COLUMN `status` "
                ."ENUM('pending','processing','paid','failed','cancelled','expired','refunded') "
                ."NOT NULL DEFAULT 'pending'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('payments')
                ->where('status', 'expired')
                ->update(['status' => 'failed']);

            DB::statement(
                "ALTER TABLE `payments` MODIFY COLUMN `status` "
                ."ENUM('pending','processing','paid','failed','cancelled','refunded') "
                ."NOT NULL DEFAULT 'pending'"
            );
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_status_expires');

            $table->dropColumn([
                'payment_mode',
                'qr_string',
                'qr_image',
                'expires_at',
            ]);
        });
    }
};
