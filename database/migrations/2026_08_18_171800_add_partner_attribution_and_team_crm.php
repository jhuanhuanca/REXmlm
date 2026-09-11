<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('partner_user_id')
                ->nullable()
                ->after('network_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['store_id', 'partner_user_id']);
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->string('crm_stage')->default('new')->after('status');
            $table->text('notes')->nullable()->after('crm_stage');
            $table->timestamp('follow_up_at')->nullable()->after('notes');
            $table->timestamp('last_contacted_at')->nullable()->after('follow_up_at');
        });

        Schema::create('team_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leader_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->string('type')->default('note');
            $table->text('body');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['leader_id', 'referred_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_activities');

        Schema::table('referrals', function (Blueprint $table) {
            $table->dropColumn(['crm_stage', 'notes', 'follow_up_at', 'last_contacted_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_user_id');
        });
    }
};
