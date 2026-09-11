<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('source_type')->default('subscription_invoice');
            $table->string('source_id');
            $table->decimal('amount', 12, 2);
            $table->decimal('percentage', 5, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->unique(['source_type', 'source_id', 'referrer_id'], 'commissions_source_unique');
            $table->index(['referrer_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
