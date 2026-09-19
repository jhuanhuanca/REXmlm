<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_company_memberships', function (Blueprint $table) {
            $table->string('billing_status')->nullable()->default('active')->after('currency');
            $table->string('paddle_subscription_id')->nullable()->after('billing_status');
        });
    }

    public function down(): void
    {
        Schema::table('user_company_memberships', function (Blueprint $table) {
            $table->dropColumn(['billing_status', 'paddle_subscription_id']);
        });
    }
};
