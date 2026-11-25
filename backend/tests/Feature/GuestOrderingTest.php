<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GuestOrderingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->manager = User::where('username', 'manager')->firstOrFail();
        $this->actingAs($this->manager);
        $this->action('guest-orders/configure', ['action' => 'settings', 'guest_menu_enabled' => true, 'guest_ordering_enabled' => true])->assertOk();
        $this->token = $this->action('guest-orders/configure', ['action' => 'link', 'table_id' => 1])->assertOk()->json('token');
    }

    private function action(string $path, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/'.$path, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function payload(int $quantity = 1): array
    {
        $menu = $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->json();

        return ['guest_name' => 'Guest', 'items' => [['menu_item_id' => 1, 'price' => 6500, 'quantity' => $quantity, 'options_version' => 1, 'addon_ids' => [], 'notes' => 'No onion']], 'charges' => $menu['charges']];
    }

    private function submit(int $quantity = 1): array
    {
        return $this->action('guest/menu/'.$this->token.'/requests', $this->payload($quantity))->assertCreated()->json();
    }

    private function confirmedTableOrder(): array
    {
        $order = $this->action('orders', ['type' => 'dine_in', 'table_id' => 1])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertOk()->json();

        return $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk()->json();
    }

    public function test_guest_bill_request_notifies_staff_once_without_payment_or_releasing_table(): void
    {
        $order = $this->confirmedTableOrder();
        $this->app['auth']->forgetGuards();

        foreach (range(1, 2) as $attempt) {
            $this->postJson('/api/v1/guest/menu/'.$this->token.'/bill', ['order_number' => $order['number'], 'table_id' => 2])
                ->assertOk()->assertJsonPath('bill_requested', true);
        }

        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'bill_requested' => true, 'version' => $order['version'] + 1, 'paid' => false, 'status' => 'open']);
        $this->assertSame(1, DB::table('operational_events')->where('order_id', $order['id'])->where('action', 'guest-bill-request')->whereNull('user_id')->count());
        $this->assertDatabaseHas('order_tables', ['table_id' => 1, 'order_id' => $order['id']]);
        $this->assertDatabaseCount('payments', 0);
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->assertJsonPath('table_order.bill_requested', true);
        $this->withSession(['password_hash_web' => null])->actingAs($this->manager);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('orders.0.bill_requested', true);
    }

    public function test_guest_bill_request_cannot_target_other_or_stale_table_orders(): void
    {
        $order = $this->confirmedTableOrder();
        $otherToken = $this->action('guest-orders/configure', ['action' => 'link', 'table_id' => 2])->assertOk()->json('token');
        $generalToken = $this->action('guest-orders/configure', ['action' => 'link'])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/guest/menu/'.$otherToken.'/bill', ['order_number' => $order['number']])->assertConflict();
        $this->postJson('/api/v1/guest/menu/'.$generalToken.'/bill', ['order_number' => $order['number']])->assertUnprocessable();
        $this->postJson('/api/v1/guest/menu/'.$this->token.'/bill', ['order_number' => 'OLD-ORDER'])->assertConflict();
        $this->postJson('/api/v1/guest/menu/'.$this->token.'/bill', [])->assertUnprocessable();
        $this->assertDatabaseMissing('orders', ['bill_requested' => true]);
        DB::table('orders')->where('id', $order['id'])->update(['status' => 'closed']);
        $this->postJson('/api/v1/guest/menu/'.$this->token.'/bill', ['order_number' => $order['number']])->assertConflict();
    }

    public function test_guest_bill_requests_reject_unconfirmed_paid_and_unavailable_tables(): void
    {
        $order = $this->confirmedTableOrder();
        $this->app['auth']->forgetGuards();
        $path = '/api/v1/guest/menu/'.$this->token.'/bill';
        $payload = ['order_number' => $order['number']];

        DB::table('order_items')->where('order_id', $order['id'])->update(['status' => 'draft']);
        $this->postJson($path, $payload)->assertUnprocessable();
        DB::table('order_items')->where('order_id', $order['id'])->update(['status' => 'sent']);
        DB::table('orders')->where('id', $order['id'])->update(['paid' => true]);
        $this->postJson($path, $payload)->assertUnprocessable();
        DB::table('orders')->where('id', $order['id'])->update(['paid' => false]);
        DB::table('dining_tables')->where('id', 1)->update(['active' => false]);
        $this->postJson($path, $payload)->assertNotFound();
        DB::table('dining_tables')->where('id', 1)->update(['active' => true]);
        DB::table('businesses')->where('id', 1)->update(['status' => 'suspended']);
        $this->postJson($path, $payload)->assertNotFound();
        $this->assertDatabaseMissing('orders', ['bill_requested' => true]);
    }

    public function test_public_menu_is_safe_and_guest_requests_need_staff_acceptance_before_kitchen(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->assertJsonMissingPath('business')->assertJsonMissingPath('staff')->assertJsonMissingPath('menu.0.stock_on_hand');
        $request = $this->submit(2);
        $this->assertSame('pending', $request['status']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->assertJsonPath('table_order', null);
        $this->getJson('/api/v1/guest-orders')->assertUnauthorized();
        $this->withSession(['password_hash_web' => null])->actingAs($this->manager);
        $id = DB::table('guest_order_requests')->value('id');
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertOk();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('kitchen_tickets', 1);
        $this->assertDatabaseHas('order_items', ['quantity' => 2, 'status' => 'sent', 'notes' => 'No onion']);
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()
            ->assertJsonCount(1, 'table_order.items')->assertJsonPath('table_order.items.0.quantity', 2);
        $this->getJson('/api/v1/guest/requests/'.$request['token'])->assertOk()->assertJsonPath('status', 'accepted')->assertJsonMissingPath('order_id');
        $this->getJson('/api/v1/guest-orders')->assertOk()->assertJsonMissingPath('requests.0.public_token');
    }

    public function test_table_menu_shows_only_confirmed_current_order_items_with_safe_snapshots(): void
    {
        $order = $this->action('orders', ['type' => 'dine_in', 'table_id' => 1])->assertOk()->json();
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->assertJsonPath('table_order.items', []);
        $order = $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 2, 'notes' => 'Internal staff note'])->assertOk()->json();
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->assertJsonPath('table_order.items', []);
        $order = $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk()->json();
        $snapshot = [['id' => 99, 'kind' => 'variant', 'name' => 'Original size', 'price' => 6500]];
        DB::table('order_items')->where('order_id', $order['id'])->update(['options_snapshot' => json_encode($snapshot)]);
        DB::table('menu_items')->where('id', 1)->update(['name' => 'Renamed menu item', 'price' => 9000]);
        $order = $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 2, 'quantity' => 1])->assertOk()->json();
        DB::table('order_items')->where('order_id', $order['id'])->where('menu_item_id', 2)->update(['status' => 'cancelled']);
        $this->app['auth']->forgetGuards();

        foreach (['sent', 'preparing', 'ready', 'served'] as $status) {
            DB::table('order_items')->where('order_id', $order['id'])->where('menu_item_id', 1)->update(['status' => $status]);
            $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()
                ->assertJsonPath('table_order.number', $order['number'])
                ->assertJsonPath('table_order.items', [['name' => 'Chicken fried rice', 'quantity' => 2, 'price' => 6500, 'status' => $status, 'options_snapshot' => $snapshot]])
                ->assertJsonMissingPath('table_order.id')->assertJsonMissingPath('table_order.items.0.notes')
                ->assertJsonMissingPath('table_order.items.0.ticket_id');
        }
        foreach (['closed', 'cancelled'] as $status) {
            DB::table('orders')->where('id', $order['id'])->update(['status' => $status]);
            $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->assertJsonPath('table_order', null);
        }
    }

    public function test_current_order_follows_table_occupancy_and_is_hidden_on_takeaway_links(): void
    {
        $otherToken = $this->action('guest-orders/configure', ['action' => 'link', 'table_id' => 2])->assertOk()->json('token');
        $takeawayToken = $this->action('guest-orders/configure', ['action' => 'link'])->assertOk()->json('token');
        $order = $this->action('orders', ['type' => 'dine_in', 'table_id' => 1])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk()->json();
        $this->getJson('/api/v1/guest/menu/'.$otherToken)->assertOk()->assertJsonPath('table_order', null);
        $this->getJson('/api/v1/guest/menu/'.$takeawayToken)->assertOk()->assertJsonPath('table_order', null);

        $this->action('orders/'.$order['id'].'/transfer', ['version' => $order['version'], 'table_id' => 2, 'reason' => 'Guest moved'])->assertOk();

        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->assertJsonPath('table_order', null);
        $this->getJson('/api/v1/guest/menu/'.$otherToken)->assertOk()->assertJsonCount(1, 'table_order.items');
        $this->getJson('/api/v1/guest/menu/'.$takeawayToken)->assertOk()->assertJsonPath('table_order', null);
    }

    public function test_duplicate_submissions_and_staff_retries_never_duplicate_orders(): void
    {
        $payload = $this->payload();
        $key = (string) Str::uuid();
        $first = $this->action('guest/menu/'.$this->token.'/requests', $payload, $key)->assertCreated()->json();
        $this->action('guest/menu/'.$this->token.'/requests', $payload, $key)->assertOk()->assertJsonPath('token', $first['token']);
        $this->action('guest/menu/'.$this->token.'/requests', [...$payload, 'guest_name' => 'Different'], $key)->assertStatus(409);
        $id = DB::table('guest_order_requests')->value('id');
        $key = (string) Str::uuid();
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true], $key)->assertOk();
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true], $key)->assertOk();
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertStatus(409);
        $this->assertDatabaseCount('order_items', 1);
        $this->submit();
        $id = DB::table('guest_order_requests')->max('id');
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertOk();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseCount('order_tables', 1);
    }

    public function test_availability_and_price_changes_block_acceptance_without_leaving_partial_records(): void
    {
        $this->submit(2);
        $id = DB::table('guest_order_requests')->value('id');
        DB::table('menu_items')->where('id', 1)->update(['available' => false]);
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('kitchen_tickets', 0);
        $this->assertDatabaseCount('order_tables', 0);
        DB::table('menu_items')->where('id', 1)->update(['available' => true, 'price' => 7000]);
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertStatus(409);
        $this->action('guest/menu/'.$this->token.'/requests', $this->payload())->assertStatus(409);
        $this->action('guest-orders/'.$id.'/review', ['action' => 'reject', 'reason' => 'This dish is unavailable; please speak with staff.', 'confirmed' => false])->assertOk();
        $this->assertDatabaseHas('guest_order_requests', ['id' => $id, 'status' => 'rejected']);
    }

    public function test_rotated_disabled_and_inactive_links_stop_new_requests_and_guests_cannot_override_prices(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['price'] = 1;
        $this->action('guest/menu/'.$this->token.'/requests', $payload)->assertStatus(409);
        $payload = $this->payload();
        $payload['charges']['tax_rate_bps'] = 900;
        $this->action('guest/menu/'.$this->token.'/requests', $payload)->assertStatus(409);
        $this->action('guest-orders/configure', ['action' => 'rotate', 'table_id' => 1])->assertOk();
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertNotFound();
        $this->action('guest/menu/'.$this->token.'/requests', $payload)->assertNotFound();
        $this->token = $this->action('guest-orders/configure', ['action' => 'link', 'table_id' => 1])->assertOk()->json('token');
        $payload = $this->payload();
        $this->action('guest-orders/configure', ['action' => 'settings', 'guest_menu_enabled' => true, 'guest_ordering_enabled' => false])->assertOk();
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->assertJsonPath('ordering_enabled', false);
        $this->action('guest/menu/'.$this->token.'/requests', $payload)->assertStatus(422);
        DB::table('dining_tables')->where('id', 1)->update(['active' => false]);
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertNotFound();
    }

    public function test_expired_requests_and_settling_table_bills_cannot_be_accepted(): void
    {
        $request = $this->submit();
        $id = DB::table('guest_order_requests')->value('id');
        DB::table('guest_order_requests')->where('id', $id)->update(['created_at' => now()->subHours(3)]);
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertStatus(422);
        $this->getJson('/api/v1/guest/requests/'.$request['token'])->assertOk()->assertJsonPath('status', 'expired');
        DB::table('guest_order_requests')->where('id', $id)->update(['created_at' => now()]);
        $order = $this->action('orders', ['type' => 'dine_in', 'table_id' => 1])->assertOk()->json();
        DB::table('orders')->where('id', $order['id'])->update(['paid' => true]);
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertStatus(422);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_permissions_scoping_and_read_only_subscriptions_are_enforced(): void
    {
        $this->submit();
        $id = DB::table('guest_order_requests')->value('id');
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'kitchen')->firstOrFail());
        $this->getJson('/api/v1/guest-orders')->assertForbidden();
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertForbidden();
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'waiter')->firstOrFail());
        $this->getJson('/api/v1/guest-orders')->assertOk()->assertJsonCount(0, 'links');
        $this->action('guest-orders/configure', ['action' => 'link'])->assertForbidden();
        $this->action('guest-orders/99999/review', ['action' => 'accept', 'confirmed' => true])->assertNotFound();
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept'])->assertStatus(422);
        DB::table('businesses')->where('id', $this->manager->business_id)->update(['status' => 'suspended']);
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertForbidden();
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertNotFound();

    }

    public function test_sizes_and_addons_are_quoted_on_the_server_and_preserved_for_the_kitchen(): void
    {
        $businessId = $this->manager->business_id;
        $size = DB::table('menu_options')->insertGetId(['business_id' => $businessId, 'menu_item_id' => 1, 'kind' => 'variant', 'name' => 'Large', 'price' => 8000, 'active' => true]);
        $extra = DB::table('menu_options')->insertGetId(['business_id' => $businessId, 'menu_item_id' => 1, 'kind' => 'addon', 'name' => 'Egg', 'price' => 1000, 'active' => true]);
        DB::table('menu_items')->where('id', 1)->update(['uses_variants' => true, 'options_version' => 2]);
        $payload = $this->payload(2);
        $payload['items'][0] = [...$payload['items'][0], 'variant_id' => $size, 'addon_ids' => [$extra], 'options_version' => 2, 'price' => 9000];
        $this->action('guest/menu/'.$this->token.'/requests', $payload)->assertCreated();
        $id = DB::table('guest_order_requests')->value('id');
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertOk();
        $line = DB::table('order_items')->first();
        $this->assertSame(9000, $line->price);
        $this->assertSame(['Large', 'Egg'], array_column(json_decode($line->options_snapshot, true), 'name'));
        $this->assertSame(18000, json_decode(DB::table('guest_order_requests')->value('totals_snapshot'), true)['total']);
    }

    public function test_a_different_business_cannot_read_or_review_requests_or_attach_foreign_tables_and_food(): void
    {
        $this->submit();
        $requestId = DB::table('guest_order_requests')->value('id');
        $business = DB::table('businesses')->insertGetId(['name' => 'Other', 'code' => 'another-cafe', 'setup_completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $subscription = (array) DB::table('subscriptions')->first();
        unset($subscription['id']);
        DB::table('subscriptions')->insert([...$subscription, 'business_id' => $business]);
        DB::table('restaurant_settings')->insert(['business_id' => $business, 'name' => 'Other']);
        $other = User::factory()->create(['business_id' => $business, 'roles' => ['manager'], 'active' => true]);
        $this->withSession(['password_hash_web' => null])->actingAs($other);
        $this->getJson('/api/v1/guest-orders')->assertOk()->assertJsonCount(0, 'requests')->assertJsonCount(0, 'links');
        $this->action('guest-orders/'.$requestId.'/review', ['action' => 'accept', 'confirmed' => true])->assertNotFound();
        $this->action('guest-orders/configure', ['action' => 'link', 'table_id' => 1])->assertStatus(422);
        $this->action('guest-orders/configure', ['action' => 'settings', 'guest_menu_enabled' => true, 'guest_ordering_enabled' => true])->assertOk();
        $foreignToken = $this->action('guest-orders/configure', ['action' => 'link'])->assertOk()->json('token');
        $charges = $this->getJson('/api/v1/guest/menu/'.$foreignToken)->assertOk()->assertJsonCount(0, 'menu')->json('charges');
        $this->action('guest/menu/'.$foreignToken.'/requests', ['items' => [['menu_item_id' => 1, 'price' => 6500, 'quantity' => 1, 'options_version' => 1, 'addon_ids' => []]], 'charges' => $charges])->assertStatus(422);
    }

    public function test_guest_submission_limits_do_not_block_other_tables_on_the_same_wifi(): void
    {
        $payload = $this->payload();
        $key = (string) Str::uuid();
        $this->action('guest/menu/'.$this->token.'/requests', $payload, $key)->assertCreated();
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $this->action('guest/menu/'.$this->token.'/requests', $payload, $key)->assertOk();
        }
        $this->action('guest/menu/'.$this->token.'/requests', $payload, $key)->assertStatus(429);
        $this->token = $this->action('guest-orders/configure', ['action' => 'link', 'table_id' => 2])->assertOk()->json('token');
        $this->submit();
    }

    public function test_waiting_requests_remain_visible_when_many_newer_requests_have_been_reviewed(): void
    {
        $this->submit();
        $request = (array) DB::table('guest_order_requests')->first();
        $pendingId = $request['id'];
        unset($request['id']);
        for ($index = 0; $index < 150; $index++) {
            DB::table('guest_order_requests')->insert([...$request, 'public_token' => Str::random(48), 'request_key' => (string) Str::uuid(), 'status' => 'rejected', 'reason' => 'Reviewed with guest', 'reviewed_at' => now(), 'reviewed_by' => $this->manager->id]);
        }
        $this->getJson('/api/v1/guest-orders')->assertOk()->assertJsonCount(150, 'requests')->assertJsonPath('requests.0.id', $pendingId)->assertJsonPath('requests.0.status', 'pending');
    }

    public function test_one_click_setup_prepares_active_table_codes_without_replacing_existing_links(): void
    {
        DB::table('dining_tables')->where('id', 12)->update(['active' => false]);
        $business = DB::table('businesses')->insertGetId(['name' => 'Other', 'code' => 'other-qr-cafe', 'created_at' => now(), 'updated_at' => now()]);
        $foreignTable = DB::table('dining_tables')->insertGetId(['business_id' => $business, 'name' => 'Other table', 'seats' => 4]);
        $key = (string) Str::uuid();
        $this->action('guest-orders/configure', ['action' => 'enable'], $key)->assertOk();
        $this->action('guest-orders/configure', ['action' => 'enable'], $key)->assertOk();
        $this->action('guest-orders/configure', ['action' => 'enable'])->assertOk();
        $this->assertDatabaseHas('restaurant_settings', ['business_id' => 1, 'guest_menu_enabled' => true, 'guest_ordering_enabled' => true]);
        $this->assertSame(12, DB::table('guest_menu_links')->where('business_id', 1)->count());
        $this->assertDatabaseHas('guest_menu_links', ['business_id' => 1, 'table_id' => 1, 'token' => $this->token]);
        $this->assertDatabaseMissing('guest_menu_links', ['table_id' => 12]);
        $this->assertDatabaseMissing('guest_menu_links', ['table_id' => $foreignTable]);
        $this->assertDatabaseMissing('guest_menu_links', ['business_id' => $business]);
    }

    public function test_only_managers_with_writable_access_can_enable_table_ordering(): void
    {
        $waiter = User::where('username', 'waiter')->firstOrFail();
        $this->withSession(['password_hash_web' => null])->actingAs($waiter);
        $this->action('guest-orders/configure', ['action' => 'enable'])->assertForbidden();
        $this->withSession(['password_hash_web' => null])->actingAs($this->manager);
        DB::table('subscriptions')->where('business_id', 1)->update(['status' => 'cancelled']);
        $this->action('guest-orders/configure', ['action' => 'enable'])->assertForbidden();
        $this->assertSame(1, DB::table('guest_menu_links')->count());
    }
}
