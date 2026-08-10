<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;

/**
 * Seeds a known superadmin so it exists on every environment the seeder runs on.
 *
 * ⚠ TEMPORARY DEV CREDENTIAL. The password here is intentionally simple for
 * development and MUST be rotated before any public deployment. Prefer setting
 * SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD in the environment over editing this
 * file. Idempotent — re-running updates the same account.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = mb_strtolower(env('SUPERADMIN_EMAIL', 'superadmin@vfi-fc.com'));
        $password = env('SUPERADMIN_PASSWORD', 'VFI@123');

        $user = User::withTrashed()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Admin',
                'password' => $password,          // hashed by the model cast
                'status' => UserStatus::Active,
                'deleted_at' => null,
            ],
        );

        // ensure the superadmin role (idempotent)
        UserRole::firstOrCreate(
            ['user_id' => $user->id, 'role' => Role::SuperAdmin->value, 'agency_id' => null],
            ['granted_at' => now()],
        );

        $this->command?->info("Superadmin seeded: {$email}");
    }
}
