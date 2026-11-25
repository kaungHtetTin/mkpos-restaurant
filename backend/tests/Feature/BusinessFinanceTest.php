<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BusinessFinanceTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed();
        $this->owner = User::where('username', 'manager')->firstOrFail();
        $this->withSession(['password_hash_web' => null])->actingAs($this->owner);
        config(['realtime.enabled' => true, 'realtime.secret' => str_repeat('s', 64), 'realtime.publish_url' => 'http://realtime.test/publish']);
        Http::fake(['realtime.test/*' => Http::response('', 202)]);
    }

    private function category(array $extra = [], ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/business-finance/categories', ['action' => 'save', 'name' => 'Supplies', 'active' => true, ...$extra], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function entry(int $category, array $extra = [], ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/business-finance/entries', ['action' => 'save', 'kind' => 'income', 'category_id' => $category, 'amount' => 5000, 'description' => 'Other income', 'entry_date' => '2026-11-20', ...$extra], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_categories_have_normalized_names_versions_retry_and_safe_deletion(): void
    {
        $key = (string) Str::uuid();
        $id = $this->category(['name' => '  Food   supplies  '], $key)->assertOk()->json('id');
        $this->category(['name' => '  Food   supplies  '], $key)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('business_finance_categories', 1);
        $this->assertDatabaseHas('business_finance_categories', ['name' => 'Food supplies', 'name_key' => 'food supplies', 'business_id' => 1]);
        $this->category(['name' => 'FOOD SUPPLIES'])->assertUnprocessable();
        $this->category(['id' => $id, 'version' => 1, 'name' => 'Ingredients', 'active' => false])->assertOk();
        $this->entry($id)->assertUnprocessable();
        $this->category(['action' => 'delete', 'id' => $id, 'version' => 1])->assertConflict();
        $this->category(['action' => 'delete', 'id' => $id, 'version' => 2])->assertOk();
        $this->assertDatabaseCount('business_finance_categories', 0);
    }

    public function test_entries_are_idempotent_and_filters_totals_and_history_are_consistent(): void
    {
        $id = $this->category()->assertOk()->json('id');
        $key = (string) Str::uuid();
        $entry = $this->entry($id, [], $key)->assertOk()->json('id');
        $this->entry($id, [], $key)->assertOk();
        $this->entry($id, ['kind' => 'expense', 'amount' => 1200, 'description' => 'Rice purchase'])->assertOk();
        $this->entry($id, ['amount' => 9000, 'entry_date' => '2026-10-01'])->assertOk();
        $this->getJson('/api/v1/business-finance?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('totals.income', 5000)->assertJsonPath('totals.expense', 1200)->assertJsonPath('totals.net', 3800)->assertJsonPath('entries.total', 2);
        $this->getJson('/api/v1/business-finance?kind=expense&q=Rice')->assertOk()->assertJsonPath('entries.total', 1)->assertJsonPath('totals.net', -1200);
        $this->getJson('/api/v1/business-finance?to=2026-10-31')->assertOk()->assertJsonPath('totals.income', 9000);
        $this->getJson('/api/v1/business-finance?from=2026-11-30&to=2026-11-01')->assertUnprocessable();
        $this->category(['id' => $id, 'version' => 1, 'name' => 'Kitchen supplies', 'active' => false])->assertOk();
        $this->entry($id, ['id' => $entry, 'version' => 1, 'amount' => 6000])->assertOk();
        $this->assertDatabaseHas('business_finance_entries', ['id' => $entry, 'category_name' => 'Supplies', 'version' => 2, 'amount' => 6000]);
        $this->entry($id, ['id' => $entry, 'version' => 1])->assertConflict();
        $this->category(['action' => 'delete', 'id' => $id, 'version' => 2])->assertUnprocessable();
        $this->assertDatabaseHas('operational_events', ['action' => 'finance_entry_save']);
    }

    public function test_voids_preserve_history_are_idempotent_and_cannot_be_edited(): void
    {
        $category = $this->category()->json('id');
        $id = $this->entry($category)->assertOk()->json('id');
        $key = (string) Str::uuid();
        $this->entry($category, ['action' => 'void', 'id' => $id, 'version' => 1, 'reason' => 'Duplicate entry'], $key)->assertOk();
        $this->entry($category, ['action' => 'void', 'id' => $id, 'version' => 1, 'reason' => 'Duplicate entry'], $key)->assertOk();
        $this->getJson('/api/v1/business-finance')->assertOk()->assertJsonPath('entries.total', 0)->assertJsonPath('totals.net', 0);
        $this->getJson('/api/v1/business-finance?status=voided')->assertOk()->assertJsonPath('entries.total', 1)->assertJsonPath('entries.data.0.void_reason', 'Duplicate entry')->assertJsonPath('totals.net', 0);
        $this->entry($category, ['id' => $id, 'version' => 2])->assertUnprocessable();
        $this->category(['action' => 'delete', 'id' => $category, 'version' => 1])->assertUnprocessable();
    }

    public function test_only_the_owner_can_access_records_even_when_other_staff_are_managers(): void
    {
        $manager = User::factory()->create(['business_id' => 1, 'roles' => ['manager'], 'active' => true]);
        $this->withSession(['password_hash_web' => null])->actingAs($manager);
        $this->getJson('/api/v1/business-finance')->assertForbidden();
        $this->category()->assertForbidden();
        $this->entry(1)->assertForbidden();
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->getJson('/api/v1/business-finance')->assertForbidden();
    }

    public function test_other_business_ids_cannot_read_or_modify_categories_or_entries(): void
    {
        $category = $this->category()->json('id');
        $entry = $this->entry($category)->json('id');
        $business = DB::table('businesses')->insertGetId(['name' => 'Other', 'code' => 'other-finance', 'setup_completed_at' => now()]);
        $owner = User::factory()->create(['business_id' => $business, 'roles' => ['manager'], 'active' => true]);
        DB::table('businesses')->where('id', $business)->update(['owner_id' => $owner->id]);
        DB::table('restaurant_settings')->insert(['business_id' => $business, 'name' => 'Other']);
        DB::table('subscriptions')->insert(['business_id' => $business, 'plan_id' => DB::table('plans')->value('id'), 'plan_name' => 'Trial', 'price' => 0, 'currency' => 'MMK', 'billing_period' => 'month', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->withSession(['password_hash_web' => null])->actingAs($owner);
        $this->getJson('/api/v1/business-finance')->assertOk()->assertJsonPath('entries.total', 0)->assertJsonCount(0, 'categories');
        $this->category(['id' => $category, 'version' => 1])->assertNotFound();
        $this->entry($category)->assertUnprocessable();
        $otherCategory = $this->category()->assertOk()->json('id');
        $this->entry($otherCategory, ['id' => $entry, 'version' => 1])->assertNotFound();
        $this->getJson('/api/v1/business-finance?category_id='.$category)->assertUnprocessable();
    }

    public function test_invalid_values_readonly_access_and_currency_changes_are_rejected(): void
    {
        $id = $this->category()->json('id');
        foreach ([0, -1, 1.5, 1000000001] as $amount) {
            $this->entry($id, ['amount' => $amount])->assertUnprocessable();
        }
        $this->entry($id, ['entry_date' => '2026-02-30'])->assertUnprocessable();
        $this->entry($id)->assertOk();
        $settings = (array) DB::table('restaurant_settings')->where('business_id', 1)->first();
        $this->postJson('/api/v1/manage/settings', [...$settings, 'currency' => 'USD'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        DB::table('subscriptions')->where('business_id', 1)->update(['ends_at' => now()->subDay()]);
        $this->entry($id)->assertForbidden();
        $this->category(['name' => 'New'])->assertForbidden();
        $this->getJson('/api/v1/business-finance')->assertOk();
    }

    public function test_finance_events_only_target_the_owner_channel_without_refreshing_operations(): void
    {
        $this->category()->assertOk();
        Http::assertSent(fn ($request) => $request['channels'] === ['finance:1'] && $request['topic'] === 'finance' && ! str_contains($request->body(), 'Supplies'));
        $claims = function (): array {
            [$payload] = explode('.', $this->getJson('/api/v1/realtime/business')->assertOk()->json('token'));

            return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        };
        $this->assertContains('finance:1', $claims()['channels']);
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->assertNotContains('finance:1', $claims()['channels']);
    }
}
