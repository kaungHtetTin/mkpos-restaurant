<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateOfficeAdmin extends Command
{
    protected $signature = 'platform:admin';

    protected $description = 'Create a platform office administrator with a hidden password prompt';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Administrator name'), 'email' => strtolower($this->ask('Email')), 'password' => $this->secret('Password (at least 12 characters)')];
        $validator = Validator::make($data, ['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users,email', 'password' => 'required|string|min:12|max:100']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User($data);
        $user->business_id = null;
        $user->is_platform_admin = true;
        $user->office_role = 'super_admin';
        $user->roles = [];
        $user->active = true;
        $user->save();
        $this->info('Platform administrator created. Sign in at /office.');

        return self::SUCCESS;
    }
}
