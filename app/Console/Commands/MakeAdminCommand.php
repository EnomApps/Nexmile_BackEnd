<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

/**
 * Creates an admin account, or promotes an existing user.
 *
 * Deliberately a console command and not a signup route: an admin can verify
 * merchants and suspend accounts, so it must never be self-service.
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'nexmile:make-admin
                            {--email= : Email address}
                            {--name= : Full name}
                            {--phone= : 10-digit mobile number}';

    protected $description = 'Create an admin user, or promote an existing account to admin';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email address');
        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing) {
            if ($existing->role === UserRole::Admin) {
                $this->warn("{$email} is already an admin.");

                return self::SUCCESS;
            }

            if (! $this->confirm("{$email} already exists as a {$existing->role->value}. Promote to admin?", false)) {
                return self::FAILURE;
            }

            $existing->restore();
            $existing->update(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

            $this->info("Promoted {$email} to admin.");

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: $this->ask('Full name');
        $phone = $this->option('phone') ?: $this->ask('Mobile number (10 digits)');

        // secret() keeps the password out of the terminal and shell history.
        $password = $this->secret('Password (at least 12 characters)');
        $confirm = $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/', Rule::unique('users', 'phone')],
            // Longer than the customer minimum: this account can suspend
            // merchants and read every KYC document.
            'password' => ['required', 'string', 'min:12'],
        ], [
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make($password),
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->newLine();
        $this->info("Admin created: {$email}");
        $this->line('  Sign in at /admin/login');

        return self::SUCCESS;
    }
}
