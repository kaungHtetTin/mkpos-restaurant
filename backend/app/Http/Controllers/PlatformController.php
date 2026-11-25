<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|max:100|confirmed',
            'business_name' => 'required|string|max:100',
            'business_code' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:businesses,code'],
        ]);
        $user = DB::transaction(function () use ($data): User {
            $plan = DB::table('plans')->where('is_trial', true)->where('active', true)->first();
            abort_unless($plan, 422, 'Registration is unavailable. Please contact the platform office.');
            $business = DB::table('businesses')->insertGetId(['name' => $data['business_name'], 'code' => $data['business_code'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password'], 'username' => 'owner', 'roles' => ['manager'], 'active' => true, 'business_id' => $business]);
            DB::table('businesses')->where('id', $business)->update(['owner_id' => $user->id]);
            DB::table('restaurant_settings')->insert(['business_id' => $business, 'name' => $data['business_name']]);
            DB::table('subscriptions')->insert(['business_id' => $business, 'plan_id' => $plan->id, 'status' => 'trial', 'plan_name' => $plan->name, 'price' => 0, 'currency' => $plan->currency, 'billing_period' => $plan->billing_period, 'starts_at' => now(), 'ends_at' => now()->addMonthNoOverflow(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('platform_events')->insert(['business_id' => $business, 'actor_id' => $user->id, 'action' => 'business_registered', 'details' => '{}', 'created_at' => now()]);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user, 'csrf' => csrf_token()], 201);
    }

    public function setup(Request $request): JsonResponse
    {
        $businessId = BusinessData::id();
        abort_unless(DB::table('businesses')->where('id', $businessId)->value('owner_id') === $request->user()->id, 403, 'Only the business owner can complete setup.');
        $data = $request->validate([
            'name' => 'required|string|max:100', 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => 'required|timezone', 'contact' => 'nullable|string|max:255',
            'other_payment_label' => 'required|string|max:60',
            'takeaway_only' => 'required|boolean',
            'tables' => 'present|array|max:100'.($request->boolean('takeaway_only') ? '|size:0' : '|min:1'),
            'tables.*' => 'array:name,seats',
            'tables.*.name' => 'required|string|max:30|distinct:ignore_case',
            'tables.*.seats' => 'required|integer|min:1|max:30',
            'menu' => 'required|array|min:1|max:200',
            'menu.*' => 'array:name,category,price,description',
            'menu.*.name' => 'required|string|max:100',
            'menu.*.category' => 'required|string|max:80',
            'menu.*.price' => 'required|integer|min:0|max:10000000',
            'menu.*.description' => 'nullable|string|max:255',
        ]);
        $data['contact'] = $data['contact'] ?? '';
        DB::transaction(function () use ($businessId, $data): void {
            $business = DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            if ($business->setup_completed_at) {
                return;
            }
            foreach ($data['tables'] as $table) {
                DB::table('dining_tables')->insert(['business_id' => $businessId, 'name' => $table['name'], 'seats' => $table['seats'], 'active' => true]);
            }
            $categories = [];
            foreach ($data['menu'] as $item) {
                $categoryKey = Str::lower($item['category']);
                if (! isset($categories[$categoryKey])) {
                    $categories[$categoryKey] = DB::table('categories')->insertGetId(['business_id' => $businessId, 'name' => $item['category'], 'sort' => count($categories), 'active' => true]);
                }
                DB::table('menu_items')->insert([
                    'business_id' => $businessId, 'category_id' => $categories[$categoryKey], 'name' => $item['name'],
                    'price' => $item['price'], 'description' => $item['description'] ?? '', 'emoji' => '🍽️', 'active' => true, 'available' => true,
                ]);
            }
            $settings = array_intersect_key($data, array_flip(['name', 'currency', 'timezone', 'contact', 'other_payment_label']));
            DB::table('restaurant_settings')->where('business_id', $businessId)->update($settings);
            DB::table('businesses')->where('id', $businessId)->update(['name' => $data['name'], 'setup_completed_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    public function billing(Request $request): JsonResponse
    {
        $business = DB::table('businesses')->find(BusinessData::id());
        abort_unless($business->owner_id === $request->user()->id, 403, 'Only the business owner can view billing.');

        return response()->json([
            'business' => $business, 'subscription' => BusinessData::subscription($business->id),
            'payments' => DB::table('subscription_payments')->where('business_id', $business->id)->orderByDesc('id')->paginate(25),
            'plans' => DB::table('plans')->where('is_public', true)->where('active', true)->where('is_trial', false)->get(),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $email = strtolower(trim($data['email']));
        $account = User::where('email', $email)->where('active', true)->where('is_platform_admin', false)->whereNotNull('business_id')->exists();
        if ($account) {
            Password::sendResetLink(['email' => $email]);
        }

        return response()->json(['message' => 'If this email belongs to an active account, a password reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => 'required|string|min:6|max:100|confirmed']);
        $data['email'] = strtolower($data['email']);
        $status = Password::reset($data, function (User $user, string $password): void {
            abort_unless($user->active && ! $user->is_platform_admin && $user->business_id, 403);
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Password reset. Sign in with your new password.']);
    }
}
