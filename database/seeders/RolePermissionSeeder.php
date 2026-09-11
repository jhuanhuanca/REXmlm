<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'dashboard.view',
            'team.view',
            'invitation.create',
            'store.manage',
            'product.manage',
            'landing.manage',
            'subscription.manage',
            'commission.view',
            'commission.pay',
            'report.view',
            'report.generate',
            'admin.users.manage',
            'admin.plans.manage',
            'admin.commissions.manage',
            'admin.settings.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $admin = Role::findOrCreate('admin');
        $leader = Role::findOrCreate('leader');
        $partner = Role::findOrCreate('partner');

        $admin->syncPermissions(Permission::all());

        $leader->syncPermissions([
            'dashboard.view',
            'team.view',
            'invitation.create',
            'store.manage',
            'product.manage',
            'landing.manage',
            'subscription.manage',
            'commission.view',
            'report.view',
            'report.generate',
        ]);

        $partner->syncPermissions([
            'dashboard.view',
            'subscription.manage',
        ]);
    }
}
