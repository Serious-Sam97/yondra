<?php

namespace App\Console\Commands;

use App\Infrastructure\Models\User;
use Illuminate\Console\Command;

class VortexRevoke extends Command
{
    protected $signature = 'vortex:revoke {email}';

    protected $description = 'Revoke Vortex admin access from a user by email';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user found with email {$this->argument('email')}");

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => false])->save();

        $this->info("{$user->email} is no longer a Vortex admin.");

        return self::SUCCESS;
    }
}
