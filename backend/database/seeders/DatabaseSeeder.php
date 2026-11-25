<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedOfficeAdmin();

        DB::table('restaurant_settings')->insertOrIgnore(['id' => 1, 'name' => 'Little Lemon', 'currency' => 'MMK', 'timezone' => 'Asia/Yangon', 'contact' => '', 'footer' => 'Thank you. Come back hungry!', 'other_payment_label' => 'QR / Card']);
        if (app()->environment('production')) {
            return;
        }
        foreach (['manager' => ['manager'], 'waiter' => ['waiter'], 'cashier' => ['cashier'], 'kitchen' => ['kitchen']] as $username => $roles) {
            if (! User::where('username', $username)->exists()) {
                User::create(['name' => ucfirst($username), 'username' => $username, 'email' => "$username@littlelemon.local", 'password' => 'Lemon2026!', 'roles' => $roles]);
            }
        }
        DB::table('businesses')->where('id', 1)->whereNull('owner_id')->update(['owner_id' => User::where('business_id', 1)->where('username', 'manager')->value('id')]);
        if (DB::table('categories')->where('business_id', 1)->exists()) {
            return;
        }
        foreach (['Rice & noodles', 'Small plates', 'Soups & salads', 'Drinks'] as $i => $name) {
            DB::table('categories')->insert(['id' => $i + 1, 'name' => $name, 'sort' => $i]);
        }
        foreach ([
            [1, 'Chicken fried rice', 'Wok-tossed rice, egg & seasonal vegetables', 6500, '🍛'],
            [1, 'Shan noodles', 'Rice noodles, chicken & fragrant garlic oil', 5500, '🍜'],
            [1, 'Basil chicken rice', 'Fresh basil, chili & a sunny-side-up egg', 7500, '🍚'],
            [1, 'Vegetable noodles', 'Fresh greens & noodles from the wok', 5000, '🥡'],
            [2, 'Crispy spring rolls', 'Golden vegetable rolls with sweet chili', 4500, '🥟'],
            [2, 'Grilled chicken skewers', 'Three skewers with our house dipping sauce', 6000, '🍢'],
            [3, 'Tea leaf salad', 'Fermented tea leaves, nuts & crunchy beans', 4500, '🥗'],
            [3, 'Tom yum soup', 'A bright, spicy broth with mushrooms', 6500, '🍲'],
            [4, 'Fresh lime soda', 'Freshly squeezed lime, bubbles & ice', 2500, '🍋'],
            [4, 'Myanmar milk tea', 'Strong black tea, creamy & comforting', 2000, '☕'],
            [4, 'Iced coffee', 'Fresh coffee poured over ice', 3500, '🧋'],
            [4, 'Drinking water', 'Chilled bottled water', 1000, '💧'],
        ] as [$category, $name, $description, $price, $emoji]) {
            DB::table('menu_items')->insert(['category_id' => $category, 'name' => $name, 'description' => $description, 'price' => $price, 'emoji' => $emoji]);
        }
        foreach (range(1, 12) as $i) {
            DB::table('dining_tables')->insert(['name' => str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'seats' => $i <= 4 ? 2 : ($i <= 10 ? 4 : 6)]);
        }
    }

    private function seedOfficeAdmin(): void
    {
        $credentials = config('auth.office_admin');
        if (blank($credentials['email']) && blank($credentials['password'])) {
            return;
        }
        if (User::where('is_platform_admin', true)->exists()) {
            return;
        }

        $credentials['email'] = strtolower(trim((string) $credentials['email']));
        $data = Validator::make($credentials, [
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:12|max:100',
        ])->validate();

        $user = new User($data);
        $user->business_id = null;
        $user->is_platform_admin = true;
        $user->office_role = 'super_admin';
        $user->roles = [];
        $user->active = true;
        $user->save();
    }
}
