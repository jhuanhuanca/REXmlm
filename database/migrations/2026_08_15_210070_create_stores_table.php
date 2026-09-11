<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('theme')->default('default');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
