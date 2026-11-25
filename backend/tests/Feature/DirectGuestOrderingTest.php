<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DirectGuestOrderingTest extends TestCase
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
        $this->action('guest-orders/configure', ['action' => 'enable'])->assertOk();
        $this->token = DB::table('guest_menu_links')->where('business_id', 1)->where('table_id', 1)->value('token');
    }

    private function action(string $path, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/'.$path, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function direct(bool $enabled): void
    {
        $this->withSession(['password_hash_web' => null])->actingAs($this->manager);
        $this->action('guest-orders/configure', ['action' => 'settings', 'guest_menu_enabled' => true, 'guest_ordering_enabled' => true, 'guest_direct_to_kitchen' => $enabled])->assertOk();
    }

    private function payload(int $quantity = 1): array
    {
        $menu = $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->json();

        return ['items' => [['menu_item_id' => 1, 'price' => 6500, 'quantity' => $quantity, 'options_version' => 1, 'addon_ids' => [], 'notes' => 'No onion']], 'charges' => $menu['charges']];
    }

    public function test_default_requires_staff_review_and_customers_cannot_enable_direct_ordering(): void
    {
        Auth::logout();
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->assertJsonPath('direct_to_kitchen', false);
        $this->action('guest/menu/'.$this->token.'/requests', [...$this->payload(), 'guest_direct_to_kitchen' => true])->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('auto_accepted', false);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('kitchen_tickets', 0);
    }

    public function test_direct_orders_reach_the_kitchen_without_impersonating_staff(): void
    {
        $this->direct(true);
        Auth::logout();
        $response = $this->action('guest/menu/'.$this->token.'/requests', [...$this->payload(2), 'table_id' => 2])->assertCreated()->assertJsonPath('status', 'accepted')->assertJsonPath('auto_accepted', true);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('kitchen_tickets', 1);
        $this->assertDatabaseHas('orders', ['business_id' => 1, 'created_by' => null, 'type' => 'dine_in', 'active_table_id' => 1]);
        $this->assertDatabaseHas('kitchen_tickets', ['submitted_by' => null]);
        $this->assertDatabaseHas('order_tables', ['business_id' => 1, 'table_id' => 1]);
        $this->assertDatabaseHas('order_items', ['quantity' => 2, 'notes' => 'No onion', 'status' => 'sent']);
        $this->assertDatabaseHas('guest_order_requests', ['status' => 'accepted', 'auto_accepted' => true, 'reviewed_by' => null]);
        $this->assertDatabaseHas('operational_events', ['user_id' => null, 'action' => 'guest-order-direct']);
        $this->assertDatabaseCount('payments', 0);
        $this->getJson('/api/v1/guest/menu/'.$this->token)->assertOk()->assertJsonCount(1, 'table_order.items')
            ->assertJsonPath('table_order.items.0.quantity', 2)->assertJsonPath('table_order.items.0.status', 'sent');
        $this->getJson('/api/v1/guest/requests/'.$response->json('token'))->assertOk()->assertJsonPath('auto_accepted', true)->assertJsonMissingPath('order_id');
        $this->withSession(['password_hash_web' => null])->actingAs($this->manager);
        $id = DB::table('orders')->value('id');
        $this->getJson('/api/v1/orders/'.$id)->assertOk()->assertJsonPath('events.0.actor', 'Customer (QR menu)');
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('guest_pending_count', 0)->assertJsonCount(1, 'tickets');
    }

    public function test_retries_do_not_duplicate_tickets_and_later_requests_join_the_same_table_bill(): void
    {
        $this->direct(true);
        Auth::logout();
        $key = (string) Str::uuid();
        $payload = $this->payload(2);
        $this->action('guest/menu/'.$this->token.'/requests', $payload, $key)->assertCreated();
        $this->action('guest/menu/'.$this->token.'/requests', $payload, $key)->assertOk();
        $this->action('guest/menu/'.$this->token.'/requests', $this->payload())->assertCreated();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_tables', 1);
        $this->assertDatabaseCount('kitchen_tickets', 2);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseCount('guest_order_requests', 2);
        $this->direct(false);
        Auth::logout();
        $this->action('guest/menu/'.$this->token.'/requests', $payload, $key)->assertOk()->assertJsonPath('auto_accepted', true);
        $this->assertDatabaseCount('kitchen_tickets', 2);
        $this->assertSame(2, DB::table('operational_events')->where('action', 'guest-order-direct')->count());
    }

    public function test_mode_changes_affect_only_new_requests_and_preparing_codes_keeps_the_saved_mode(): void
    {
        $this->action('guest/menu/'.$this->token.'/requests', $this->payload())->assertCreated()->assertJsonPath('status', 'pending');
        $this->direct(true);
        $this->action('guest-orders/configure', ['action' => 'enable'])->assertOk();
        $this->assertDatabaseHas('restaurant_settings', ['business_id' => 1, 'guest_direct_to_kitchen' => true]);
        $this->action('guest/menu/'.$this->token.'/requests', $this->payload())->assertCreated()->assertJsonPath('status', 'accepted');
        $this->direct(false);
        $this->action('guest/menu/'.$this->token.'/requests', $this->payload())->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertSame(2, DB::table('guest_order_requests')->where('status', 'pending')->count());
        $this->assertDatabaseCount('kitchen_tickets', 1);
    }

    public function test_invalid_prices_and_conflicting_bills_leave_no_partial_customer_orders(): void
    {
        $this->direct(true);
        $payload = $this->payload();
        $payload['items'][0]['price'] = 1;
        $this->action('guest/menu/'.$this->token.'/requests', $payload)->assertConflict();
        $order = $this->action('orders', ['type' => 'dine_in', 'table_id' => 1])->assertOk()->json();
        DB::table('orders')->where('id', $order['id'])->update(['discount_type' => 'fixed', 'discount_value' => 500]);
        $this->action('guest/menu/'.$this->token.'/requests', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('guest_order_requests', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('kitchen_tickets', 0);
        DB::table('orders')->where('id', $order['id'])->update(['discount_type' => 'none', 'paid' => true]);
        $this->action('guest/menu/'.$this->token.'/requests', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('guest_order_requests', 0);
    }

    public function test_only_managers_can_enable_direct_orders_and_ordering_must_be_enabled(): void
    {
        $this->action('guest-orders/configure', ['action' => 'settings', 'guest_menu_enabled' => true, 'guest_ordering_enabled' => false, 'guest_direct_to_kitchen' => true])->assertUnprocessable();
        $waiter = User::where('username', 'waiter')->firstOrFail();
        $this->withSession(['password_hash_web' => null])->actingAs($waiter);
        $this->action('guest-orders/configure', ['action' => 'settings', 'guest_menu_enabled' => true, 'guest_ordering_enabled' => true, 'guest_direct_to_kitchen' => true])->assertForbidden();
        $this->direct(true);
        $this->action('guest-orders/configure', ['action' => 'settings', 'guest_menu_enabled' => true, 'guest_ordering_enabled' => false])->assertOk();
        $this->assertDatabaseHas('restaurant_settings', ['business_id' => 1, 'guest_direct_to_kitchen' => false]);
        DB::table('businesses')->where('id', 1)->update(['status' => 'suspended']);
        $this->action('guest-orders/configure', ['action' => 'settings', 'guest_menu_enabled' => true, 'guest_ordering_enabled' => true, 'guest_direct_to_kitchen' => true])->assertForbidden();
    }
}
