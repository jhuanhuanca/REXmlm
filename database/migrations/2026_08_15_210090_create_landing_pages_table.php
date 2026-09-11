<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('template')->default('default');
            $table->json('content')->nullable();
            $table->string('custom_domain')->nullable()->unique();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
    }
};
