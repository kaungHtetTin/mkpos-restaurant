<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TableTransferTest extends TestCase
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

    private function order(int $table, bool $food = true): array
    {
        $order = $this->action('orders', ['type' => 'dine_in', 'table_id' => $table])->assertOk()->json();

        return $food ? $this->command($order, 'add-item', ['menu_item_id' => 1, 'quantity' => 1, 'notes' => 'No onion']) : $order;
    }

    private function command(array $order, string $action, array $data = []): array
    {
        return $this->action('orders/'.$order['id'].'/'.$action, ['version' => $order['version'], ...$data])->assertOk()->json();
    }

    private function merge(array $source, array $target): array
    {
        return $this->command($source, 'merge', ['target_order_id' => $target['id'], 'target_version' => $target['version'], 'reason' => 'Dining together']);
    }

    public function test_transfer_preserves_items_and_tickets_and_releases_the_old_table(): void
    {
        $order = $this->command($this->order(1), 'submit');
        $ticket = $order['items'][0]['ticket_id'];
        $body = ['version' => $order['version'], 'table_id' => 3, 'reason' => 'Guest requested a quieter table'];
        $key = (string) Str::uuid();
        $moved = $this->action('orders/'.$order['id'].'/transfer', $body, $key)->assertOk()->assertJsonPath('table_name', '03')->assertJsonPath('active_table_id', 3)->assertJsonPath('items.0.ticket_id', $ticket)->assertJsonPath('items.0.status', 'sent')->assertJsonPath('total', 6500)->json();
        $this->action('orders/'.$order['id'].'/transfer', $body, $key)->assertOk()->assertJsonPath('version', $moved['version']);
        $this->assertDatabaseHas('kitchen_tickets', ['id' => $ticket, 'table_name' => '03']);
        $this->assertDatabaseMissing('order_tables', ['table_id' => 1]);
        $this->action('orders', ['type' => 'dine_in', 'table_id' => 3])->assertOk()->assertJsonPath('id', $order['id']);
        $new = $this->order(1, false);
        $this->assertNotSame($order['id'], $new['id']);
        $this->assertSame(1, DB::table('operational_events')->where('action', 'transfer')->count());
    }

    public function test_transfer_and_merge_reject_occupied_inactive_stale_and_non_dine_in_destinations(): void
    {
        $source = $this->order(1);
        $target = $this->order(2);
        $this->action('orders/'.$source['id'].'/transfer', ['version' => $source['version'], 'table_id' => 2, 'reason' => 'Occupied'])->assertConflict();
        DB::table('dining_tables')->where('id', 3)->update(['active' => false]);
        $this->action('orders/'.$source['id'].'/transfer', ['version' => $source['version'], 'table_id' => 3, 'reason' => 'Inactive'])->assertUnprocessable();
        $this->action('orders/'.$source['id'].'/transfer', ['version' => 1, 'table_id' => 4, 'reason' => 'Stale'])->assertConflict();
        $this->action('orders/'.$source['id'].'/merge', ['version' => $source['version'], 'target_order_id' => $target['id'], 'target_version' => 1, 'reason' => 'Stale target'])->assertConflict();
        $this->action('orders/'.$source['id'].'/merge', ['version' => $source['version'], 'target_order_id' => $source['id'], 'target_version' => $source['version'], 'reason' => 'Same order'])->assertUnprocessable();
        $takeaway = $this->action('orders', ['type' => 'takeaway'])->assertOk()->json();
        $this->action('orders/'.$takeaway['id'].'/transfer', ['version' => $takeaway['version'], 'table_id' => 4, 'reason' => 'Invalid type'])->assertUnprocessable();
        $this->action('orders/'.$source['id'].'/merge', ['version' => $source['version'], 'target_order_id' => $takeaway['id'], 'target_version' => $takeaway['version'], 'reason' => 'Invalid type'])->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['id' => $source['id'], 'status' => 'open', 'active_table_id' => 1]);
        $this->assertDatabaseCount('order_tables', 2);
    }

    public function test_merging_keeps_original_discounts_rates_tickets_and_clears_all_tables_on_payment(): void
    {
        $rates = ['tax_rate_bps' => 500, 'service_rate_bps' => 1000, 'tax_label' => 'Tax', 'service_charge_label' => 'Service', 'tax_on_service' => false, 'service_dine_in_only' => true];
        $this->action('manage/charges', $rates)->assertOk();
        $source = $this->order(1);
        $source = $this->command($source, 'discount', ['discount_type' => 'percent', 'discount_value' => 1000, 'reason' => 'Promotion']);
        $source = $this->command($source, 'submit');
        $source = $this->command($source, 'ready', ['item_id' => $source['items'][0]['id']]);
        $source = $this->command($source, 'serve', ['item_id' => $source['items'][0]['id']]);
        $this->action('manage/charges', [...$rates, 'tax_rate_bps' => 725, 'service_rate_bps' => 250, 'tax_on_service' => true])->assertOk();
        $target = $this->order(2);
        $target = $this->command($target, 'discount', ['discount_type' => 'fixed', 'discount_value' => 500, 'reason' => 'Voucher']);
        $beforeTotal = $source['total'] + $target['total'];
        $key = (string) Str::uuid();
        $body = ['version' => $source['version'], 'target_order_id' => $target['id'], 'target_version' => $target['version'], 'reason' => 'Same party'];
        $combined = $this->action('orders/'.$source['id'].'/merge', $body, $key)->assertOk()->assertJsonPath('id', $target['id'])->assertJsonPath('total', 13324)->assertJsonPath('discount_amount', 1150)->assertJsonPath('tax_amount', 739)->assertJsonPath('table_name', '01 + 02')->assertJsonCount(2, 'table_ids')->assertJsonCount(2, 'billing_groups')->json();
        $this->assertSame($beforeTotal, $combined['total']);
        $this->action('orders/'.$source['id'].'/merge', $body, $key)->assertOk()->assertJsonCount(2, 'items');
        $this->assertDatabaseHas('kitchen_tickets', ['id' => $source['items'][0]['ticket_id'], 'order_id' => $target['id'], 'table_name' => '01']);
        $this->assertDatabaseHas('order_items', ['id' => $source['items'][0]['id'], 'order_id' => $target['id'], 'origin_order_id' => $source['id'], 'status' => 'served']);
        $this->action('manage/tables', ['id' => 1, 'name' => '01', 'seats' => 4, 'active' => false])->assertUnprocessable();
        $this->action('orders', ['type' => 'dine_in', 'table_id' => 1])->assertOk()->assertJsonPath('id', $target['id']);
        $combined = $this->command($combined, 'discount', ['group_id' => $source['id'], 'discount_type' => 'fixed', 'discount_value' => 1000, 'reason' => 'Revised promotion']);
        $this->assertSame(12921, $combined['total']);
        $combined = $this->command($combined, 'submit');
        $pending = collect($combined['items'])->firstWhere('status', 'sent');
        $combined = $this->command($combined, 'ready', ['item_id' => $pending['id']]);
        $combined = $this->command($combined, 'serve', ['item_id' => $pending['id']]);
        $paid = $this->command($combined, 'pay', ['method' => 'cash', 'tendered' => 15000]);
        $this->assertSame(12921, $paid['payment']['amount']);
        $this->assertCount(2, $paid['payment']['totals_snapshot']['billing_groups']);
        $this->assertDatabaseCount('order_tables', 0);
        $this->assertDatabaseCount('payments', 1);
        $this->getJson('/api/v1/history?status=merged')->assertOk()->assertJsonPath('data.0.merged_into_id', $target['id']);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('summary.day.amount', 12921);
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'waiter')->firstOrFail());
        $this->getJson('/api/v1/orders/'.$source['id'])->assertOk()->assertJsonPath('status', 'merged')->assertJsonPath('merged_into_id', $target['id']);
    }

    public function test_nested_merges_keep_one_bill_and_group_transfer_releases_every_previous_table(): void
    {
        $a = $this->order(1);
        $b = $this->order(2);
        $c = $this->order(3);
        $b = $this->merge($a, $b);
        $c = $this->merge($b, $c);
        $this->assertSame(19500, $c['total']);
        $this->assertCount(3, $c['table_ids']);
        $this->assertCount(3, $c['billing_groups']);
        $this->getJson('/api/v1/orders/'.$a['id'])->assertOk()->assertJsonPath('merged_into_id', $c['id']);
        $this->action('orders', ['type' => 'dine_in', 'table_id' => 2])->assertOk()->assertJsonPath('id', $c['id']);
        $moved = $this->command($c, 'transfer', ['table_id' => 4, 'reason' => 'Whole group moved']);
        $this->assertSame([4], $moved['table_ids']);
        $this->assertSame(['04'], $moved['table_names_snapshot']);
        $this->assertSame(19500, $moved['total']);
        $this->assertDatabaseCount('order_tables', 1);
        $this->assertCount(3, $moved['billing_groups']);
    }

    public function test_kitchen_permissions_and_cross_business_table_and_order_access_are_enforced(): void
    {
        $source = $this->order(1);
        $target = $this->order(2);
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'kitchen')->firstOrFail());
        $this->action('orders/'.$source['id'].'/transfer', ['version' => $source['version'], 'table_id' => 3, 'reason' => 'Not allowed'])->assertForbidden();
        $this->action('orders/'.$source['id'].'/merge', ['version' => $source['version'], 'target_order_id' => $target['id'], 'target_version' => $target['version'], 'reason' => 'Not allowed'])->assertForbidden();
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'manager')->firstOrFail());
        $business = DB::table('businesses')->insertGetId(['name' => 'Other', 'code' => 'other', 'setup_completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $foreignTable = DB::table('dining_tables')->insertGetId(['business_id' => $business, 'name' => 'Foreign', 'seats' => 4, 'active' => true]);
        $foreignOrder = DB::table('orders')->insertGetId(['business_id' => $business, 'number' => 'FOREIGN', 'type' => 'dine_in', 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $this->action('orders/'.$source['id'].'/transfer', ['version' => $source['version'], 'table_id' => $foreignTable, 'reason' => 'Wrong tenant'])->assertUnprocessable();
        $this->action('orders/'.$source['id'].'/merge', ['version' => $source['version'], 'target_order_id' => $foreignOrder, 'target_version' => 1, 'reason' => 'Wrong tenant'])->assertUnprocessable();
        $this->action('orders/'.$source['id'].'/discount', ['version' => $source['version'], 'group_id' => $target['id'], 'discount_type' => 'fixed', 'discount_value' => 1, 'reason' => 'Wrong group'])->assertUnprocessable();
    }
}
