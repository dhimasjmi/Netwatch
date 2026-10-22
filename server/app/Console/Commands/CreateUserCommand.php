<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CreateUserCommand extends Command
{
    protected $signature = 'netwatch:create-user
                            {email : Alamat email untuk login}
                            {--name= : Nama tampilan}
                            {--role=admin : admin, operator, atau viewer}';

    protected $description = 'Buat pengguna dashboard NetWatch';

    public function handle(): int
    {
        $password = (string) $this->secret('Kata sandi (min. 12 karakter, huruf besar/kecil + angka)');

        if ($password !== (string) $this->secret('Ulangi kata sandi')) {
            $this->error('Kata sandi tidak sama.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'email' => $this->argument('email'),
            'name' => $this->option('name') ?: (string) $this->argument('email'),
            'role' => $this->option('role'),
            'password' => $password,
        ], [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([...$validator->validated(), 'is_active' => true]);

        $this->info("Pengguna {$user->email} dibuat dengan peran {$user->role->value}.");

        return self::SUCCESS;
    }
}
