<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('organization_connections')->nullOnDelete();
            $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope')->default('organization');
            $table->string('external_code')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->nullable();
            $table->string('sponsor_code')->nullable();
            $table->unsignedBigInteger('sponsor_member_id')->nullable();
            $table->string('rank_code')->nullable();
            $table->string('rank_name')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email'], 'org_members_org_email');
            $table->index(['organization_id', 'user_id'], 'org_members_org_user');
            $table->index(['organization_id', 'sponsor_code'], 'org_members_org_sponsor');
            $table->unique(['organization_id', 'external_code'], 'org_members_org_code_unique');
        });

        Schema::create('organization_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('organization_members')->nullOnDelete();
            $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete();
            $table->string('external_code')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email'], 'org_customers_org_email');
        });

        Schema::create('organization_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('organization_connections')->nullOnDelete();
            $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('organization_members')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('organization_customers')->nullOnDelete();
            $table->string('scope')->default('organization');
            $table->string('external_code')->nullable();
            $table->string('period', 7)->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->decimal('total', 14, 2)->nullable();
            $table->string('currency')->nullable();
            $table->boolean('qualifying')->nullable();
            $table->string('status')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'period'], 'org_orders_org_period');
            $table->index(['member_id', 'period'], 'org_orders_member_period');
        });

        Schema::create('organization_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('organization_orders')->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->decimal('pv', 14, 4)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('organization_connections')->nullOnDelete();
            $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete();
            $table->foreignId('member_id')->constrained('organization_members')->cascadeOnDelete();
            $table->string('scope')->default('organization');
            $table->string('period', 7);
            $table->string('unit')->default('PV');
            $table->decimal('personal_volume', 14, 4)->nullable();
            $table->decimal('group_volume', 14, 4)->nullable();
            $table->decimal('sales_volume', 14, 4)->nullable();
            $table->decimal('commission_volume', 14, 4)->nullable();
            $table->boolean('qualifying')->nullable();
            $table->unsignedTinyInteger('priority')->default(10);
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'period', 'scope'], 'org_volumes_member_period_scope');
        });

        Schema::create('organization_company_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('organization_connections')->nullOnDelete();
            $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('organization_members')->nullOnDelete();
            $table->string('scope')->default('organization');
            $table->string('period', 7)->nullable();
            $table->string('external_code')->nullable();
            $table->string('kind')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('source_type')->default('company');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'period'], 'org_bonuses_org_period');
        });

        Schema::create('organization_member_ranks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('organization_members')->cascadeOnDelete();
            $table->string('scope')->default('organization');
            $table->string('period', 7)->nullable();
            $table->string('rank_code')->nullable();
            $table->string('rank_name')->nullable();
            $table->boolean('qualified')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'period'], 'org_ranks_member_period');
        });

        Schema::create('organization_sponsors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('organization_members')->cascadeOnDelete();
            $table->foreignId('sponsor_member_id')->constrained('organization_members')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('member_id');
            $table->index('sponsor_member_id');
        });

        Schema::create('organization_rank_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_rank_id')->nullable();
            $table->string('code')->nullable();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'catalog_rank_id'], 'org_rank_defs_org_catalog');
        });

        Schema::create('organization_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_product_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->decimal('pv', 14, 4)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'sku'], 'org_catalog_items_org_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_catalog_items');
        Schema::dropIfExists('organization_rank_definitions');
        Schema::dropIfExists('organization_sponsors');
        Schema::dropIfExists('organization_member_ranks');
        Schema::dropIfExists('organization_company_commissions');
        Schema::dropIfExists('organization_volumes');
        Schema::dropIfExists('organization_order_items');
        Schema::dropIfExists('organization_orders');
        Schema::dropIfExists('organization_customers');
        Schema::dropIfExists('organization_members');
    }
};
