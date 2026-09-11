<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope')->default('organization');
            $table->string('driver');
            $table->string('name');
            $table->string('status')->default('idle');
            $table->json('config')->nullable();
            $table->text('credentials')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'driver', 'scope']);
        });

        Schema::create('organization_data_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('organization_connections')->cascadeOnDelete();
            $table->string('kind');
            $table->string('label');
            $table->json('mapping')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('last_count')->default(0);
            $table->timestamps();
        });

        Schema::create('organization_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('organization_connections')->cascadeOnDelete();
            $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope')->default('organization');
            $table->string('driver');
            $table->string('status')->default('running');
            $table->json('records')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_id')->constrained('organization_syncs')->cascadeOnDelete();
            $table->string('level')->default('info');
            $table->string('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_sync_logs');
        Schema::dropIfExists('organization_syncs');
        Schema::dropIfExists('organization_data_sources');
        Schema::dropIfExists('organization_connections');
    }
};
