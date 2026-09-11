<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->after('password');
            $table->text('two_factor_secret')->nullable()->after('status');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('two_factor_confirmed_at');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->foreignId('sponsor_user_id')->nullable()->after('locked_until')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sponsor_user_id');
            $table->dropColumn([
                'status',
                'two_factor_secret',
                'two_factor_confirmed_at',
                'failed_login_attempts',
                'locked_until',
            ]);
            $table->dropSoftDeletes();
        });
    }
};
