<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MultiBusinessTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['2026-11-26 10:15:00', '2026-12-26 10:15:00', false])]
    #[TestWith(['2026-01-31 10:15:00', '2026-02-28 10:15:00', false])]
    #[TestWith(['2028-01-31 10:15:00', '2028-02-29 10:15:00', false])]
    #[TestWith(['2026-11-26 10:15:00', '2026-12-26 10:15:00', true])]
    #[TestWith(['2026-01-31 10:15:00', '2026-02-28 10:15:00', true])]
    #[TestWith(['2028-01-31 10:15:00', '2028-02-29 10:15:00', true])]
    public function test_new_businesses_get_one_free_calendar_month(string $start, string $end, bool $fromOffice): void
    {
        $this->travelTo(CarbonImmutable::parse($start, 'UTC'));
        if ($fromOffice) {
            $this->office();
            $businessId = $this->postAction('office/businesses', [
                'name' => 'Trial Cafe', 'owner_name' => 'Trial Owner', 'owner_email' => 'trial@example.test',
                'password' => 'TrialPassword123!', 'password_confirmation' => 'TrialPassword123!',
            ])->assertOk()->json('id');
        } else {
            $businessId = $this->registerBusiness(completeSetup: false)->business_id;
        }

        $this->assertDatabaseHas('subscriptions', [
            'business_id' => $businessId, 'status' => 'trial', 'price' => 0,
            'plan_name' => '1-month trial', 'starts_at' => $start, 'ends_at' => $end,
        ]);
        $this->assertDatabaseCount('subscription_payments', 0);
    }

    public function test_trial_plan_migration_preserves_existing_subscription_dates_and_paid_plans(): void
    {
        DB::table('plans')->where('is_trial', true)->update(['name' => '14-day trial']);
        $paidPlanId = $this->plan();
        $subscriptions = DB::table('subscriptions')->get()->toArray();
        $migration = require database_path('migrations/2026_11_25_174552_set_default_trial_plan_to_one_month.php');

        $migration->up();

        $this->assertDatabaseHas('plans', ['is_trial' => true, 'name' => '1-month trial']);
        $this->assertDatabaseHas('plans', ['id' => $paidPlanId, 'name' => 'Monthly', 'price' => 30000]);
        $this->assertEquals($subscriptions, DB::table('subscriptions')->get()->toArray());
    }

    public function test_initial_business_trial_is_one_calendar_month(): void
    {
        $subscription = DB::table('subscriptions')->where('business_id', 1)->first();

        $this->assertSame('1-month trial', $subscription->plan_name);
        $this->assertSame(CarbonImmutable::parse($subscription->starts_at)->addMonthNoOverflow()->toDateTimeString(), $subscription->ends_at);
    }

    public function test_seeding_bootstraps_an_office_super_admin_in_production(): void
    {
        $this->app->instance('env', 'production');
        config(['auth.office_admin' => ['name' => 'Office Setup', 'email' => 'SETUP@example.com', 'password' => 'OfficePassword123!']]);

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $admin = User::where('email', 'setup@example.com')->firstOrFail();
        $this->assertSame('Office Setup', $admin->name);
        $this->assertNull($admin->business_id);
        $this->assertTrue($admin->is_platform_admin);
        $this->assertSame('super_admin', $admin->office_role);
        $this->assertSame([], $admin->roles);
        $this->assertTrue($admin->active);
        $this->assertTrue(Hash::check('OfficePassword123!', $admin->password));
        $this->assertDatabaseMissing('users', ['username' => 'manager']);
        $this->app->instance('env', 'testing');
        $this->postJson('/api/v1/office/login', ['email' => $admin->email, 'password' => 'OfficePassword123!'])->assertOk();

        $admin->update(['password' => 'ChangedPassword123!']);
        config(['auth.office_admin.email' => 'another@example.com']);
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, User::where('is_platform_admin', true)->count());
        $this->assertTrue(Hash::check('ChangedPassword123!', $admin->fresh()->password));
    }

    public function test_seeding_skips_office_admin_when_credentials_are_unset(): void
    {
        config(['auth.office_admin' => ['name' => 'Office Admin', 'email' => null, 'password' => null]]);

        $this->seed();

        $this->assertDatabaseMissing('users', ['is_platform_admin' => true]);
    }

    public function test_seeding_rejects_an_invalid_office_password(): void
    {
        config(['auth.office_admin' => ['name' => 'Office Admin', 'email' => 'setup@example.com', 'password' => 'short']]);

        try {
            $this->seed();
            $this->fail('Invalid bootstrap credentials should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }

        $this->assertDatabaseMissing('users', ['is_platform_admin' => true]);
    }

    public function test_seeding_does_not_promote_a_business_user_with_the_configured_email(): void
    {
        $user = User::factory()->create(['email' => 'setup@example.com']);
        config(['auth.office_admin' => ['name' => 'Office Admin', 'email' => $user->email, 'password' => 'OfficePassword123!']]);

        try {
            $this->seed();
            $this->fail('An existing business email should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $this->assertFalse($user->fresh()->is_platform_admin);
        $this->assertDatabaseMissing('users', ['is_platform_admin' => true]);
    }

    private function registerBusiness(string $code = 'golden-cafe', bool $completeSetup = true): User
    {
        Auth::logout();
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Business Owner', 'email' => $code.'@example.com', 'password' => 'OwnerPassword123!',
            'password_confirmation' => 'OwnerPassword123!', 'business_name' => ucfirst($code), 'business_code' => $code,
        ])->assertCreated();
        if ($completeSetup) {
            $this->postJson('/api/v1/setup', [...$this->setupData(), 'name' => ucfirst($code), 'currency' => 'MMK', 'timezone' => 'Asia/Yangon', 'contact' => '', 'other_payment_label' => 'QR / Card'])->assertOk();
        }

        return User::findOrFail($response->json('user.id'));
    }

    private function postAction(string $path, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/'.$path, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function setupData(): array
    {
        return ['takeaway_only' => true, 'tables' => [], 'menu' => [['name' => 'Setup dish', 'category' => 'Main dishes', 'price' => 1000]]];
    }

    private function office(): User
    {
        $user = User::factory()->create(['name' => 'Office Admin', 'email' => 'office@example.com', 'business_id' => null, 'roles' => [], 'active' => true, 'password' => 'OfficePassword123!']);
        $user->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($user, 'office');
        Auth::shouldUse('web');

        return $user;
    }

    private function plan(): int
    {
        return DB::table('plans')->insertGetId(['name' => 'Monthly', 'price' => 30000, 'currency' => 'MMK', 'billing_period' => 'month', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_registration_provisions_an_empty_business_without_email_verification(): void
    {
        $this->seed();
        $owner = $this->registerBusiness(completeSetup: false);
        $this->assertNull($owner->email_verified_at);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('business.owner_id', $owner->id)
            ->assertJsonPath('subscription.status', 'trial')->assertJsonPath('writable', true)
            ->assertJsonCount(0, 'orders')->assertJsonCount(0, 'menu')->assertJsonCount(0, 'tables')
            ->assertJsonCount(1, 'staff')->assertJsonPath('settings.name', 'Golden-cafe');
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('business.setup_completed_at', null);
        $this->postAction('orders', ['type' => 'takeaway'])->assertForbidden();
        $this->postAction('manage/settings', ['name' => 'Bypass'])->assertForbidden();
        $this->getJson('/api/v1/sales')->assertForbidden();
        $this->postJson('/api/v1/setup', [...$this->setupData(), 'name' => 'Golden-cafe', 'currency' => 'MMK', 'timezone' => 'Asia/Yangon', 'other_payment_label' => 'QR / Card'])->assertOk();
        $this->assertNotNull(DB::table('businesses')->where('id', $owner->business_id)->value('setup_completed_at'));
        $this->postAction('orders', ['type' => 'takeaway'])->assertOk();
        $this->assertDatabaseHas('orders', ['business_id' => $owner->business_id]);
    }

    public function test_setup_is_validated_persisted_and_only_completed_by_its_owner(): void
    {
        $owner = $this->registerBusiness(completeSetup: false);
        $this->postJson('/api/v1/logout')->assertOk();
        $this->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'OwnerPassword123!'])->assertOk();
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('business.setup_completed_at', null);
        $setup = [...$this->setupData(), 'name' => 'Golden Restaurant', 'currency' => 'USD', 'timezone' => 'Asia/Bangkok', 'contact' => '123 Main Street', 'other_payment_label' => 'Card'];
        $setup['takeaway_only'] = false;
        $setup['tables'] = [['name' => '01', 'seats' => 4], ['name' => 'Patio', 'seats' => 6]];
        $setup['menu'] = [['name' => 'Soup', 'category' => 'Starters', 'price' => 5], ['name' => 'Salad', 'category' => 'starters', 'price' => 8, 'description' => 'Fresh greens']];
        $this->postJson('/api/v1/setup', [...$setup, 'timezone' => 'Invalid/Zone', 'currency' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['timezone', 'currency']);
        $this->postJson('/api/v1/setup', [...$setup, 'tables' => []])->assertUnprocessable()->assertJsonValidationErrors('tables');
        $this->postJson('/api/v1/setup', [...$setup, 'tables' => [['name' => 'Patio', 'seats' => 4], ['name' => 'patio', 'seats' => 2]]])->assertUnprocessable()->assertJsonValidationErrors('tables.0.name');
        $this->postJson('/api/v1/setup', [...$setup, 'menu' => []])->assertUnprocessable()->assertJsonValidationErrors('menu');
        $this->postJson('/api/v1/setup', [...$setup, 'menu' => [['name' => 'Soup', 'category' => 'Starters', 'price' => -1]]])->assertUnprocessable()->assertJsonValidationErrors('menu.0.price');
        $this->assertSame(0, DB::table('dining_tables')->where('business_id', $owner->business_id)->count());
        $this->assertSame(0, DB::table('menu_items')->where('business_id', $owner->business_id)->count());
        $this->assertNull(DB::table('businesses')->where('id', $owner->business_id)->value('setup_completed_at'));
        $staff = User::factory()->create(['business_id' => $owner->business_id, 'active' => true, 'roles' => ['manager']]);
        $this->withSession(['password_hash_web' => null])->actingAs($staff);
        $this->postJson('/api/v1/setup', $setup)->assertForbidden();
        $this->withSession(['password_hash_web' => null])->actingAs($owner);
        $this->postJson('/api/v1/setup', $setup)->assertOk();
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('settings.currency', 'USD')->assertJsonPath('settings.timezone', 'Asia/Bangkok')->assertJsonPath('settings.contact', '123 Main Street')->assertJsonPath('business.name', 'Golden Restaurant');
        $completed = DB::table('businesses')->where('id', $owner->business_id)->value('setup_completed_at');
        $this->postJson('/api/v1/setup', [...$setup, 'currency' => 'MMK'])->assertOk();
        $this->assertDatabaseHas('restaurant_settings', ['business_id' => $owner->business_id, 'currency' => 'USD']);
        $this->assertSame($completed, DB::table('businesses')->where('id', $owner->business_id)->value('setup_completed_at'));
        $this->assertSame(2, DB::table('dining_tables')->where('business_id', $owner->business_id)->count());
        $this->assertSame(2, DB::table('menu_items')->where('business_id', $owner->business_id)->count());
        $this->assertSame(1, DB::table('categories')->where('business_id', $owner->business_id)->count());
        $this->assertDatabaseHas('dining_tables', ['business_id' => $owner->business_id, 'name' => 'Patio', 'seats' => 6]);
        $this->assertDatabaseHas('menu_items', ['business_id' => $owner->business_id, 'name' => 'Salad', 'price' => 8, 'description' => 'Fresh greens', 'available' => true]);
        $this->postAction('orders', ['type' => 'takeaway'])->assertOk();
    }

    public function test_registration_rejects_duplicate_email_code_and_invalid_code_without_partial_records(): void
    {
        $this->registerBusiness();
        Auth::logout();
        $base = ['name' => 'Other', 'email' => 'GOLDEN-CAFE@example.com', 'password' => 'OwnerPassword123!', 'password_confirmation' => 'OwnerPassword123!', 'business_name' => 'Other', 'business_code' => 'other-cafe'];
        $this->postJson('/api/v1/register', $base)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/register', [...$base, 'email' => 'other@example.com', 'business_code' => 'golden-cafe'])->assertUnprocessable()->assertJsonValidationErrors('business_code');
        $this->postJson('/api/v1/register', [...$base, 'email' => 'other@example.com', 'business_code' => '../bad'])->assertUnprocessable();
        $this->assertDatabaseCount('businesses', 2);
    }

    public function test_a_business_cannot_read_or_change_another_business_records(): void
    {
        $this->seed();
        $manager = User::where('username', 'manager')->firstOrFail();
        $this->actingAs($manager);
        $order = $this->postAction('orders', ['type' => 'takeaway'])->assertOk()->json();
        $owner = $this->registerBusiness();
        $this->withSession(['password_hash_web' => null])->actingAs($owner);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonCount(1, 'menu')->assertJsonCount(0, 'orders');
        $this->getJson('/api/v1/orders/'.$order['id'])->assertNotFound();
        $this->postAction('orders/'.$order['id'].'/discard', ['version' => 1])->assertNotFound();
        $this->postAction('orders', ['type' => 'dine_in', 'table_id' => 1])->assertUnprocessable();
        $this->postAction('manage/categories', ['id' => 1, 'name' => 'Hacked', 'sort' => 0, 'active' => true])->assertUnprocessable();
        $this->postAction('manage/tables', ['id' => 1, 'name' => 'Hacked', 'seats' => 4, 'active' => true])->assertUnprocessable();
        $this->postAction('manage/availability', ['id' => 1, 'available' => false])->assertUnprocessable();
        $this->postAction('manage/menu', ['category_id' => 1, 'name' => 'Foreign', 'emoji' => 'x', 'price' => 1, 'active' => true, 'available' => true])->assertUnprocessable();
        $this->postAction('manage/staff', ['id' => $manager->id, 'name' => 'Hacked', 'username' => 'manager', 'email' => $manager->email, 'roles' => ['manager'], 'active' => true])->assertUnprocessable();
        $this->getJson('/api/v1/history')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('summary.day.amount', 0);
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'business_id' => 1, 'status' => 'open']);
    }

    public function test_business_names_and_staff_usernames_can_be_reused_and_login_is_scoped(): void
    {
        $this->seed();
        $owner = $this->registerBusiness();
        $this->postAction('manage/categories', ['name' => 'Drinks', 'sort' => 0, 'active' => true])->assertOk();
        $this->postAction('manage/tables', ['name' => '01', 'seats' => 4, 'active' => true])->assertOk();
        $this->postAction('manage/staff', ['name' => 'Second Waiter', 'username' => 'waiter', 'email' => 'second-waiter@example.com', 'password' => 'SecondPassword!', 'roles' => ['waiter'], 'active' => true])->assertOk();
        $this->postJson('/api/v1/logout')->assertOk();
        $this->postJson('/api/v1/login', ['email' => 'second-waiter@example.com', 'password' => 'Lemon2026!'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => ' SECOND-WAITER@example.com ', 'password' => 'SecondPassword!'])->assertOk()->assertJsonPath('user.business_id', $owner->business_id)->assertJsonPath('user.roles', ['waiter']);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonCount(1, 'menu');
        $this->postAction('manage/categories', ['name' => 'Forbidden', 'sort' => 0, 'active' => true])->assertForbidden();
        $this->getJson('/api/v1/billing')->assertForbidden();
        $this->postJson('/api/v1/logout')->assertOk();
        $this->postJson('/api/v1/login', ['email' => 'GOLDEN-CAFE@example.com', 'password' => 'OwnerPassword123!'])->assertOk()->assertJsonPath('user.id', $owner->id);
    }

    public function test_staff_email_is_required_globally_unique_and_editable(): void
    {
        $this->seed();
        $owner = $this->registerBusiness();
        $body = ['name' => 'Team Member', 'username' => 'team', 'password' => 'Team12', 'roles' => ['waiter'], 'active' => true];
        $this->postAction('manage/staff', [...$body, 'email' => 'team@example.com', 'password' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postAction('manage/staff', $body)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postAction('manage/staff', [...$body, 'email' => 'WAITER@littlelemon.local'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postAction('manage/staff', [...$body, 'email' => $owner->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postAction('manage/staff', [...$body, 'email' => ' TEAM@example.com '])->assertOk();
        $staff = User::where('email', 'team@example.com')->firstOrFail();
        $this->postAction('manage/staff', [...$body, 'id' => $staff->id, 'email' => $staff->email, 'password' => ''])->assertOk();
        $this->postAction('manage/staff', [...$body, 'id' => $staff->id, 'email' => 'updated@example.com', 'password' => ''])->assertOk();
        $this->postJson('/api/v1/logout')->assertOk();
        $this->postJson('/api/v1/login', ['email' => 'team@example.com', 'password' => $body['password']])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => 'updated@example.com', 'password' => $body['password']])->assertOk()->assertJsonPath('user.id', $staff->id);
    }

    public function test_staff_can_recover_their_password_and_disabled_accounts_cannot(): void
    {
        Notification::fake();
        $this->seed();
        $staff = User::where('username', 'waiter')->firstOrFail();
        $this->postJson('/api/v1/forgot-password', ['email' => $staff->email])->assertOk();
        Notification::assertSentTo($staff, ResetPassword::class);
        $token = Password::createToken($staff);
        $this->postJson('/api/v1/reset-password', ['email' => $staff->email, 'token' => $token, 'password' => 'UpdatedPassword123!', 'password_confirmation' => 'UpdatedPassword123!'])->assertOk();
        $this->postJson('/api/v1/login', ['email' => $staff->email, 'password' => 'UpdatedPassword123!'])->assertOk()->assertJsonPath('user.id', $staff->id);
        $this->postJson('/api/v1/logout')->assertOk();
        $token = Password::createToken($staff);
        $staff->update(['active' => false]);
        $this->postJson('/api/v1/reset-password', ['email' => $staff->email, 'token' => $token, 'password' => 'DisabledPassword123!', 'password_confirmation' => 'DisabledPassword123!'])->assertForbidden();
        $this->postJson('/api/v1/login', ['email' => $staff->email, 'password' => 'UpdatedPassword123!'])->assertUnprocessable();
    }

    public function test_business_passwords_require_at_least_six_characters(): void
    {
        Notification::fake();
        $this->seed();
        $body = ['name' => 'Owner', 'email' => 'six@example.com', 'business_name' => 'Six Cafe', 'business_code' => 'six-cafe'];
        $this->postJson('/api/v1/register', [...$body, 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $response = $this->postJson('/api/v1/register', [...$body, 'password' => 'Owner6', 'password_confirmation' => 'Owner6'])->assertCreated();
        $owner = User::findOrFail($response->json('user.id'));
        $token = Password::createToken($owner);
        $reset = ['email' => $owner->email, 'token' => $token];
        $this->postJson('/api/v1/reset-password', [...$reset, 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/reset-password', [...$reset, 'password' => 'Reset6', 'password_confirmation' => 'Reset6'])->assertOk();
        Auth::logout();
        $this->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'Reset6'])->assertOk();
        $this->office();
        $update = ['name' => 'Six Cafe', 'status' => 'active', 'owner_name' => $owner->name, 'owner_email' => $owner->email, 'owner_active' => true];
        $this->postAction('office/businesses/'.$owner->business_id, [...$update, 'owner_password' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('owner_password');
        $this->postAction('office/businesses/'.$owner->business_id, [...$update, 'owner_password' => 'Office'])->assertOk();
    }

    public function test_business_settings_updates_do_not_use_the_first_restaurant(): void
    {
        $this->seed();
        $owner = $this->registerBusiness();
        $this->postAction('manage/receipts', ['receipt_title' => 'My receipt', 'footer' => 'My footer', 'receipt_paper_width' => 58])->assertOk();
        $this->assertDatabaseHas('restaurant_settings', ['business_id' => $owner->business_id, 'receipt_title' => 'My receipt']);
        $this->assertNotSame('My receipt', DB::table('restaurant_settings')->where('business_id', 1)->value('receipt_title'));
    }

    public function test_photos_are_scoped_and_same_content_does_not_share_storage_between_businesses(): void
    {
        Storage::fake('local');
        $this->seed();
        $this->actingAs(User::where('username', 'manager')->firstOrFail());
        $photo = UploadedFile::fake()->image('dish.png', 30, 20);
        $body = ['id' => 1, 'category_id' => 1, 'name' => 'Dish', 'emoji' => 'x', 'price' => 1, 'active' => true, 'available' => true, 'image' => $photo];
        $this->postAction('manage/menu', $body)->assertOk();
        $firstPath = DB::table('menu_items')->where('id', 1)->value('image_path');
        $owner = $this->registerBusiness();
        $this->get('/api/v1/menu-images/'.basename($firstPath))->assertNotFound();
        $this->postAction('manage/categories', ['name' => 'Drinks', 'sort' => 0, 'active' => true])->assertOk();
        $category = DB::table('categories')->where('business_id', $owner->business_id)->value('id');
        $this->postAction('manage/menu', [...$body, 'id' => null, 'category_id' => $category])->assertOk();
        $secondPath = DB::table('menu_items')->where('business_id', $owner->business_id)->value('image_path');
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertExists($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    public function test_expiry_and_cancellation_block_existing_sessions_from_writes_but_keep_history_and_billing(): void
    {
        $owner = $this->registerBusiness();
        DB::table('subscriptions')->where('business_id', $owner->business_id)->update(['ends_at' => now()->subSecond()]);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('writable', false)->assertJsonPath('subscription.status', 'expired');
        $this->postAction('orders', ['type' => 'takeaway'])->assertForbidden();
        $this->getJson('/api/v1/history')->assertOk();
        $this->getJson('/api/v1/billing')->assertOk();
        DB::table('subscriptions')->where('business_id', $owner->business_id)->update(['status' => 'cancelled', 'ends_at' => now()->addMonth()]);
        $this->postAction('manage/categories', ['name' => 'Blocked', 'sort' => 0, 'active' => true])->assertForbidden();
    }

    public function test_suspension_blocks_staff_and_keeps_owner_read_access(): void
    {
        $owner = $this->registerBusiness();
        DB::table('businesses')->where('id', $owner->business_id)->update(['status' => 'suspended']);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('writable', false);
        $this->getJson('/api/v1/billing')->assertOk();
        $this->postAction('orders', ['type' => 'takeaway'])->assertForbidden();
        $staff = User::factory()->create(['business_id' => $owner->business_id, 'roles' => ['waiter'], 'active' => true]);
        $this->withSession(['password_hash_web' => null])->actingAs($staff);
        $this->getJson('/api/v1/state')->assertForbidden();
    }

    public function test_business_manager_cannot_change_owner_identity_or_access(): void
    {
        $owner = $this->registerBusiness();
        $manager = User::factory()->create(['business_id' => $owner->business_id, 'roles' => ['manager'], 'active' => true]);
        $this->withSession(['password_hash_web' => null])->actingAs($manager);
        $this->postAction('manage/staff', ['id' => $owner->id, 'name' => 'Changed', 'username' => 'owner', 'email' => $owner->email, 'roles' => ['waiter'], 'active' => false])->assertUnprocessable();
        $this->assertTrue($owner->fresh()->active);
    }

    public function test_office_and_business_authentication_are_separate(): void
    {
        $owner = $this->registerBusiness();
        $this->getJson('/api/v1/office/businesses')->assertUnauthorized();
        $this->postJson('/api/v1/office/login', ['email' => $owner->email, 'password' => 'OwnerPassword123!'])->assertUnprocessable();
        $admin = $this->office();
        $this->getJson('/api/v1/office/businesses')->assertOk();
        $this->withSession(['password_hash_web' => null])->actingAs($admin, 'web');
        $this->getJson('/api/v1/state')->assertForbidden();
        $this->postJson('/api/v1/login', ['email' => $admin->email, 'password' => 'OfficePassword123!'])->assertUnprocessable();
        $admin->active = false;
        $admin->save();
        $this->getJson('/api/v1/office/businesses')->assertUnauthorized();
    }

    public function test_office_can_create_edit_and_disable_plans_without_repricing_existing_subscriptions(): void
    {
        $owner = $this->registerBusiness();
        $this->office();
        $this->postAction('office/plans', ['name' => 'Monthly', 'price' => 30000, 'currency' => 'MMK', 'billing_period' => 'month', 'active' => true])->assertOk();
        $plan = DB::table('plans')->where('is_trial', false)->first();
        $this->postAction('office/businesses/'.$owner->business_id.'/subscription', ['action' => 'renew', 'plan_id' => $plan->id, 'amount' => 30000, 'reference' => 'Transfer-1', 'reason' => 'Confirmed'])->assertOk();
        $this->postAction('office/plans', ['id' => $plan->id, 'name' => 'Monthly revised', 'price' => 40000, 'currency' => 'MMK', 'billing_period' => 'month', 'active' => false])->assertOk();
        $this->assertDatabaseHas('subscriptions', ['business_id' => $owner->business_id, 'price' => 30000, 'plan_name' => 'Monthly']);
        $this->postAction('office/businesses/'.$owner->business_id.'/subscription', ['action' => 'renew', 'plan_id' => $plan->id, 'amount' => 40000, 'reference' => 'Transfer-2', 'reason' => 'Confirmed'])->assertUnprocessable();
        $this->getJson('/api/v1/office/events')->assertOk()->assertJsonPath('total', 4);
    }

    public function test_renewals_are_idempotent_and_restore_access_without_duplicate_payments(): void
    {
        $owner = $this->registerBusiness();
        DB::table('subscriptions')->where('business_id', $owner->business_id)->update(['ends_at' => now()->subDay()]);
        $this->office();
        $plan = $this->plan();
        $key = (string) Str::uuid();
        $path = 'office/businesses/'.$owner->business_id.'/subscription';
        $body = ['action' => 'renew', 'plan_id' => $plan, 'amount' => 30000, 'reference' => 'Transfer-123', 'reason' => 'Paid'];
        $this->postAction($path, [...$body, 'amount' => 1])->assertUnprocessable();
        $first = $this->postAction($path, $body, $key)->assertOk()->json('subscription.ends_at');
        $this->postAction($path, $body, $key)->assertOk()->assertJsonPath('subscription.ends_at', $first);
        $this->postAction($path, [...$body, 'reference' => 'different'], $key)->assertConflict();
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->actingAs($owner, 'web');
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('writable', true);
        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('payments.total', 1);
    }

    public function test_office_can_extend_cancel_assign_and_update_owner_account_with_audit(): void
    {
        $owner = $this->registerBusiness();
        $admin = $this->office();
        $plan = $this->plan();
        $path = 'office/businesses/'.$owner->business_id.'/subscription';
        $this->postAction($path, ['action' => 'assign', 'plan_id' => $plan, 'reason' => 'Next renewal'])->assertOk()->assertJsonPath('subscription.status', 'trial');
        $this->postAction($path, ['action' => 'extend', 'days' => 7, 'reason' => 'Courtesy'])->assertOk();
        $this->postAction($path, ['action' => 'cancel', 'reason' => 'Requested'])->assertOk()->assertJsonPath('subscription.status', 'cancelled');
        $this->postAction('office/businesses/'.$owner->business_id, ['name' => 'Renamed', 'status' => 'suspended', 'owner_name' => 'New name', 'owner_email' => 'new@example.com', 'owner_active' => false, 'owner_password' => 'ChangedPassword123!'])->assertOk()->assertJsonPath('owner.active', false);
        $this->assertDatabaseHas('platform_events', ['actor_id' => $admin->id, 'action' => 'business_updated', 'business_id' => $owner->business_id]);
        $this->assertStringNotContainsString('ChangedPassword123!', DB::table('platform_events')->where('action', 'business_updated')->value('details'));
        $this->getJson('/api/v1/office/businesses?q=Renamed&status=suspended')->assertOk()->assertJsonPath('businesses.total', 1);
    }

    public function test_owner_password_recovery_is_token_based_and_does_not_require_verification(): void
    {
        Notification::fake();
        $owner = $this->registerBusiness();
        $this->postJson('/api/v1/forgot-password', ['email' => $owner->email])->assertOk();
        Notification::assertSentTo($owner, ResetPassword::class);
        $this->postJson('/api/v1/reset-password', ['email' => $owner->email, 'token' => 'wrong', 'password' => 'UpdatedPassword123!', 'password_confirmation' => 'UpdatedPassword123!'])->assertUnprocessable();
        $token = Password::createToken($owner);
        $this->postJson('/api/v1/reset-password', ['email' => $owner->email, 'token' => $token, 'password' => 'UpdatedPassword123!', 'password_confirmation' => 'UpdatedPassword123!'])->assertOk();
        Auth::logout();
        $this->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'UpdatedPassword123!'])->assertOk();
        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_office_admin_command_creates_no_business_membership(): void
    {
        $this->artisan('platform:admin')->expectsQuestion('Administrator name', 'Office')
            ->expectsQuestion('Email', 'setup@example.com')
            ->expectsQuestion('Password (at least 12 characters)', 'SetupPassword123!')
            ->expectsOutput('Platform administrator created. Sign in at /office.')->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'setup@example.com', 'is_platform_admin' => true, 'business_id' => null]);
    }

    public function test_expiry_notices_are_sent_once_per_period_and_not_to_disabled_owners(): void
    {
        $owner = $this->registerBusiness();
        DB::table('subscriptions')->where('business_id', $owner->business_id)->update(['ends_at' => now()->addDays(3)]);
        Mail::shouldReceive('raw')->once()->withArgs(fn (string $body, callable $callback) => str_contains($body, 'Golden-cafe') && str_contains($body, '/billing'));
        $this->artisan('platform:subscription-notices')->assertSuccessful();
        $this->artisan('platform:subscription-notices')->assertSuccessful();
        $this->assertDatabaseCount('subscription_notices', 1);
        $owner->update(['active' => false]);
        DB::table('subscriptions')->where('business_id', $owner->business_id)->update(['ends_at' => now()->addDays(4)]);
        $this->artisan('platform:subscription-notices')->assertSuccessful();
        $this->assertDatabaseCount('subscription_notices', 1);
    }

    public function test_early_paid_renewal_preserves_remaining_time_and_handles_yearly_periods(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 31)->startOfDay());
        $owner = $this->registerBusiness();
        $this->office();
        $plan = $this->plan();
        $path = 'office/businesses/'.$owner->business_id.'/subscription';
        $body = ['action' => 'renew', 'plan_id' => $plan, 'amount' => 30000, 'reference' => 'Monthly transfer', 'reason' => 'Paid'];
        $this->postAction($path, $body)->assertOk();
        $this->assertSame('2026-02-28 00:00:00', DB::table('subscriptions')->where('business_id', $owner->business_id)->value('ends_at'));
        $this->postAction($path, $body)->assertOk();
        $this->assertSame('2026-03-28 00:00:00', DB::table('subscriptions')->where('business_id', $owner->business_id)->value('ends_at'));
        DB::table('plans')->where('id', $plan)->update(['billing_period' => 'year']);
        $this->postAction($path, $body)->assertOk();
        $this->assertSame('2027-03-28 00:00:00', DB::table('subscriptions')->where('business_id', $owner->business_id)->value('ends_at'));
        $this->assertDatabaseCount('subscription_payments', 3);
    }

    public function test_paid_sales_receipts_and_history_are_isolated_between_businesses(): void
    {
        $this->seed();
        $this->actingAs(User::where('username', 'manager')->firstOrFail());
        $first = $this->postAction('orders', ['type' => 'takeaway'])->assertOk()->json();
        $first = $this->postAction('orders/'.$first['id'].'/add-item', ['version' => $first['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertOk()->json();
        $first = $this->postAction('orders/'.$first['id'].'/submit', ['version' => $first['version']])->assertOk()->json();
        $first = $this->postAction('orders/'.$first['id'].'/pay', ['version' => $first['version'], 'method' => 'cash', 'tendered' => 6500])->assertOk()->json();
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('summary.day.amount', 6500);
        $owner = $this->registerBusiness();
        $this->withSession(['password_hash_web' => null])->actingAs($owner);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('summary.day.amount', 0)->assertJsonPath('sales.total', 0);
        $this->getJson('/api/v1/orders/'.$first['id'])->assertNotFound();
        $this->postAction('manage/categories', ['name' => 'Food', 'sort' => 0, 'active' => true])->assertOk();
        $category = DB::table('categories')->where('business_id', $owner->business_id)->value('id');
        $this->postAction('manage/menu', ['category_id' => $category, 'name' => 'New business dish', 'emoji' => 'x', 'price' => 1000, 'active' => true, 'available' => true])->assertOk();
        $food = DB::table('menu_items')->where('business_id', $owner->business_id)->value('id');
        $second = $this->postAction('orders', ['type' => 'takeaway'])->assertOk()->json();
        $second = $this->postAction('orders/'.$second['id'].'/add-item', ['version' => $second['version'], 'menu_item_id' => $food, 'quantity' => 1])->assertOk()->json();
        $second = $this->postAction('orders/'.$second['id'].'/submit', ['version' => $second['version']])->assertOk()->json();
        $second = $this->postAction('orders/'.$second['id'].'/pay', ['version' => $second['version'], 'method' => 'cash', 'tendered' => 1000])->assertOk()->json();
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('summary.day.amount', 1000)->assertJsonPath('sales.total', 1);
        $this->postAction('orders/'.$second['id'].'/ready', ['version' => $second['version'], 'item_id' => $second['items'][0]['id']])->assertOk();
        $this->postAction('orders/'.$second['id'].'/collect', ['version' => $second['version'] + 1])->assertOk();
        $this->getJson('/api/v1/history')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $second['id']);
        $this->getJson('/api/v1/orders/'.$second['id'])->assertOk()->assertJsonPath('payment.restaurant_snapshot.name', 'Golden-cafe');
    }

    public function test_authentication_rate_limits_do_not_consume_the_registration_or_office_limits(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/login', ['email' => 'missing@example.com', 'password' => 'InvalidPassword123!'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', ['email' => 'missing@example.com', 'password' => 'InvalidPassword123!'])->assertTooManyRequests();
        $this->registerBusiness();
        $admin = $this->office();
        $this->postJson('/api/v1/office/login', ['email' => $admin->email, 'password' => 'OfficePassword123!'])->assertOk();
    }
}
