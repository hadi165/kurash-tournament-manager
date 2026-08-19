<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the first account on a fresh installation.
 *
 * Registration is open in the starter kit, but on a live federation server the
 * first account should be made deliberately from the command line rather than
 * by whoever reaches the sign-up page first.
 *
 * The password is generated and printed once unless one is supplied, so there
 * is never a shipped default like the old admin/admin123.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'kurash:create-admin
                            {--name= : Full name}
                            {--email= : Email address}
                            {--password= : Password (generated and shown once if omitted)}
                            {--role=admin : admin, supervisor, official or viewer}';

    protected $description = 'Create an administrator account';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Full name', 'Administrator');
        $email = $this->option('email') ?: $this->ask('Email address');
        $role = $this->option('role');

        $generated = $this->option('password') === null;
        $password = $this->option('password') ?: Str::password(16, symbols: false);

        $validator = Validator::make(
            compact('name', 'email', 'password', 'role'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'password' => ['required', Password::min(8)],
                'role' => ['required', Rule::in(['admin', 'supervisor', 'official', 'viewer'])],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        $this->newLine();
        $this->info("Account created ({$role}).");
        $this->line("  email:    {$email}");

        if ($generated) {
            $this->line("  password: {$password}");
            $this->warn('  Write this down now — it is not stored anywhere else and will not be shown again.');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
