<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('intro_price', 12, 2)->default(1)->after('price');
            $table->string('paddle_intro_discount_id')->nullable()->after('paddle_price_id');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_active');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('next_billed_at')->nullable()->after('ends_at');
            $table->date('renewal_reminder_for')->nullable()->after('next_billed_at');
            $table->timestamp('past_due_notified_at')->nullable()->after('renewal_reminder_for');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['intro_price', 'paddle_intro_discount_id', 'sort_order']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['next_billed_at', 'renewal_reminder_for', 'past_due_notified_at']);
        });
    }
};
