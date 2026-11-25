<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OrderChargesTest extends TestCase
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

    private function rates(array $overrides = []): array
    {
        return ['tax_rate_bps' => 500, 'service_rate_bps' => 1000, 'tax_label' => 'Sales tax', 'service_charge_label' => 'Service charge', 'tax_on_service' => true, 'service_dine_in_only' => false, ...$overrides];
    }

    private function order(string $type = 'takeaway'): array
    {
        $order = $this->action('orders', ['type' => $type, ...($type === 'dine_in' ? ['table_id' => 1] : [])])->assertOk()->json();

        return $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertOk()->json();
    }

    public function test_new_orders_snapshot_rates_and_dine_in_only_service_is_not_applied_to_takeaway(): void
    {
        $old = $this->order();
        $this->action('manage/charges', $this->rates(['service_dine_in_only' => true]))->assertOk();
        $this->getJson('/api/v1/orders/'.$old['id'])->assertOk()->assertJsonPath('total', 6500)->assertJsonPath('tax_rate_bps', 0);
        $takeaway = $this->order();
        $this->assertSame(6825, $takeaway['total']);
        $this->assertSame(0, $takeaway['service_amount']);
        $dineIn = $this->order('dine_in');
        $this->assertSame(650, $dineIn['service_amount']);
        $this->assertSame(358, $dineIn['tax_amount']);
        $this->assertSame(7508, $dineIn['total']);
        $this->action('manage/charges', $this->rates(['tax_rate_bps' => 1000, 'tax_label' => 'Updated tax']))->assertOk();
        $this->getJson('/api/v1/orders/'.$dineIn['id'])->assertOk()->assertJsonPath('total', 7508)->assertJsonPath('tax_label', 'Sales tax');
    }

    public function test_manager_discounts_are_applied_before_charges_and_audited_with_idempotent_retries(): void
    {
        $this->action('manage/charges', $this->rates())->assertOk();
        $order = $this->order();
        $key = (string) Str::uuid();
        $body = ['version' => $order['version'], 'discount_type' => 'percent', 'discount_value' => 1000, 'reason' => 'Approved promotion'];
        $discount = $this->action('orders/'.$order['id'].'/discount', $body, $key)->assertOk()->assertJsonPath('discount_amount', 650)->assertJsonPath('service_amount', 585)->assertJsonPath('tax_amount', 322)->assertJsonPath('total', 6757)->json();
        $this->action('orders/'.$order['id'].'/discount', $body, $key)->assertOk()->assertJsonPath('version', $discount['version']);
        $this->assertSame(1, DB::table('operational_events')->where('action', 'discount')->count());
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'discount_reason' => 'Approved promotion', 'discount_approved_by' => auth()->id()]);
        $fixed = $this->action('orders/'.$order['id'].'/discount', ['version' => $discount['version'], 'discount_type' => 'fixed', 'discount_value' => 500, 'reason' => 'Service recovery'])->assertOk()->assertJsonPath('total', 6930)->json();
        $this->action('orders/'.$order['id'].'/discount', ['version' => $fixed['version'], 'discount_type' => 'none', 'discount_value' => '0', 'reason' => 'Promotion removed'])->assertOk()->assertJsonPath('discount_amount', 0)->assertJsonPath('total', 7508);
        $this->action('orders/'.$order['id'].'/discount', [...$body, 'reason' => 'Stale edit'])->assertConflict();
    }

    public function test_invalid_discounts_and_unauthorized_approvals_are_rejected(): void
    {
        $order = $this->order();
        $body = ['version' => $order['version'], 'discount_type' => 'fixed', 'discount_value' => 6501, 'reason' => 'Too much'];
        $this->action('orders/'.$order['id'].'/discount', $body)->assertUnprocessable();
        $this->action('orders/'.$order['id'].'/discount', [...$body, 'discount_type' => 'percent', 'discount_value' => 10001])->assertUnprocessable();
        $this->action('orders/'.$order['id'].'/discount', [...$body, 'discount_value' => 100, 'reason' => ''])->assertUnprocessable();
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->action('orders/'.$order['id'].'/discount', [...$body, 'discount_value' => 100])->assertForbidden();
        $this->action('manage/charges', $this->rates())->assertForbidden();
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'manager')->firstOrFail());
        $this->action('manage/charges', $this->rates(['tax_rate_bps' => -1]))->assertUnprocessable()->assertJsonValidationErrors('tax_rate_bps');
        $this->action('manage/charges', $this->rates(['service_rate_bps' => 10001]))->assertUnprocessable()->assertJsonValidationErrors('service_rate_bps');
    }

    public function test_fixed_discounts_are_capped_when_contents_change_and_full_discounts_settle_at_zero(): void
    {
        $this->action('manage/charges', $this->rates())->assertOk();
        $order = $this->order();
        $order = $this->action('orders/'.$order['id'].'/discount', ['version' => $order['version'], 'discount_type' => 'fixed', 'discount_value' => 6500, 'reason' => 'Complimentary meal'])->assertOk()->assertJsonPath('total', 0)->json();
        $order = $this->action('orders/'.$order['id'].'/change-price', ['version' => $order['version'], 'item_id' => $order['items'][0]['id'], 'price' => 3000])->assertOk()->assertJsonPath('discount_amount', 3000)->assertJsonPath('total', 0)->json();
        $order = $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk()->json();
        $this->action('orders/'.$order['id'].'/pay', ['version' => $order['version'], 'method' => 'cash', 'tendered' => 0])->assertOk()->assertJsonPath('payment.amount', 0);
    }

    public function test_paid_breakdown_is_snapshotted_and_matches_sales_and_cash_drawer(): void
    {
        $this->action('manage/charges', $this->rates())->assertOk();
        $this->action('shifts/open', ['opening_cash' => 1000])->assertOk();
        $order = $this->order();
        $order = $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/pay', ['version' => $order['version'], 'method' => 'cash', 'tendered' => 8000, 'total' => 1])->assertOk()->assertJsonPath('payment.amount', 7508)->assertJsonPath('payment.change', 492)->assertJsonPath('payment.totals_snapshot.tax_amount', 358)->json();
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active.expected_cash', 8508);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('summary.day.amount', 7508);
        $this->action('manage/charges', $this->rates(['tax_rate_bps' => 0, 'service_rate_bps' => 0]))->assertOk();
        $this->getJson('/api/v1/orders/'.$order['id'])->assertOk()->assertJsonPath('total', 7508)->assertJsonPath('payment.totals_snapshot.tax_label', 'Sales tax');
        $this->action('orders/'.$order['id'].'/discount', ['version' => $order['version'], 'discount_type' => 'fixed', 'discount_value' => 1, 'reason' => 'After payment'])->assertUnprocessable();
    }

    public function test_tax_without_service_in_taxable_base_and_fractional_rates_round_to_whole_units(): void
    {
        $this->action('manage/charges', $this->rates(['tax_rate_bps' => 725, 'service_rate_bps' => 250, 'tax_on_service' => false]))->assertOk();
        $order = $this->order();
        $this->action('orders/'.$order['id'].'/change-price', ['version' => $order['version'], 'item_id' => $order['items'][0]['id'], 'price' => 101])->assertOk()->assertJsonPath('service_amount', 3)->assertJsonPath('tax_amount', 7)->assertJsonPath('total', 111);
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'kitchen')->firstOrFail());
        $this->getJson('/api/v1/state')->assertOk()->assertJsonMissingPath('orders.0.subtotal')->assertJsonMissingPath('orders.0.discount_amount')->assertJsonMissingPath('orders.0.tax_amount')->assertJsonMissingPath('orders.0.service_amount');
    }
}
