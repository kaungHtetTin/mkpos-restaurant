<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MenuOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('username', 'manager')->firstOrFail());
    }

    private function action(string $path, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/'.$path, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function configure(): array
    {
        $this->action('manage/menu-options', ['id' => 1, 'options_version' => 1, 'variants' => [['name' => 'Small', 'price' => 5000, 'active' => true], ['name' => 'Large', 'price' => 8000, 'active' => true]], 'addons' => [['name' => 'Extra egg', 'price' => 500, 'active' => true]]])->assertOk();

        return $this->getJson('/api/v1/state')->assertOk()->json('menu.0');
    }

    private function order(): array
    {
        return $this->action('orders', ['type' => 'takeaway'])->assertOk()->json();
    }

    private function choices(array $food): array
    {
        return ['menu_item_id' => $food['id'], 'options_version' => $food['options_version'], 'variant_id' => $food['options'][1]['id'], 'addon_ids' => [$food['options'][2]['id']], 'quantity' => 2, 'notes' => 'No onion'];
    }

    public function test_sizes_and_addons_use_server_prices_and_draft_choices_can_be_edited(): void
    {
        $food = $this->configure();
        $this->assertSame(5000, $food['display_price']);
        $this->assertTrue($food['orderable']);
        $order = $this->order();
        $body = ['version' => $order['version'], ...$this->choices($food), 'price' => 1];
        $key = (string) Str::uuid();
        $order = $this->action('orders/'.$order['id'].'/add-item', $body, $key)->assertOk()->assertJsonPath('items.0.price', 8500)->assertJsonPath('total', 17000)->assertJsonPath('items.0.options_snapshot.0.name', 'Large')->assertJsonPath('items.0.options_snapshot.1.name', 'Extra egg')->json();
        $this->action('orders/'.$order['id'].'/add-item', $body, $key)->assertOk()->assertJsonCount(1, 'items');
        $order = $this->action('orders/'.$order['id'].'/configure-item', ['version' => $order['version'], 'item_id' => $order['items'][0]['id'], 'options_version' => $food['options_version'], 'variant_id' => $food['options'][0]['id'], 'addon_ids' => [], 'quantity' => 1, 'notes' => 'Less salt'])->assertOk()->assertJsonPath('items.0.price', 5000)->assertJsonPath('total', 5000)->assertJsonPath('items.0.notes', 'Less salt')->assertJsonCount(1, 'items.0.options_snapshot')->json();
        $this->assertSame(1, DB::table('operational_events')->where('action', 'configure-item')->count());
        $order = $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk()->json();
        $this->action('orders/'.$order['id'].'/configure-item', ['version' => $order['version'], 'item_id' => $order['items'][0]['id'], 'options_version' => $food['options_version'], 'variant_id' => $food['options'][0]['id'], 'addon_ids' => [], 'quantity' => 1])->assertUnprocessable();
    }

    public function test_required_sizes_duplicates_foreign_options_and_stale_configurations_are_rejected(): void
    {
        $food = $this->configure();
        $order = $this->order();
        $base = ['version' => $order['version'], ...$this->choices($food)];
        $this->action('orders/'.$order['id'].'/add-item', [...$base, 'variant_id' => null])->assertUnprocessable();
        $this->action('orders/'.$order['id'].'/add-item', [...$base, 'addon_ids' => [$food['options'][2]['id'], $food['options'][2]['id']]])->assertUnprocessable()->assertJsonValidationErrors('addon_ids.0');
        $this->action('orders/'.$order['id'].'/add-item', [...$base, 'options_version' => 1])->assertConflict();
        $this->action('orders/'.$order['id'].'/add-item', [...$base, 'variant_id' => $food['options'][2]['id']])->assertUnprocessable();
        $this->action('orders/'.$order['id'].'/add-item', [...$base, 'addon_ids' => [$food['options'][0]['id']]])->assertUnprocessable();
        $this->action('orders/'.$order['id'].'/add-item', [...$base, 'menu_item_id' => 2])->assertConflict();
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_removed_extras_block_drafts_but_staff_can_reconfigure_without_them(): void
    {
        $food = $this->configure();
        $order = $this->order();
        $order = $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], ...$this->choices($food)])->assertOk()->json();
        $variants = array_map(fn ($option) => ['id' => $option['id'], 'name' => $option['name'], 'price' => $option['price'], 'active' => true], array_slice($food['options'], 0, 2));
        $this->action('manage/menu-options', ['id' => 1, 'options_version' => $food['options_version'], 'variants' => $variants, 'addons' => []])->assertOk();
        $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertUnprocessable();
        $this->assertDatabaseHas('menu_options', ['id' => $food['options'][2]['id'], 'active' => false]);
        $order = $this->action('orders/'.$order['id'].'/configure-item', ['version' => $order['version'], 'item_id' => $order['items'][0]['id'], 'options_version' => $food['options_version'] + 1, 'variant_id' => $food['options'][1]['id'], 'addon_ids' => [], 'quantity' => 2])->assertOk()->assertJsonPath('total', 16000)->json();
        $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk();
    }

    public function test_submitted_snapshots_keep_names_and_prices_and_kitchen_cannot_see_option_prices(): void
    {
        $food = $this->configure();
        $order = $this->order();
        $order = $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], ...$this->choices($food), 'quantity' => 1])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk()->json();
        $this->action('manage/menu-options', ['id' => 1, 'options_version' => $food['options_version'], 'variants' => [['id' => $food['options'][1]['id'], 'name' => 'Family size', 'price' => 9000, 'active' => true]], 'addons' => []])->assertOk();
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'kitchen')->firstOrFail());
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('orders.0.items.0.options_snapshot.0.name', 'Large')->assertJsonMissingPath('orders.0.items.0.options_snapshot.0.price');
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'manager')->firstOrFail());
        $this->action('orders/'.$order['id'].'/pay', ['version' => $order['version'], 'method' => 'cash', 'tendered' => 10000])->assertOk()->assertJsonPath('payment.amount', 8500)->assertJsonPath('items.0.options_snapshot.0.name', 'Large')->assertJsonPath('items.0.options_snapshot.1.name', 'Extra egg');
    }

    public function test_sizes_can_be_disabled_or_removed_and_newly_required_sizes_are_checked_before_sending(): void
    {
        $order = $this->order();
        $order = $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertOk()->json();
        $food = $this->configure();
        $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertUnprocessable();
        $this->action('manage/menu-options', ['id' => 1, 'options_version' => $food['options_version'], 'variants' => [['id' => $food['options'][0]['id'], 'name' => 'Small', 'price' => 5000, 'active' => false]], 'addons' => []])->assertOk();
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('menu.0.orderable', false)->assertJsonPath('menu.0.display_price', null);
        $this->action('manage/menu-options', ['id' => 1, 'options_version' => $food['options_version'] + 1, 'variants' => [], 'addons' => []])->assertOk();
        $this->getJson('/api/v1/state')->assertOk()->assertJsonPath('menu.0.orderable', true)->assertJsonPath('menu.0.display_price', 6500);
        $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk();
    }

    public function test_options_configuration_requires_manager_access_and_belongs_to_its_menu_item(): void
    {
        $food = $this->configure();
        $body = ['id' => 1, 'options_version' => 1, 'variants' => [], 'addons' => []];
        $this->action('manage/menu-options', $body)->assertConflict();
        $this->action('manage/menu-options', [...$body, 'id' => 2, 'variants' => [['id' => $food['options'][0]['id'], 'name' => 'Small', 'price' => 5, 'active' => true]]])->assertUnprocessable();
        $this->action('manage/menu-options', [...$body, 'options_version' => $food['options_version'], 'variants' => [['name' => 'Small', 'price' => -1, 'active' => true]]])->assertUnprocessable()->assertJsonValidationErrors('variants.0.price');
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->action('manage/menu-options', $body)->assertForbidden();
    }
}
