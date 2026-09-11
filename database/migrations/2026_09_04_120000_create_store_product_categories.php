<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'slug']);
            $table->unique(['store_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('store_category_id')
                ->nullable()
                ->after('store_id')
                ->constrained('store_product_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_category_id');
        });
        Schema::dropIfExists('store_product_categories');
    }
};
