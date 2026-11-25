<?php

namespace Tests\Feature;

use App\Models\PlatformLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OfficeFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private int $plan;

    private int $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('username', 'manager')->firstOrFail();
        $this->admin = User::factory()->create(['business_id' => null, 'roles' => [], 'active' => true]);
        $this->admin->forceFill(['is_platform_admin' => true, 'office_role' => 'super_admin'])->save();
        $this->plan = DB::table('plans')->insertGetId(['name' => 'Monthly', 'price' => 30000, 'currency' => 'MMK', 'billing_period' => 'month', 'created_at' => now(), 'updated_at' => now()]);
        $this->method = DB::table('office_payment_methods')->insertGetId(['name' => 'Bank transfer', 'currency' => 'MMK', 'instructions' => 'Send to test bank account']);
        $this->admin = $this->admin->fresh();
        $this->office();
    }

    private function office(?User $admin = null): void
    {
        $this->withSession(['office_version' => ($admin ?? $this->admin)->fresh()->office_version])->actingAs($admin ?? $this->admin, 'office');
        Auth::shouldUse('web');
    }

    private function business(?User $user = null): void
    {
        Auth::guard('office')->logout();
        $this->withSession(['password_hash_web' => null])->actingAs($user ?? $this->owner, 'web');
    }

    private function action(string $path, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/'.$path, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function paymentRequest(string $reference = 'BANK-123'): int
    {
        $this->business();

        return $this->action('billing/requests', ['plan_id' => $this->plan, 'method_id' => $this->method, 'amount' => 30000, 'reference' => $reference, 'confirmed' => true])->assertOk()->json('id');
    }

    private function book(string $name, bool $default = false, string $currency = 'MMK'): int
    {
        $this->action('office/finance', ['action' => 'book', 'name' => $name, 'currency' => $currency, 'active' => true, 'is_default' => $default])->assertOk();

        return (int) DB::table('office_bank_books')->where('name', $name)->value('id');
    }

    private function entry(int $book, string $kind, int $amount, array $extra = []): TestResponse
    {
        return $this->action('office/finance', ['action' => 'entry', 'book_id' => $book, 'kind' => $kind, 'amount' => $amount, 'currency' => 'MMK', 'entry_date' => now('Asia/Yangon')->toDateString(), 'description' => 'Test '.$kind, ...($kind === 'allocation' ? ['settings_version' => (int) DB::table('office_settings')->where('id', 1)->value('version')] : []), ...$extra]);
    }

    public function test_payment_approval_renews_once_and_receipts_retain_their_receiving_book(): void
    {
        DB::table('subscriptions')->where('business_id', 1)->update(['status' => 'expired', 'ends_at' => now()->subDay()]);
        $request = $this->paymentRequest();
        $this->assertDatabaseCount('subscription_payments', 0);
        $this->assertDatabaseHas('subscriptions', ['business_id' => 1, 'status' => 'expired']);
        $this->office();
        $first = $this->book('Primary bank', true);
        $second = $this->book('Other bank');
        $key = (string) Str::uuid();
        $payload = ['action' => 'approve', 'version' => 1, 'confirmed' => true, 'reason' => 'Confirmed at bank', 'book_id' => $first];
        $this->action('office/requests/'.$request.'/review', $payload, $key)->assertOk();
        $this->action('office/requests/'.$request.'/review', $payload, $key)->assertOk();
        $this->action('office/requests/'.$request.'/review', $payload)->assertStatus(409);
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseCount('office_ledger', 1);
        $this->assertSame(30000, PlatformLedger::balance($first));
        $this->assertDatabaseHas('subscriptions', ['business_id' => 1, 'status' => 'active']);
        $this->action('office/finance', ['action' => 'book', 'id' => $second, 'version' => 1, 'name' => 'Other bank', 'currency' => 'MMK', 'active' => true, 'is_default' => true])->assertOk();
        $this->assertDatabaseHas('office_ledger', ['book_id' => $first, 'amount' => 30000]);
        $this->action('office/businesses/1/subscription', ['action' => 'renew', 'plan_id' => $this->plan, 'amount' => 30000, 'reference' => 'DIRECT-PAYMENT', 'reason' => 'Confirmed receipt', 'book_id' => $first])->assertOk();
        $this->assertDatabaseHas('office_ledger', ['book_id' => $first, 'reference' => 'DIRECT-PAYMENT', 'amount' => 30000]);
        $this->getJson('/api/v1/office/payments?q=BANK-123')->assertOk()->assertJsonPath('payments.data.0.book_name', 'Primary bank');
        $this->business();
        $this->getJson('/api/v1/billing/requests')->assertOk()->assertJsonPath('requests.0.status', 'approved');
        $this->action('billing/requests', ['plan_id' => $this->plan, 'method_id' => $this->method, 'amount' => 30000, 'reference' => 'BANK-123', 'confirmed' => true])->assertStatus(422);
    }

    public function test_payment_requests_without_references_can_be_submitted_and_approved_on_later_renewals(): void
    {
        foreach ([[], ['reference' => ''], ['reference' => null], ['reference' => '   ']] as $reference) {
            $this->business();
            $id = $this->action('billing/requests', ['plan_id' => $this->plan, 'method_id' => $this->method, 'amount' => 30000, 'confirmed' => true, ...$reference])->assertOk()->json('id');
            $this->assertDatabaseHas('subscription_requests', ['id' => $id, 'reference' => '', 'status' => 'pending']);
            $this->assertDatabaseMissing('subscription_payments', ['request_key' => 'approval-'.$id]);
            $this->action('billing/requests', ['plan_id' => $this->plan, 'method_id' => $this->method, 'amount' => 30000, 'confirmed' => true])->assertStatus(422);
            $this->office();
            $payload = ['action' => 'approve', 'version' => 1, 'confirmed' => true, 'reason' => 'Verified external payment without a reference'];
            $this->action('office/requests/'.$id.'/review', $payload)->assertOk();
            $this->action('office/requests/'.$id.'/review', $payload)->assertStatus(409);
            $this->assertDatabaseHas('subscription_payments', ['request_key' => 'approval-'.$id, 'reference' => '']);
        }
        $this->assertDatabaseCount('subscription_payments', 4);
        $this->assertDatabaseCount('office_ledger', 4);
    }

    public function test_optional_payment_references_still_validate_length_and_type(): void
    {
        $this->business();
        foreach ([str_repeat('A', 151), ['invalid']] as $reference) {
            $this->action('billing/requests', ['plan_id' => $this->plan, 'method_id' => $this->method, 'amount' => 30000, 'confirmed' => true, 'reference' => $reference])->assertUnprocessable()->assertJsonValidationErrors('reference');
        }
        $this->assertDatabaseCount('subscription_requests', 0);
    }

    public function test_plan_changes_unconfirmed_payments_and_duplicate_pending_requests_are_rejected(): void
    {
        $request = $this->paymentRequest();
        $this->action('billing/requests', ['plan_id' => $this->plan, 'method_id' => $this->method, 'amount' => 30000, 'reference' => 'ANOTHER', 'confirmed' => true])->assertStatus(422);
        $this->office();
        $this->action('office/requests/'.$request.'/review', ['action' => 'approve', 'version' => 1, 'reason' => 'Not checked'])->assertStatus(422);
        DB::table('plans')->where('id', $this->plan)->update(['price' => 35000]);
        $this->action('office/requests/'.$request.'/review', ['action' => 'approve', 'version' => 1, 'confirmed' => true, 'reason' => 'Changed price'])->assertStatus(409);
        $this->action('office/requests/'.$request.'/review', ['action' => 'reject', 'version' => 1, 'reason' => 'Please resubmit for the current plan'])->assertOk();
        $this->assertDatabaseCount('office_ledger', 0);
        $this->assertDatabaseCount('subscription_payments', 0);
        $this->business();
        $this->getJson('/api/v1/billing/requests')->assertOk()->assertJsonPath('requests.0.review_reason', 'Please resubmit for the current plan');
    }

    public function test_financial_books_keep_transfers_capital_and_opening_adjustments_out_of_profit(): void
    {
        $one = $this->book('Operating bank', true);
        $two = $this->book('Reserve');
        $this->entry($one, 'opening', 1000)->assertOk();
        $this->entry($one, 'expense', 100)->assertOk();
        $this->entry($one, 'contribution', 500)->assertOk();
        $this->entry($one, 'transfer', 400, ['to_book_id' => $two])->assertOk();
        $this->assertSame(1000, PlatformLedger::balance($one));
        $this->assertSame(400, PlatformLedger::balance($two));
        $this->assertSame(-100, PlatformLedger::profit('MMK')['profit']);
        $this->entry($one, 'expense', 1001)->assertStatus(422);
        $usd = $this->book('USD book', false, 'USD');
        $this->entry($one, 'transfer', 10, ['to_book_id' => $usd])->assertStatus(422);
        $this->entry($one, 'income', 1000)->assertOk();
        $this->action('office/finance', ['action' => 'founders', 'settings_version' => 1, 'founders' => [['name' => 'One', 'share_bps' => 6000], ['name' => 'Two', 'share_bps' => 4000]]])->assertOk();
        $this->entry($one, 'allocation', 901)->assertStatus(422);
        $this->entry($one, 'allocation', 899)->assertOk();
        $allocation = DB::table('office_ledger')->where('kind', 'allocation')->first();
        $snapshot = json_decode($allocation->allocation_snapshot, true);
        $this->assertSame([539, 360], array_column($snapshot, 'amount'));
        $this->assertSame(1, PlatformLedger::profit('MMK')['available_profit']);
        $this->assertSame(1101, PlatformLedger::balance($one));
        $this->action('office/finance', ['action' => 'founders', 'settings_version' => 2, 'founders' => [['name' => 'New founder', 'share_bps' => 10000]]])->assertOk();
        $this->assertSame('One', json_decode(DB::table('office_ledger')->find($allocation->id)->allocation_snapshot, true)[0]['name']);
        $this->assertDatabaseHas('office_founders', ['name' => 'One', 'active' => false]);
    }

    public function test_founder_allocations_reject_stale_previews_and_preserve_retry_and_void_behavior(): void
    {
        $book = $this->book('Founder payouts', true);
        $this->entry($book, 'income', 1010)->assertOk();
        $this->action('office/finance', ['action' => 'founders', 'settings_version' => 1, 'founders' => [['name' => 'First', 'share_bps' => 5000], ['name' => 'Second', 'share_bps' => 5000]]])->assertOk();
        $emptyBook = $this->book('Unfunded payout book');
        $this->entry($emptyBook, 'allocation', 1)->assertUnprocessable();
        $foreignBook = $this->book('USD payout book', false, 'USD');
        $this->entry($foreignBook, 'allocation', 1)->assertUnprocessable();
        $this->entry($book, 'allocation', 101, ['settings_version' => 1])->assertStatus(409);
        $this->entry($book, 'allocation', 101, ['settings_version' => null])->assertUnprocessable()->assertJsonValidationErrors('settings_version');
        $this->assertDatabaseCount('office_ledger', 1);
        $key = (string) Str::uuid();
        $payload = ['action' => 'entry', 'kind' => 'allocation', 'currency' => 'MMK', 'book_id' => $book, 'amount' => 101, 'settings_version' => 2, 'entry_date' => now('Asia/Yangon')->toDateString(), 'description' => 'Founder payout'];
        $this->action('office/finance', $payload, $key)->assertOk();
        $this->action('office/finance', $payload, $key)->assertOk();
        $this->assertDatabaseCount('office_ledger', 2);
        $entry = DB::table('office_ledger')->where('kind', 'allocation')->first();
        $this->assertSame([51, 50], array_column(json_decode($entry->allocation_snapshot, true), 'amount'));
        $this->assertSame(909, PlatformLedger::balance($book));
        $this->getJson('/api/v1/office/finance?kind=allocation')->assertOk()->assertJsonPath('entries.total', 1)->assertJsonPath('settings_version', 2);
        $this->action('office/finance', ['action' => 'void', 'id' => $entry->id, 'reason' => 'Payout was not completed'])->assertOk();
        $this->assertSame(1010, PlatformLedger::balance($book));
        $this->assertSame(1010, PlatformLedger::profit('MMK')['available_profit']);
        $this->assertDatabaseHas('office_ledger', ['id' => $entry->id, 'allocation_snapshot' => $entry->allocation_snapshot]);
    }

    public function test_voids_are_audited_and_cannot_overdraw_books_or_rewrite_subscription_receipts(): void
    {
        $one = $this->book('Bank', true);
        $two = $this->book('Savings');
        $this->entry($one, 'opening', 1000)->assertOk();
        $opening = DB::table('office_ledger')->value('id');
        $this->entry($one, 'transfer', 800, ['to_book_id' => $two])->assertOk();
        $transfer = DB::table('office_ledger')->where('kind', 'transfer')->value('id');
        $this->action('office/finance', ['action' => 'void', 'id' => $opening, 'reason' => 'Cannot reverse spent funds'])->assertStatus(422);
        $this->action('office/finance', ['action' => 'void', 'id' => $transfer, 'reason' => 'Wrong destination'])->assertOk();
        $this->assertSame(1000, PlatformLedger::balance($one));
        $this->assertSame(0, PlatformLedger::balance($two));
        $this->action('office/finance', ['action' => 'void', 'id' => $transfer, 'reason' => 'Duplicate void'])->assertStatus(422);
        $request = $this->paymentRequest();
        $this->office();
        $this->action('office/requests/'.$request.'/review', ['action' => 'approve', 'version' => 1, 'confirmed' => true, 'reason' => 'Confirmed'])->assertOk();
        $receipt = DB::table('office_ledger')->where('kind', 'subscription')->value('id');
        $this->action('office/finance', ['action' => 'void', 'id' => $receipt, 'reason' => 'Forbidden'])->assertStatus(422);
    }

    public function test_administrator_roles_self_protection_and_session_revocation(): void
    {
        $this->action('office/manage/admins', ['version' => 1, 'name' => 'Operations', 'email' => 'ops@example.test', 'office_role' => 'admin', 'active' => true, 'password' => 'six123', 'password_confirmation' => 'six123'])->assertOk();
        $operations = User::where('email', 'ops@example.test')->firstOrFail();
        $this->action('office/manage/admins', ['id' => $this->admin->id, 'version' => 1, 'name' => 'Self', 'email' => $this->admin->email, 'office_role' => 'admin', 'active' => false])->assertStatus(422);
        $this->office($operations);
        $this->getJson('/api/v1/office/overview')->assertOk();
        $this->getJson('/api/v1/office/health')->assertForbidden();
        $this->getJson('/api/v1/office/manage/admins')->assertForbidden();
        $this->action('office/manage/settings', [])->assertForbidden();
        $this->action('office/finance', ['action' => 'founders', 'settings_version' => 1, 'founders' => [['name' => 'No', 'share_bps' => 10000]]])->assertForbidden();
        DB::table('users')->where('id', $operations->id)->increment('office_version');
        Auth::guard('office')->setUser($operations->fresh());
        $this->getJson('/api/v1/office/overview')->assertUnauthorized();
        $this->business();
        $this->getJson('/api/v1/office/overview')->assertUnauthorized();
    }

    public function test_help_reports_are_scoped_and_private_notes_do_not_leak_to_business_accounts(): void
    {
        $this->action('office/manage/content', ['version' => 1, 'kind' => 'tutorial', 'title' => 'Start service', 'body' => 'Choose a table.', 'audience' => 'all', 'published' => true, 'sort' => 0])->assertOk();
        $this->action('office/manage/content', ['version' => 1, 'kind' => 'announcement', 'title' => 'Private draft', 'body' => 'Draft.', 'audience' => 'all', 'published' => false, 'sort' => 0])->assertOk();
        $this->business();
        $this->getJson('/api/v1/help')->assertOk()->assertJsonCount(1, 'content')->assertJsonPath('content.0.title', 'Start service');
        $id = $this->action('help/reports', ['title' => 'Printer issue', 'description' => 'Steps to reproduce', 'priority' => 'high'])->assertOk()->json('id');
        $this->office();
        $this->action('office/manage/reports', ['id' => $id, 'version' => 1, 'status' => 'resolved', 'priority' => 'high', 'resolution' => 'Choose the correct paper width.', 'internal_note' => 'Private diagnostic'])->assertOk();
        $this->action('office/manage/reports', ['id' => $id, 'version' => 1, 'status' => 'closed', 'priority' => 'low'])->assertStatus(409);
        $this->business();
        $this->getJson('/api/v1/help')->assertOk()->assertJsonPath('reports.0.resolution', 'Choose the correct paper width.')->assertJsonMissingPath('reports.0.internal_note');
        $this->business(User::where('username', 'waiter')->firstOrFail());
        $this->getJson('/api/v1/help')->assertOk()->assertJsonCount(0, 'reports');
        $this->getJson('/api/v1/billing/requests')->assertForbidden();
    }

    public function test_private_plans_and_unsafe_settings_are_not_exposed_and_calendar_uses_office_timezone(): void
    {
        DB::table('plans')->where('id', $this->plan)->update(['is_public' => false]);
        $this->business();
        $this->getJson('/api/v1/billing/requests')->assertOk()->assertJsonCount(0, 'plans');
        $this->action('billing/requests', ['plan_id' => $this->plan, 'method_id' => $this->method, 'amount' => 30000, 'reference' => 'PRIVATE', 'confirmed' => true])->assertStatus(422);
        $this->office();
        $this->action('office/manage/settings', ['version' => 1, 'name' => 'Platform', 'timezone' => 'Asia/Yangon', 'theme_color' => '#112233', 'support_url' => 'javascript:alert(1)'])->assertStatus(422);
        DB::table('subscriptions')->where('business_id', 1)->update(['ends_at' => '2026-11-30 20:00:00']);
        $this->getJson('/api/v1/office/calendar?month=2026-12&day=2026-12-01')->assertOk()->assertJsonPath('days.2026-12-01', 1)->assertJsonCount(1, 'businesses.data');
        $this->getJson('/api/v1/office/manage/admins?per_page=999')->assertStatus(422);

    }

    public function test_office_onboarding_requires_owner_setup_and_is_idempotent_without_sending_mail(): void
    {
        $key = (string) Str::uuid();
        $payload = ['name' => 'New Restaurant', 'owner_name' => 'New Owner', 'owner_email' => 'new-owner@example.test', 'password' => 'six123', 'password_confirmation' => 'six123'];
        $id = $this->action('office/businesses', $payload, $key)->assertOk()->json('id');
        $this->action('office/businesses', $payload, $key)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseHas('businesses', ['id' => $id, 'code' => 'new-restaurant', 'setup_completed_at' => null]);
        $owner = User::where('email', 'new-owner@example.test')->firstOrFail();
        $this->assertSame(['manager'], $owner->roles);
        $this->business($owner);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('business.setup_completed_at', null);
        $this->action('orders', ['type' => 'takeaway'])->assertForbidden();
    }

    public function test_an_administrator_can_update_their_own_account_without_revoking_the_current_session(): void
    {
        $operations = User::factory()->create(['business_id' => null, 'roles' => [], 'active' => true, 'password' => 'Original123!']);
        $operations->forceFill(['is_platform_admin' => true, 'office_role' => 'admin'])->save();
        $operations->refresh();
        $this->office($operations);
        $this->action('office/account', ['name' => 'Updated', 'email' => $operations->email, 'current_password' => 'Wrong'])->assertStatus(422);
        $this->action('office/account', ['name' => 'Updated', 'email' => $operations->email, 'current_password' => 'Original123!', 'password' => 'six123', 'password_confirmation' => 'six123'])->assertOk()->assertJsonMissingPath('user.password');
        $this->assertTrue(Hash::check('six123', $operations->fresh()->password));
        $this->getJson('/api/v1/office/overview')->assertOk();
        $this->assertDatabaseHas('platform_events', ['actor_id' => $operations->id, 'action' => 'office_account_updated']);
    }

    public function test_uploaded_payment_proof_and_support_screenshots_are_private_while_the_logo_is_public(): void
    {
        Storage::fake('local');
        $this->business();
        $id = $this->action('billing/requests', ['plan_id' => $this->plan, 'method_id' => $this->method, 'amount' => 30000, 'reference' => 'IMAGE-PAYMENT', 'confirmed' => true, 'receipt' => UploadedFile::fake()->image('receipt.png')])->assertOk()->json('id');
        $report = $this->action('help/reports', ['title' => 'Screenshot', 'description' => 'Reproduction steps', 'priority' => 'normal', 'screenshot' => UploadedFile::fake()->image('screen.png')])->assertOk()->json('id');
        $this->get('/api/v1/office/requests/'.$id.'/receipt', ['Accept' => 'application/json'])->assertUnauthorized();
        $this->get('/api/v1/office/reports/'.$report.'/screenshot', ['Accept' => 'application/json'])->assertUnauthorized();
        $this->office();
        $this->get('/api/v1/office/requests/'.$id.'/receipt')->assertOk();
        $this->get('/api/v1/office/reports/'.$report.'/screenshot')->assertOk();
        $this->action('office/manage/settings', ['version' => 1, 'name' => 'Restaurant Platform', 'timezone' => 'Asia/Yangon', 'theme_color' => '#aabbcc', 'image' => UploadedFile::fake()->image('logo.png')])->assertOk();
        $this->business();
        $this->get('/api/v1/platform-logo')->assertOk();
        $this->getJson('/api/v1/help')->assertOk()->assertJsonMissingPath('settings.logo_path');
    }
}
