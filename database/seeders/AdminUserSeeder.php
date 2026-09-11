<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Shared\Enums\UserStatus;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'admin@rexmlm.test'],
            [
                'name' => 'Administrador',
                'password' => 'password',
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]
        );

        $user->syncRoles(['admin']);
    }
}
