<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_company_id')->nullable()->after('store_id');
            $table->unsignedBigInteger('catalog_product_id')->nullable()->after('catalog_company_id');

            $table->index('catalog_company_id');
            $table->unique(['store_id', 'catalog_product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'catalog_product_id']);
            $table->dropIndex(['catalog_company_id']);
            $table->dropColumn(['catalog_company_id', 'catalog_product_id']);
        });
    }
};
