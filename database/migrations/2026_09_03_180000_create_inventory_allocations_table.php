<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('partner_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('qty_assigned')->default(0);
            $table->unsignedInteger('qty_sold')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'product_id', 'partner_user_id'], 'inventory_allocations_unique_partner_product');
            $table->index(['store_id', 'partner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_allocations');
    }
};
