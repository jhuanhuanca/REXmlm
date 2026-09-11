<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('source', 20)->default('personal')->after('catalog_product_id');
            $table->boolean('is_published')->default(true)->after('is_active');
            $table->string('fulfillment', 20)->default('stock')->after('is_published');
            $table->date('expires_at')->nullable()->after('fulfillment');
            $table->text('technical_sheet')->nullable()->after('description');
            $table->string('dropship_url', 2048)->nullable()->after('image');
            $table->string('dropship_sku', 120)->nullable()->after('dropship_url');

            $table->index(['store_id', 'source']);
            $table->index(['store_id', 'is_published', 'is_active']);
        });

        DB::table('products')->whereNotNull('catalog_product_id')->update([
            'source' => 'company',
            'is_published' => true,
        ]);
        DB::table('products')->whereNull('catalog_product_id')->update([
            'source' => 'personal',
            'is_published' => true,
        ]);
        DB::table('products')->where('stock', 0)->update([
            'fulfillment' => 'dropship',
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'source']);
            $table->dropIndex(['store_id', 'is_published', 'is_active']);
            $table->dropColumn([
                'source',
                'is_published',
                'fulfillment',
                'expires_at',
                'technical_sheet',
                'dropship_url',
                'dropship_sku',
            ]);
        });
    }
};
