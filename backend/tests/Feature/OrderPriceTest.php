<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OrderPriceTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->seed();
        $this->actingAs(User::where('username', 'manager')->firstOrFail());
    }

    private function postAction(string $path, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/'.$path, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function openOrder(string $type = 'takeaway'): array
    {
        $order = $this->postAction('orders', ['type' => $type, ...($type === 'dine_in' ? ['table_id' => 1] : [])])->assertOk()->json();

        return $this->command($order, 'add-item', ['menu_item_id' => 1, 'quantity' => 2]);
    }

    private function command(array $order, string $action, array $data = []): array
    {
        return $this->postAction("orders/{$order['id']}/$action", ['version' => $order['version'], ...$data])->assertOk()->json();
    }

    public function test_price_changes_update_order_only_and_record_server_side_audit_values(): void
    {
        $this->signIn();
        $order = $this->command($this->openOrder(), 'submit');
        $ticket = $order['items'][0]['ticket_id'];
        $order = $this->command($order, 'change-price', ['item_id' => $order['items'][0]['id'], 'price' => 5000, 'reason' => 'Agreed price', 'old_price' => 1]);
        $this->assertSame(10000, $order['total']);
        $this->assertSame(5000, $order['items'][0]['price']);
        $this->assertSame('sent', $order['items'][0]['status']);
        $this->assertSame($ticket, $order['items'][0]['ticket_id']);
        $this->assertDatabaseHas('menu_items', ['id' => 1, 'price' => 6500]);
        $this->assertDatabaseCount('kitchen_tickets', 1);
        $event = DB::table('operational_events')->where('order_id', $order['id'])->where('action', 'change-price')->first();
        $details = json_decode($event->details, true);
        $this->assertSame(6500, $details['old_price']);
        $this->assertSame(5000, $details['new_price']);
        $this->assertSame('Agreed price', $details['reason']);
        $this->assertSame(2, $details['quantity']);
        $this->assertSame(User::where('username', 'manager')->value('id'), $event->user_id);
        $order = $this->command($order, 'add-item', ['menu_item_id' => 1, 'quantity' => 1]);
        $this->assertSame(6500, $order['items'][1]['price']);
    }

    public function test_cashier_can_change_served_dine_in_prices_at_checkout(): void
    {
        $this->signIn();
        $order = $this->command($this->openOrder('dine_in'), 'submit');
        $itemId = $order['items'][0]['id'];
        $order = $this->command($order, 'ready', ['item_id' => $itemId]);
        $order = $this->command($order, 'serve', ['item_id' => $itemId]);
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'cashier')->firstOrFail());
        $order = $this->command($order, 'change-price', ['item_id' => $itemId, 'price' => 4000]);
        $this->assertSame('served', $order['items'][0]['status']);
        $order = $this->command($order, 'pay', ['method' => 'cash', 'tendered' => 10000]);
        $this->assertSame(8000, $order['payment']['amount']);
        $this->assertSame(2000, $order['payment']['change']);
        $this->assertSame(4000, $order['items'][0]['price']);
        $this->assertSame('closed', $order['status']);
    }

    public function test_collected_unpaid_takeaway_can_be_repriced_but_food_contents_stay_locked(): void
    {
        $this->signIn();
        $order = $this->command($this->openOrder(), 'submit');
        $order = $this->command($order, 'ready', ['item_id' => $order['items'][0]['id']]);
        $order = $this->command($order, 'collect');
        $order = $this->command($order, 'change-price', ['item_id' => $order['items'][0]['id'], 'price' => 3000]);
        $this->postAction("orders/{$order['id']}/add-item", ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertUnprocessable();
        $order = $this->command($order, 'pay', ['method' => 'cash', 'tendered' => 6000]);
        $this->assertSame(6000, $order['payment']['amount']);
    }

    public function test_paid_and_cancelled_items_cannot_be_repriced(): void
    {
        $this->signIn();
        $order = $this->command($this->openOrder(), 'submit');
        $itemId = $order['items'][0]['id'];
        $order = $this->command($order, 'pay', ['method' => 'cash', 'tendered' => 13000]);
        $this->postAction("orders/{$order['id']}/change-price", ['version' => $order['version'], 'item_id' => $itemId, 'price' => 1])->assertUnprocessable();
        $this->assertDatabaseHas('order_items', ['id' => $itemId, 'price' => 6500]);
        $second = $this->command($this->openOrder(), 'add-item', ['menu_item_id' => 2, 'quantity' => 1]);
        $cancelledId = $second['items'][0]['id'];
        $second = $this->command($second, 'cancel-item', ['item_id' => $cancelledId, 'reason' => 'Wrong item']);
        $this->postAction("orders/{$second['id']}/change-price", ['version' => $second['version'], 'item_id' => $cancelledId, 'price' => 1])->assertUnprocessable();
    }

    public function test_price_retry_is_idempotent_and_stale_or_foreign_item_updates_are_rejected(): void
    {
        $this->signIn();
        $order = $this->openOrder();
        $key = (string) Str::uuid();
        $data = ['version' => $order['version'], 'item_id' => $order['items'][0]['id'], 'price' => 5000];
        $this->postAction("orders/{$order['id']}/change-price", $data, $key)->assertOk();
        $this->postAction("orders/{$order['id']}/change-price", $data, $key)->assertOk();
        $this->assertSame(1, DB::table('operational_events')->where('action', 'change-price')->count());
        $this->postAction("orders/{$order['id']}/change-price", [...$data, 'price' => 1])->assertConflict();
        $other = $this->openOrder();
        $this->postAction("orders/{$other['id']}/change-price", ['version' => $other['version'], 'item_id' => $order['items'][0]['id'], 'price' => 1])->assertNotFound();
    }

    public function test_zero_unit_price_can_be_settled(): void
    {
        $this->signIn();
        $order = $this->openOrder();
        $order = $this->command($order, 'change-price', ['item_id' => $order['items'][0]['id'], 'price' => 0]);
        $order = $this->command($order, 'submit');
        $order = $this->command($order, 'pay', ['method' => 'cash', 'tendered' => 0]);
        $this->assertSame(0, $order['payment']['amount']);
    }

    #[TestWith([-1])]
    #[TestWith([1.5])]
    #[TestWith([10000001])]
    public function test_invalid_prices_return_422(mixed $price): void
    {
        $this->signIn();
        $order = $this->openOrder();
        $this->postAction("orders/{$order['id']}/change-price", ['version' => $order['version'], 'item_id' => $order['items'][0]['id'], 'price' => $price])->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->assertDatabaseHas('order_items', ['id' => $order['items'][0]['id'], 'price' => 6500]);
    }

    #[TestWith(['waiter'])]
    #[TestWith(['kitchen'])]
    public function test_unauthorized_roles_cannot_change_prices(string $role): void
    {
        $this->signIn();
        $order = $this->openOrder();
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', $role)->firstOrFail());
        $this->postAction("orders/{$order['id']}/change-price", ['version' => $order['version'], 'item_id' => $order['items'][0]['id'], 'price' => 1])->assertForbidden();
    }

    public function test_guests_cannot_change_prices(): void
    {
        $this->postAction('orders/1/change-price', ['version' => 1, 'item_id' => 1, 'price' => 1])->assertUnauthorized();
    }
}
