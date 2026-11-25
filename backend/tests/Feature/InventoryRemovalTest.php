<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InventoryRemovalTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->manager = User::where('username', 'manager')->firstOrFail();
        $this->actingAs($this->manager);
        DB::table('menu_items')->where('id', 1)->update(['track_stock' => true, 'stock_on_hand' => 0]);
        DB::table('inventory_movements')->insert(['business_id' => 1, 'menu_item_id' => 1, 'created_by' => $this->manager->id, 'kind' => 'count', 'quantity' => 0, 'balance' => 0, 'reason' => 'Historical count', 'created_at' => now()]);
    }

    private function action(string $path, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/'.$path, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_removed_endpoints_cannot_read_or_modify_existing_inventory_records(): void
    {
        $before = (array) DB::table('inventory_movements')->first();
        $this->getJson('/api/v1/inventory')->assertNotFound();
        $this->action('inventory', ['id' => 1, 'action' => 'restock', 'version' => 1, 'quantity' => 10, 'reason' => 'Old client'])->assertNotFound();
        $this->assertSame($before, (array) DB::table('inventory_movements')->first());
        $this->assertDatabaseHas('menu_items', ['id' => 1, 'stock_on_hand' => 0, 'track_stock' => true]);
    }

    public function test_staff_can_order_and_submit_a_dish_with_legacy_zero_stock_without_changing_history(): void
    {
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('menu.0.orderable', true)->assertJsonMissingPath('menu.0.track_stock')->assertJsonMissingPath('menu.0.stock_on_hand')->assertJsonMissingPath('menu.0.stock_low');
        $order = $this->action('orders', ['type' => 'takeaway'])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 3])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/edit-item', ['version' => $order['version'], 'item_id' => $order['items'][0]['id'], 'quantity' => 4])->assertOk()->json();
        $key = (string) Str::uuid();
        foreach (range(1, 2) as $attempt) {
            $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']], $key)->assertOk();
        }
        $this->assertDatabaseHas('order_items', ['order_id' => $order['id'], 'status' => 'sent', 'quantity' => 4]);
        $this->assertDatabaseCount('kitchen_tickets', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('menu_items', ['id' => 1, 'stock_on_hand' => 0]);
    }

    public function test_qr_orders_can_be_submitted_and_accepted_without_stock_constraints(): void
    {
        $this->action('guest-orders/configure', ['action' => 'enable'])->assertOk();
        $token = DB::table('guest_menu_links')->where('table_id', 1)->value('token');
        $menu = $this->getJson('/api/v1/guest/menu/'.$token)->assertOk()->assertJsonPath('menu.0.orderable', true)->json();
        $response = $this->action('guest/menu/'.$token.'/requests', ['items' => [['menu_item_id' => 1, 'price' => 6500, 'quantity' => 20, 'options_version' => 1, 'addon_ids' => []]], 'charges' => $menu['charges']])->assertCreated();
        $id = DB::table('guest_order_requests')->where('public_token', $response->json('token'))->value('id');
        $this->action('guest-orders/'.$id.'/review', ['action' => 'accept', 'confirmed' => true])->assertOk();
        $this->assertDatabaseHas('order_items', ['menu_item_id' => 1, 'quantity' => 20, 'status' => 'sent']);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('menu_items', ['id' => 1, 'stock_on_hand' => 0]);
    }

    public function test_manual_sold_out_and_required_menu_options_still_control_ordering(): void
    {
        $order = $this->action('orders', ['type' => 'takeaway'])->assertOk()->json();
        $this->action('manage/availability', ['id' => 1, 'available' => false])->assertOk();
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('menu.0.orderable', false);
        $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertUnprocessable();
        $this->action('manage/availability', ['id' => 1, 'available' => true])->assertOk();
        DB::table('menu_items')->where('id', 1)->update(['uses_variants' => true]);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('menu.0.orderable', false);
        $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1, 'options_version' => 1, 'addon_ids' => []])->assertUnprocessable();
    }
}
