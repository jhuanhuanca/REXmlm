<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_company_memberships')) {
            Schema::create('user_company_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('catalog_company_id');
                $table->string('catalog_company_name')->nullable();
                $table->unsignedBigInteger('catalog_rank_id')->nullable();
                $table->string('catalog_rank_name')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->decimal('extra_price', 10, 2)->nullable();
                $table->string('currency', 3)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'catalog_company_id']);
                $table->index(['user_id', 'is_primary']);
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'active_catalog_company_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('active_catalog_company_id')->nullable()->after('catalog_rank_name');
            });
        }

        if (Schema::hasTable('organization_users')) {
            Schema::table('organization_users', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('organization_users', function (Blueprint $table) {
                $table->dropUnique(['user_id']);
            });

            Schema::table('organization_users', function (Blueprint $table) {
                $table->unique(['organization_id', 'user_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('invitations') && ! Schema::hasColumn('invitations', 'catalog_company_id')) {
            Schema::table('invitations', function (Blueprint $table) {
                $table->unsignedBigInteger('catalog_company_id')->nullable()->after('network_id');
                $table->string('catalog_company_name')->nullable()->after('catalog_company_id');
            });
        }

        if (Schema::hasTable('user_company_memberships') && DB::table('user_company_memberships')->count() === 0) {
            $users = DB::table('users')
                ->whereNotNull('catalog_company_id')
                ->select('id', 'catalog_company_id', 'catalog_company_name', 'catalog_rank_id', 'catalog_rank_name')
                ->get();

            foreach ($users as $user) {
                DB::table('user_company_memberships')->insert([
                    'user_id' => $user->id,
                    'catalog_company_id' => $user->catalog_company_id,
                    'catalog_company_name' => $user->catalog_company_name,
                    'catalog_rank_id' => $user->catalog_rank_id,
                    'catalog_rank_name' => $user->catalog_rank_name,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('users')->where('id', $user->id)->update([
                    'active_catalog_company_id' => $user->catalog_company_id,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invitations', 'catalog_company_id')) {
            Schema::table('invitations', function (Blueprint $table) {
                $table->dropColumn(['catalog_company_id', 'catalog_company_name']);
            });
        }

        if (Schema::hasTable('organization_users')) {
            Schema::table('organization_users', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
            Schema::table('organization_users', function (Blueprint $table) {
                $table->dropUnique(['organization_id', 'user_id']);
                $table->unique('user_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('users', 'active_catalog_company_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('active_catalog_company_id');
            });
        }

        Schema::dropIfExists('user_company_memberships');
    }
};
