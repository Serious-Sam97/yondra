<?php

namespace App\Console\Commands;

use App\Infrastructure\Models\User;
use Illuminate\Console\Command;

class VortexGrant extends Command
{
    protected $signature = 'vortex:grant {email}';

    protected $description = 'Grant Vortex admin access to a user by email';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user found with email {$this->argument('email')}");

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true])->save();

        $this->info("{$user->email} is now a Vortex admin.");
        $this->line('Reminder: if VORTEX_ADMIN_EMAILS is set, this email must also be on that list.');

        return self::SUCCESS;
    }
}
