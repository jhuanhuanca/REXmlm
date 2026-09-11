<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('user_id')->constrained('plans')->nullOnDelete();
            $table->foreignId('network_id')->nullable()->after('plan_id')->constrained('networks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropConstrainedForeignId('network_id');
        });
    }
};
