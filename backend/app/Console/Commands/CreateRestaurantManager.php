<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateRestaurantManager extends Command
{
    protected $signature = 'restaurant:manager {--business=little-lemon : Business code}';

    protected $description = 'Create a restaurant manager using a hidden password prompt';

    public function handle(): int
    {
        $business = DB::table('businesses')->where('code', $this->option('business'))->first();
        if (! $business) {
            $this->error('Business not found.');

            return self::FAILURE;
        }
        $values = ['name' => $this->ask('Manager name'), 'username' => $this->ask('Username'), 'password' => $this->secret('Password (at least 6 characters)')];
        $validator = Validator::make($values, ['name' => 'required|string|max:100', 'username' => ['required', 'alpha_dash', 'max:60', Rule::unique('users')->where('business_id', $business->id)], 'password' => 'required|string|min:6|max:100']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = User::create([...$values, 'business_id' => $business->id, 'email' => $values['username'].'.'.$business->id.'@staff.local', 'roles' => ['manager'], 'active' => true]);
        DB::table('businesses')->where('id', $business->id)->whereNull('owner_id')->update(['owner_id' => $user->id]);
        $this->info('Manager account created.');

        return self::SUCCESS;
    }
}
