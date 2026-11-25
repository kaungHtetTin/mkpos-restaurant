<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestaurantWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('username', 'manager')->first());
    }

    private function postAction(string $path, array $body, ?string $key = null)
    {
        return $this->postJson('/api/v1/'.$path, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function open(string $type = 'dine_in'): array
    {
        return $this->postAction('orders', ['type' => $type, ...($type === 'dine_in' ? ['table_id' => 1] : [])])->assertOk()->json();
    }

    private function command(array $order, string $action, array $data = []): array
    {
        return $this->postAction("orders/{$order['id']}/$action", ['version' => $order['version'], ...$data])->assertOk()->json();
    }

    private function food(array $order): array
    {
        return $this->command($order, 'add-item', ['menu_item_id' => 1, 'quantity' => 2, 'notes' => 'No onion']);
    }

    public function test_dine_in_lifecycle_and_receipt_snapshot(): void
    {
        $o = $this->food($this->open());
        $this->assertSame(13000, $o['total']);
        $o = $this->command($o, 'submit');
        $item = $o['items'][0]['id'];
        $this->postAction("orders/{$o['id']}/pay", ['version' => $o['version'], 'method' => 'cash', 'tendered' => 15000])->assertUnprocessable();
        $o = $this->command($o, 'prepare', ['item_id' => $item]);
        $o = $this->command($o, 'ready', ['item_id' => $item]);
        $o = $this->command($o, 'serve', ['item_id' => $item]);
        $o = $this->command($o, 'bill');
        $o = $this->command($o, 'pay', ['method' => 'cash', 'tendered' => 15000]);
        $this->assertSame('closed', $o['status']);
        $this->assertNull($o['active_table_id']);
        $this->assertSame(2000, $o['payment']['change']);
        DB::table('restaurant_settings')->update(['name' => 'Changed restaurant']);
        DB::table('menu_items')->where('id', 1)->update(['price' => 99999, 'name' => 'Changed dish']);
        $receipt = $this->getJson('/api/v1/orders/'.$o['id'])->assertOk()->json();
        $this->assertSame(13000, $receipt['total']);
        $this->assertSame('Chicken fried rice', $receipt['items'][0]['name']);
        $this->assertSame('Little Lemon', $receipt['payment']['restaurant_snapshot']['name']);
        $this->postAction("orders/{$o['id']}/add-item", ['version' => $o['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertUnprocessable();
    }

    public function test_takeaway_paid_first_stays_open_until_collected(): void
    {
        $o = $this->command($this->food($this->open('takeaway')), 'submit');
        $o = $this->command($o, 'pay', ['method' => 'other', 'tendered' => 13000]);
        $this->assertTrue($o['paid']);
        $this->assertSame('open', $o['status']);
        $o = $this->command($o, 'ready', ['item_id' => $o['items'][0]['id']]);
        $o = $this->command($o, 'collect');
        $this->assertSame('closed', $o['status']);
        $this->assertNotNull($o['collected_at']);
    }

    public function test_takeaway_collected_first_waits_for_payment(): void
    {
        $o = $this->command($this->food($this->open('takeaway')), 'submit');
        $o = $this->command($o, 'ready', ['item_id' => $o['items'][0]['id']]);
        $o = $this->command($o, 'collect');
        $this->assertSame('open', $o['status']);
        $this->assertFalse($o['paid']);
        $this->assertSame('closed', $this->command($o, 'pay', ['method' => 'cash', 'tendered' => 13000])['status']);
    }

    public function test_creation_and_kitchen_submission_are_idempotent(): void
    {
        $key = (string) Str::uuid();
        $body = ['type' => 'dine_in', 'table_id' => 1];
        $a = $this->postAction('orders', $body, $key)->assertOk()->json();
        $b = $this->postAction('orders', $body, $key)->assertOk()->json();
        $this->assertSame($a['id'], $b['id']);
        $this->postAction('orders', ['type' => 'takeaway'], $key)->assertConflict();
        $this->assertSame($a['id'], $this->open()['id']);
        $this->assertDatabaseCount('orders', 1);
        $o = $this->food($a);
        $key = (string) Str::uuid();
        $body = ['version' => $o['version']];
        $this->postAction("orders/{$o['id']}/submit", $body, $key)->assertOk();
        $this->postAction("orders/{$o['id']}/submit", $body, $key)->assertOk();
        $this->assertDatabaseCount('kitchen_tickets', 1);
    }

    public function test_payment_retry_does_not_duplicate_receipt(): void
    {
        $o = $this->command($this->food($this->open('takeaway')), 'submit');
        $key = (string) Str::uuid();
        $body = ['version' => $o['version'], 'method' => 'cash', 'tendered' => 13000];
        $this->postAction("orders/{$o['id']}/pay", $body, $key)->assertOk();
        $this->postAction("orders/{$o['id']}/pay", $body, $key)->assertOk();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_additional_round_only_submits_new_lines(): void
    {
        $o = $this->command($this->food($this->open()), 'submit');
        $first = $o['items'][0]['ticket_id'];
        $o = $this->command($this->food($o), 'submit');
        $this->assertDatabaseCount('kitchen_tickets', 2);
        $this->assertSame($first, $o['items'][0]['ticket_id']);
        $this->assertNotSame($first, $o['items'][1]['ticket_id']);
    }

    public function test_stale_order_update_is_rejected(): void
    {
        $o = $this->open();
        $this->food($o);
        $this->postAction("orders/{$o['id']}/add-item", ['version' => $o['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertConflict();
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_cancellation_survives_order_closure_until_kitchen_acknowledges(): void
    {
        $o = $this->command($this->food($this->open()), 'submit');
        $id = $o['items'][0]['id'];
        $o = $this->command($o, 'cancel-item', ['item_id' => $id, 'reason' => 'Guest changed their mind']);
        $this->assertSame('cancelled', $o['status']);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonCount(1, 'orders');
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'kitchen')->first());
        $this->command($o, 'acknowledge', ['item_id' => $id]);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonCount(0, 'orders');
    }

    public function test_cancellation_blocks_payment_until_acknowledged(): void
    {
        $o = $this->food($this->food($this->open('takeaway')));
        $o = $this->command($o, 'submit');
        $o = $this->command($o, 'cancel-item', ['item_id' => $o['items'][0]['id'], 'reason' => 'Wrong item']);
        $this->postAction("orders/{$o['id']}/pay", ['version' => $o['version'], 'method' => 'cash', 'tendered' => 20000])->assertUnprocessable();
        $o = $this->command($o, 'acknowledge', ['item_id' => $o['items'][0]['id']]);
        $this->assertSame(13000, $this->command($o, 'pay', ['method' => 'cash', 'tendered' => 13000])['payment']['amount']);
    }

    public function test_sold_out_items_block_add_and_submission(): void
    {
        $o = $this->food($this->open());
        DB::table('menu_items')->where('id', 1)->update(['available' => false]);
        $this->postAction("orders/{$o['id']}/submit", ['version' => $o['version']])->assertUnprocessable();
        $this->postAction("orders/{$o['id']}/add-item", ['version' => $o['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('kitchen_tickets', 0);
    }

    public function test_collected_takeaway_contents_are_locked_but_payment_remains_possible(): void
    {
        $o = $this->command($this->food($this->open('takeaway')), 'submit');
        $o = $this->command($o, 'ready', ['item_id' => $o['items'][0]['id']]);
        $o = $this->command($o, 'collect');
        $this->postAction("orders/{$o['id']}/add-item", ['version' => $o['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertUnprocessable();
        $this->assertSame('closed', $this->command($o, 'pay', ['method' => 'cash', 'tendered' => 13000])['status']);
    }

    public function test_kitchen_actions_do_not_expose_payment_or_customer_details(): void
    {
        $o = $this->command($this->food($this->open('takeaway')), 'submit');
        $o = $this->command($o, 'pay', ['method' => 'cash', 'tendered' => 13000]);
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'kitchen')->first());
        $this->postAction("orders/{$o['id']}/ready", ['version' => $o['version'], 'item_id' => $o['items'][0]['id']])->assertOk()->assertJsonMissingPath('payment')->assertJsonMissingPath('phone')->assertJsonMissingPath('items.0.price');
    }

    public function test_kitchen_and_waiter_permissions_are_enforced(): void
    {
        $o = $this->command($this->food($this->open('takeaway')), 'submit');
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'kitchen')->first());
        $this->getJson('/api/v1/history')->assertForbidden();
        $this->getJson('/api/v1/orders/'.$o['id'])->assertForbidden();
        $this->postAction("orders/{$o['id']}/pay", ['version' => $o['version'], 'method' => 'cash', 'tendered' => 13000])->assertForbidden();
        $this->postAction('manage/settings', [])->assertForbidden();
        $this->getJson('/api/v1/state')->assertOk()->assertJsonMissingPath('orders.0.payment')->assertJsonMissingPath('orders.0.phone');
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'waiter')->first());
        $this->postAction("orders/{$o['id']}/cancel", ['version' => $o['version'], 'reason' => 'test'])->assertForbidden();
        $this->postAction('manage/menu', [])->assertForbidden();
    }

    public function test_inactive_staff_and_guests_cannot_access_state(): void
    {
        $staff = User::where('username', 'waiter')->first();
        $staff->update(['active' => false]);
        $this->actingAs($staff);
        $this->getJson('/api/v1/state')->assertUnauthorized();
        $this->postJson('/api/v1/login', ['email' => 'waiter@littlelemon.local', 'password' => 'Lemon2026!'])->assertUnprocessable();
    }

    public function test_cannot_deactivate_an_occupied_table_or_last_manager(): void
    {
        $this->open();
        $this->postAction('manage/tables', ['id' => 1, 'name' => '01', 'seats' => 4, 'active' => false])->assertUnprocessable();
        $manager = User::where('username', 'manager')->first();
        $this->postAction('manage/staff', ['id' => $manager->id, 'name' => 'Manager', 'username' => 'manager', 'email' => $manager->email, 'roles' => ['waiter'], 'active' => true])->assertUnprocessable();
    }

    public function test_zero_total_order_can_be_settled_and_cash_underpayment_is_rejected(): void
    {
        $o = $this->command($this->food($this->open('takeaway')), 'submit');
        $this->postAction("orders/{$o['id']}/pay", ['version' => $o['version'], 'method' => 'cash', 'tendered' => 1])->assertUnprocessable();
        DB::table('menu_items')->where('id', 1)->update(['price' => 0]);
        $zero = $this->command($this->food($this->open('takeaway')), 'submit');
        $this->assertSame(0, $this->command($zero, 'pay', ['method' => 'cash', 'tendered' => 0])['payment']['amount']);
    }
}
