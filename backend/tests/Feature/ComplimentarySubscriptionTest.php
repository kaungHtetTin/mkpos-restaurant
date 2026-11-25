<?php

namespace Tests\Feature;

use App\Models\BusinessData;
use App\Models\PlatformLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ComplimentarySubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private int $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(CarbonImmutable::parse('2026-01-31 12:00:00'));
        $this->plan = DB::table('plans')->insertGetId(['name' => 'FOC Test Monthly', 'price' => 30000, 'currency' => 'MMK', 'billing_period' => 'month', 'active' => true, 'is_trial' => false]);
        $admin = User::factory()->create(['business_id' => null, 'roles' => [], 'active' => true]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin, 'office');
        Auth::shouldUse('web');
    }

    private function grant(array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/office/businesses/1/subscription', array_replace(['action' => 'foc', 'plan_id' => $this->plan, 'reason' => 'Partner complimentary access', 'confirmed' => true], $overrides), ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_grant_restores_expired_access_without_creating_financial_records(): void
    {
        DB::table('subscriptions')->where('business_id', 1)->update(['status' => 'expired', 'ends_at' => now()->subDay()]);
        $payments = DB::table('subscription_payments')->count();
        $ledger = DB::table('office_ledger')->count();
        $response = $this->grant()->assertOk()->assertJsonPath('subscription.is_complimentary', true)->assertJsonPath('subscription.price', 0)->assertJsonPath('subscription.status', 'active')->assertJsonPath('subscription.plan_name', 'FOC Test Monthly');
        $this->assertSame('2026-02-28 12:00:00', $response->json('subscription.ends_at'));
        $this->assertTrue(BusinessData::writable(1));
        $this->assertDatabaseCount('subscription_payments', $payments);
        $this->assertDatabaseCount('office_ledger', $ledger);
        $this->assertDatabaseHas('platform_events', ['business_id' => 1, 'action' => 'subscription_foc']);
        $event = json_decode(DB::table('platform_events')->where('action', 'subscription_foc')->value('details'), true);
        $this->assertSame('Partner complimentary access', $event['request']['reason']);
        $this->assertSame('expired', $event['before']['status']);
        $this->getJson('/api/v1/office/businesses')->assertOk()->assertJsonPath('summary.complimentary', 1)->assertJsonPath('summary.active', 0)->assertJsonPath('businesses.data.0.is_complimentary', 1);
        $this->getJson('/api/v1/office/overview')->assertOk()->assertJsonPath('summary.complimentary', 1)->assertJsonPath('summary.paid', 0);
    }

    public function test_grants_preserve_active_and_trial_time_and_yearly_leap_dates(): void
    {
        foreach (['active', 'trial'] as $status) {
            DB::table('subscriptions')->where('business_id', 1)->update(['status' => $status, 'ends_at' => '2026-03-31 12:00:00']);
            $this->grant()->assertOk()->assertJsonPath('subscription.ends_at', '2026-04-30 12:00:00');
        }
        $this->travelTo(CarbonImmutable::parse('2028-02-29 12:00:00'));
        DB::table('plans')->where('id', $this->plan)->update(['billing_period' => 'year']);
        DB::table('subscriptions')->where('business_id', 1)->update(['status' => 'cancelled', 'ends_at' => '2029-01-01 00:00:00']);
        $this->grant()->assertOk()->assertJsonPath('subscription.ends_at', '2029-02-28 12:00:00');
    }

    public function test_grant_requires_confirmation_reason_and_an_active_non_trial_plan(): void
    {
        $before = (array) DB::table('subscriptions')->where('business_id', 1)->first();
        foreach ([['confirmed' => false], ['confirmed' => null], ['reason' => ''], ['plan_id' => null], ['amount' => 30000], ['reference' => 'payment'], ['book_id' => 1]] as $invalid) {
            $this->grant($invalid)->assertUnprocessable();
        }
        DB::table('plans')->where('id', $this->plan)->update(['active' => false]);
        $this->grant()->assertUnprocessable();
        DB::table('plans')->where('id', $this->plan)->update(['active' => true, 'is_trial' => true]);
        $this->grant()->assertUnprocessable();
        $this->assertSame($before, (array) DB::table('subscriptions')->where('business_id', 1)->first());
        $this->assertDatabaseMissing('platform_events', ['action' => 'subscription_foc']);
    }

    public function test_grant_is_idempotent_and_keeps_business_suspension(): void
    {
        DB::table('businesses')->where('id', 1)->update(['status' => 'suspended']);
        $key = (string) Str::uuid();
        $end = $this->grant(key: $key)->assertOk()->json('subscription.ends_at');
        $this->grant(key: $key)->assertOk()->assertJsonPath('subscription.ends_at', $end);
        $this->grant(['reason' => 'Changed'], $key)->assertConflict();
        $this->assertSame(1, DB::table('platform_events')->where('action', 'subscription_foc')->count());
        $this->assertFalse(BusinessData::writable(1));
        $this->assertDatabaseHas('businesses', ['id' => 1, 'status' => 'suspended']);
    }

    public function test_paid_renewals_clear_foc_and_plan_assignment_keeps_free_price(): void
    {
        $this->grant()->assertOk();
        $this->grant(['action' => 'assign'])->assertOk()->assertJsonPath('subscription.is_complimentary', true)->assertJsonPath('subscription.price', 0);
        $this->grant(['action' => 'renew', 'amount' => 30000, 'reference' => 'paid-after-foc'])->assertOk()->assertJsonPath('subscription.is_complimentary', false)->assertJsonPath('subscription.price', 30000);
        $this->grant()->assertOk();
        DB::transaction(fn () => PlatformLedger::renew(1, DB::table('plans')->find($this->plan), 'approved-after-foc', (string) Str::uuid()));
        $this->assertFalse(BusinessData::subscription(1)->is_complimentary);
        $this->assertSame(30000, BusinessData::subscription(1)->price);
    }

    public function test_business_owners_cannot_grant_foc(): void
    {
        Auth::guard('office')->logout();
        $this->actingAs(User::where('business_id', 1)->first(), 'web');
        $this->grant()->assertUnauthorized();
    }
}
