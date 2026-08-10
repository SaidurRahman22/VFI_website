<?php

namespace App\Console\Commands;

use App\Services\AdminAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sealed bootstrap of the first superadmin (docs §6.2). Refuses to run once any
 * superadmin exists — after that, accounts are invite-only. The generated
 * password is printed ONCE; the admin sets up mandatory TOTP on first sign-in.
 *
 *   php artisan admin:create-superadmin owner@vfi-edu.com
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:create-superadmin {email} {--password=}';

    protected $description = 'Create the first superadmin (sealed — one-time bootstrap).';

    public function handle(AdminAccounts $accounts): int
    {
        $email = (string) $this->argument('email');
        $password = (string) ($this->option('password') ?: Str::password(20));

        try {
            $user = $accounts->createSuperAdmin($email, $password);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Superadmin created: {$user->email}");
        if (! $this->option('password')) {
            $this->line('');
            $this->warn('Temporary password (shown once — store it securely, then change it):');
            $this->line("  {$password}");
        }
        $this->line('');
        $this->info('Next: sign in at /admin-login.html and complete mandatory TOTP enrolment.');

        return self::SUCCESS;
    }
}
