<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Realtime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed();
        config(['realtime.enabled' => true, 'realtime.secret' => str_repeat('s', 64), 'realtime.publish_url' => 'http://realtime.test/publish']);
        Http::fake(['realtime.test/*' => Http::response('', 202)]);
    }

    private function owner(): User
    {
        $owner = User::where('business_id', 1)->where('username', 'manager')->firstOrFail();
        $this->actingAs($owner);

        return $owner;
    }

    public function test_signed_credentials_are_scoped_to_the_authenticated_business(): void
    {
        $this->getJson('/api/v1/realtime/business')->assertUnauthorized();
        $owner = $this->owner();
        $response = $this->getJson('/api/v1/realtime/business')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        [$payload, $signature] = explode('.', $response->json('token'));
        $this->assertSame(hash_hmac('sha256', $payload, config('realtime.secret')), $signature);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertContains('business:1', $claims['channels']);
        $this->assertContains('user:'.$owner->id, $claims['channels']);
        $this->assertNotContains('office', $claims['channels']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->getJson('/api/v1/office/realtime')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_committed_operation_publishes_signed_invalidation_without_order_details(): void
    {
        $this->owner();
        $key = (string) Str::uuid();
        $this->postJson('/api/v1/orders', ['type' => 'takeaway', 'customer_name' => 'Private Customer'], ['Idempotency-Key' => $key])->assertOk();
        Http::assertSent(function ($request) use ($key): bool {
            $body = $request->body();
            $event = json_decode($body, true);
            $this->assertDatabaseHas('orders', ['business_id' => 1, 'customer_name' => 'Private Customer']);
            $this->assertStringNotContainsString('Private Customer', $body);
            $this->assertSame(['business:1'], $event['channels']);
            $this->assertSame($key, $event['request_key']);
            $timestamp = $request->header('X-Realtime-Timestamp')[0];

            return hash_hmac('sha256', $timestamp.'.'.$body, config('realtime.secret')) === $request->header('X-Realtime-Signature')[0];
        });
    }

    public function test_invalid_actions_and_reads_do_not_publish_and_rollback_drops_callbacks(): void
    {
        $this->owner();
        $this->getJson('/api/v1/state')->assertOk();
        $this->postJson('/api/v1/orders', ['type' => 'invalid'])->assertUnprocessable();
        DB::beginTransaction();
        DB::afterCommit(fn () => Realtime::publish(['business:1'], 'business'));
        DB::rollBack();
        Http::assertNothingSent();
    }

    public function test_office_subscription_grant_notifies_only_the_affected_business(): void
    {
        $admin = User::factory()->create(['business_id' => null, 'roles' => [], 'active' => true]);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin, 'office');
        Auth::shouldUse('web');
        $plan = DB::table('plans')->insertGetId(['name' => 'Monthly', 'price' => 30000, 'currency' => 'MMK', 'billing_period' => 'month', 'active' => true, 'is_trial' => false]);
        $this->postJson('/api/v1/office/businesses/1/subscription', ['action' => 'foc', 'plan_id' => $plan, 'reason' => 'Partner', 'confirmed' => true], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        Http::assertSent(fn ($request) => $request['channels'] === ['office', 'business:1', 'menu:1']);
        $this->getJson('/api/v1/office/realtime')->assertOk();
    }

    public function test_guest_request_credentials_cannot_cross_businesses(): void
    {
        $link = DB::table('guest_menu_links')->insertGetId(['business_id' => 1, 'token' => 'test-menu-token', 'created_at' => now()]);
        $this->getJson('/api/v1/realtime/guest?menu_token=test-menu-token&request_token=other-guest-secret')->assertNotFound();
        $response = $this->getJson('/api/v1/realtime/guest?menu_token=test-menu-token')->assertOk();
        [$payload] = explode('.', $response->json('token'));
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertContains('menu:1', $claims['channels']);
        $this->assertNotContains('business:1', $claims['channels']);
        $this->assertNotContains('office', $claims['channels']);
    }

    public function test_broker_failure_does_not_undo_an_order(): void
    {
        Http::fake(['realtime.test/*' => Http::response('', 503)]);
        $this->owner();
        $this->postJson('/api/v1/orders', ['type' => 'takeaway'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('orders', ['business_id' => 1, 'type' => 'takeaway']);
    }
}
