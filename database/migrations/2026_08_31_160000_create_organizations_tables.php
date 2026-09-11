<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_company_id')->nullable()->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('default_timezone')->default('America/La_Paz');
            $table->char('default_currency', 3)->default('USD');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('catalog_rank_name')->constrained('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::dropIfExists('organization_users');
        Schema::dropIfExists('organizations');
    }
};
