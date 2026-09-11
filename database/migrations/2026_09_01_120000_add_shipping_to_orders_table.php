<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'customer_phone')) {
                $table->string('customer_phone', 40)->nullable()->after('customer_email');
            }
            if (! Schema::hasColumn('orders', 'shipping_fee')) {
                $table->decimal('shipping_fee', 12, 2)->default(0)->after('total');
            }
            if (! Schema::hasColumn('orders', 'shipping_country')) {
                $table->string('shipping_country', 2)->nullable()->after('shipping_fee');
            }
            if (! Schema::hasColumn('orders', 'shipping_department')) {
                $table->string('shipping_department', 120)->nullable()->after('shipping_country');
            }
            if (! Schema::hasColumn('orders', 'shipping_area')) {
                $table->string('shipping_area', 120)->nullable()->after('shipping_department');
            }
            if (! Schema::hasColumn('orders', 'shipping_address')) {
                $table->string('shipping_address', 255)->nullable()->after('shipping_area');
            }
            if (! Schema::hasColumn('orders', 'shipping_zone')) {
                $table->string('shipping_zone', 160)->nullable()->after('shipping_address');
            }
            if (! Schema::hasColumn('orders', 'shipping_eta_days')) {
                $table->unsignedSmallInteger('shipping_eta_days')->nullable()->after('shipping_zone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = array_values(array_filter([
                'customer_phone',
                'shipping_fee',
                'shipping_country',
                'shipping_department',
                'shipping_area',
                'shipping_address',
                'shipping_zone',
                'shipping_eta_days',
            ], fn (string $column) => Schema::hasColumn('orders', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
