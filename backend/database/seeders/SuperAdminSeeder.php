<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;

/**
 * Seeds a superadmin from the ENVIRONMENT only — no password is ever stored in
 * source (safe for public repos). Set SUPERADMIN_EMAIL + SUPERADMIN_PASSWORD in
 * the environment/.env to seed; if the password is unset the seeder skips
 * cleanly. Idempotent — re-running updates the same account.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = mb_strtolower(env('SUPERADMIN_EMAIL', 'superadmin@vfi-fc.com'));
        $password = env('SUPERADMIN_PASSWORD');

        // No credential baked into code. Without an env password, do nothing
        // (never create a superadmin with a blank/guessable password).
        if (blank($password)) {
            $this->command?->warn('SUPERADMIN_PASSWORD not set — skipping superadmin seed. Set it in .env to seed.');

            return;
        }

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
