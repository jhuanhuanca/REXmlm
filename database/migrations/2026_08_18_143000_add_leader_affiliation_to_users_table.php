<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('email');
            $table->unsignedBigInteger('catalog_company_id')->nullable()->after('current_network_id');
            $table->string('catalog_company_name')->nullable()->after('catalog_company_id');
            $table->unsignedBigInteger('catalog_rank_id')->nullable()->after('catalog_company_name');
            $table->string('catalog_rank_name')->nullable()->after('catalog_rank_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'country',
                'catalog_company_id',
                'catalog_company_name',
                'catalog_rank_id',
                'catalog_rank_name',
            ]);
        });
    }
};
