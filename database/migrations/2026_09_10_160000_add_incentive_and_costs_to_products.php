<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('purchase_cost', 12, 2)->default(0)->after('price');
            $table->foreignId('incentive_product_id')->nullable()->after('purchase_cost')->constrained('products')->nullOnDelete();
            $table->unsignedSmallInteger('incentive_qty')->default(1)->after('incentive_product_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->default(0)->after('unit_price');
            $table->decimal('incentive_cost', 12, 2)->default(0)->after('unit_cost');
            $table->decimal('line_cost', 12, 2)->default(0)->after('line_total');
            $table->decimal('line_profit', 12, 2)->default(0)->after('line_cost');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'incentive_cost', 'line_cost', 'line_profit']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incentive_product_id');
            $table->dropColumn(['purchase_cost', 'incentive_qty']);
        });
    }
};
