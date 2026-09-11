<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_voucher_url', 2048)->nullable()->after('payment_method');
            $table->unsignedBigInteger('payment_voucher_document_id')->nullable()->after('payment_voucher_url');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_voucher_url', 'payment_voucher_document_id']);
        });
    }
};
