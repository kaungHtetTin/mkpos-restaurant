<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentAdjustmentTest extends TestCase
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

    private function paidOrder(string $method = 'cash'): array
    {
        $order = $this->action('orders', ['type' => 'takeaway'])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk()->json();

        return $this->action('orders/'.$order['id'].'/pay', ['version' => $order['version'], 'method' => $method, 'tendered' => 7000])->assertOk()->json();
    }

    private function payment(int $orderId): array
    {
        return $this->getJson('/api/v1/orders/'.$orderId)->assertOk()->json('payment');
    }

    public function test_refunds_and_method_corrections_preserve_the_original_payment_and_reconcile_the_drawer(): void
    {
        $this->action('shifts/open', ['opening_cash' => 10000])->assertOk();
        $order = $this->paidOrder();
        $payment = $order['payment'];
        $body = ['version' => 1, 'amount' => 1000, 'method' => 'cash', 'reason' => 'Approved partial refund', 'confirmed' => true];
        $key = (string) Str::uuid();
        $this->action('payments/'.$payment['id'].'/refund', $body, $key)->assertOk();
        $this->action('payments/'.$payment['id'].'/refund', $body, $key)->assertOk();
        $this->assertDatabaseCount('payment_adjustments', 1);
        $updated = $this->payment($order['id']);
        $this->assertSame(1000, $updated['refunded_amount']);
        $this->assertSame(5500, $updated['refundable_amount']);
        $this->assertSame(6500, $updated['amount']);
        $this->assertSame('Cash', $updated['method_label']);
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active.expected_cash', 15500);
        $this->action('payments/'.$payment['id'].'/correct-method', ['version' => 2, 'method' => 'other', 'reason' => 'Actually received by QR', 'confirmed' => true, 'expected_cash_effect' => -6500])->assertOk();
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active.cash_sales', 0)->assertJsonPath('active.other_sales', 6500)->assertJsonPath('active.cash_refunds', 1000)->assertJsonPath('active.expected_cash', 9000);
        $updated = $this->payment($order['id']);
        $this->assertSame('cash', $updated['method']);
        $this->assertSame('other', $updated['effective_method']);
        $this->assertSame($payment['items_snapshot'], $updated['items_snapshot']);
        $this->action('payments/'.$payment['id'].'/refund', ['version' => 3, 'amount' => 500, 'method' => 'other', 'reason' => 'Additional QR refund', 'confirmed' => true])->assertOk();
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active.expected_cash', 9000);
        $this->getJson('/api/v1/sales?method=other')->assertOk()->assertJsonPath('summary.day.amount', 6500)->assertJsonPath('refund_summary.day.refunded', 1500)->assertJsonPath('refund_summary.day.net', 5000)->assertJsonPath('sales.data.0.method', 'other')->assertJsonPath('sales.data.0.refunded_amount', 1500);
        $this->action('payments/'.$payment['id'].'/refund', ['version' => 4, 'amount' => 5001, 'method' => 'other', 'reason' => 'Too much', 'confirmed' => true])->assertUnprocessable();
        $this->action('payments/'.$payment['id'].'/refund', ['version' => 4, 'amount' => 5000, 'method' => 'other', 'reason' => 'Remaining full refund', 'confirmed' => true])->assertOk();
        $this->assertSame(0, $this->payment($order['id'])['refundable_amount']);
    }

    public function test_full_takeaway_refund_can_stop_preparation_without_changing_original_receipt_items(): void
    {
        $order = $this->paidOrder();
        $payment = $order['payment'];
        $this->action('payments/'.$payment['id'].'/refund', ['version' => 1, 'amount' => 6500, 'method' => 'cash', 'reason' => 'Customer cancelled takeaway', 'confirmed' => true, 'stop_preparation' => true])->assertOk();
        $updated = $this->getJson('/api/v1/orders/'.$order['id'])->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('paid', true)->assertJsonPath('items.0.status', 'cancelled')->assertJsonPath('payment.amount', 6500)->assertJsonPath('payment.refunded_amount', 6500)->assertJsonCount(1, 'payment.items_snapshot')->json();
        $this->assertSame($payment['items_snapshot'], $updated['payment']['items_snapshot']);
        $this->getJson('/api/v1/state')->assertOk()->assertJsonCount(1, 'orders');
        $this->action('orders/'.$order['id'].'/acknowledge', ['version' => $updated['version'], 'item_id' => $updated['items'][0]['id']])->assertOk();
        $this->getJson('/api/v1/state')->assertOk()->assertJsonCount(0, 'orders');
        $this->getJson('/api/v1/history?status=refunded')->assertOk()->assertJsonPath('data.0.refunded_amount', 6500);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('summary.day.amount', 6500)->assertJsonPath('refund_summary.day.net', 0);
    }

    public function test_adjusting_old_payments_preserves_closed_shift_counts_and_refunds_use_the_current_drawer(): void
    {
        $shift = $this->action('shifts/open', ['opening_cash' => 1000])->assertOk()->json();
        $order = $this->paidOrder();
        $payment = $order['payment'];
        $this->action('shifts/close', ['shift_id' => $shift['id'], 'expected_cash' => 7500, 'counted_cash' => 7500])->assertOk();
        $this->action('payments/'.$payment['id'].'/correct-method', ['version' => 1, 'method' => 'other', 'reason' => 'Reviewed previous shift', 'confirmed' => true, 'expected_cash_effect' => 0])->assertOk();
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('history.data.0.expected_cash', 7500)->assertJsonPath('history.data.0.cash_sales', 6500)->assertJsonPath('history.data.0.variance', 0);
        $this->action('payments/'.$payment['id'].'/refund', ['version' => 2, 'amount' => 1000, 'method' => 'cash', 'reason' => 'Cash refund', 'confirmed' => true])->assertUnprocessable();
        $this->action('shifts/open', ['opening_cash' => 5000])->assertOk();
        $this->action('payments/'.$payment['id'].'/refund', ['version' => 2, 'amount' => 1000, 'method' => 'cash', 'reason' => 'Cash refund', 'confirmed' => true])->assertOk();
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active.expected_cash', 4000)->assertJsonPath('active.cash_refunds', 1000)->assertJsonPath('history.data.0.expected_cash', 7500);
    }

    public function test_stale_versions_cash_effect_changes_and_unauthorized_adjustments_are_rejected(): void
    {
        $this->action('shifts/open', ['opening_cash' => 0])->assertOk();
        $order = $this->paidOrder();
        $payment = $order['payment'];
        $body = ['version' => 1, 'amount' => 500, 'method' => 'cash', 'reason' => 'Refund', 'confirmed' => true];
        $this->action('payments/'.$payment['id'].'/correct-method', ['version' => 1, 'method' => 'other', 'reason' => 'Wrong cash impact', 'confirmed' => true, 'expected_cash_effect' => 0])->assertConflict();
        $this->action('payments/'.$payment['id'].'/refund', [...$body, 'confirmed' => false])->assertUnprocessable();
        $this->action('payments/'.$payment['id'].'/refund', [...$body, 'expected_shift_id' => 999999])->assertConflict();
        $this->action('payments/'.$payment['id'].'/refund', $body)->assertOk();
        $this->action('payments/'.$payment['id'].'/refund', $body)->assertConflict();
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->action('payments/'.$payment['id'].'/refund', [...$body, 'version' => 2])->assertForbidden();
        $this->action('payments/'.$payment['id'].'/correct-method', ['version' => 2, 'method' => 'other', 'reason' => 'Unauthorized', 'confirmed' => true, 'expected_cash_effect' => -6500])->assertForbidden();
    }

    public function test_refunds_are_reported_on_issue_date_and_corrections_do_not_create_sales(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00:00', 'UTC'));
        DB::table('subscriptions')->update(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $order = $this->paidOrder('other');
        $payment = $order['payment'];
        $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'UTC'));
        $this->action('payments/'.$payment['id'].'/refund', ['version' => 1, 'amount' => 1000, 'method' => 'other', 'reason' => 'Next day refund', 'confirmed' => true])->assertOk();
        $this->action('payments/'.$payment['id'].'/correct-method', ['version' => 2, 'method' => 'cash', 'reason' => 'Actually paid cash', 'confirmed' => true, 'expected_cash_effect' => 0])->assertOk();
        $this->getJson('/api/v1/sales?date=2026-11-10&method=cash')->assertOk()->assertJsonPath('summary.day.amount', 6500)->assertJsonPath('refund_summary.day.refunded', 0)->assertJsonPath('refund_summary.day.net', 6500)->assertJsonPath('sales.total', 1)->assertJsonPath('sales.data.0.refunded_amount', 1000);
        $this->getJson('/api/v1/sales?date=2026-11-11')->assertOk()->assertJsonPath('summary.day.amount', 0)->assertJsonPath('refund_summary.day.refunded', 1000)->assertJsonPath('refund_summary.day.net', -1000)->assertJsonPath('adjustments.total', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'method' => 'other', 'method_label' => 'QR / Card', 'effective_method' => 'cash']);
    }

    public function test_a_method_correction_can_expose_a_negative_expected_drawer_without_rewriting_movements(): void
    {
        $shift = $this->action('shifts/open', ['opening_cash' => 0])->assertOk()->json();
        $order = $this->paidOrder();
        $this->action('shifts/movement', ['shift_id' => $shift['id'], 'direction' => 'out', 'amount' => 6500, 'reason' => 'Recorded banking'])->assertOk();
        $this->action('payments/'.$order['payment']['id'].'/correct-method', ['version' => 1, 'method' => 'other', 'reason' => 'Wrongly recorded as cash', 'confirmed' => true, 'expected_cash_effect' => -6500])->assertOk();
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active.expected_cash', -6500);
        $this->action('shifts/close', ['shift_id' => $shift['id'], 'expected_cash' => -6500, 'counted_cash' => 0, 'note' => 'Manager reviewed the banking and correction discrepancy'])->assertOk()->assertJsonPath('variance', 6500);
    }

    public function test_insufficient_cash_invalid_stop_and_cross_tenant_payment_access_do_not_write_adjustments(): void
    {
        $this->action('shifts/open', ['opening_cash' => 100])->assertOk();
        $order = $this->paidOrder('other');
        $payment = $order['payment'];
        $body = ['version' => 1, 'amount' => 500, 'method' => 'cash', 'reason' => 'Refund', 'confirmed' => true];
        $this->action('payments/'.$payment['id'].'/refund', $body)->assertUnprocessable();
        $this->action('payments/'.$payment['id'].'/refund', [...$body, 'method' => 'other', 'stop_preparation' => true])->assertUnprocessable();
        $this->assertDatabaseCount('payment_adjustments', 0);
        $business = DB::table('businesses')->insertGetId(['name' => 'Other', 'code' => 'other', 'setup_completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('restaurant_settings')->insert(['business_id' => $business, 'name' => 'Other']);
        $subscription = (array) DB::table('subscriptions')->where('business_id', 1)->first();
        unset($subscription['id']);
        DB::table('subscriptions')->insert([...$subscription, 'business_id' => $business]);
        $other = User::factory()->create(['business_id' => $business, 'roles' => ['manager'], 'active' => true]);
        $this->withSession(['password_hash_web' => null])->actingAs($other);
        $this->action('payments/'.$payment['id'].'/refund', $body)->assertNotFound();
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('adjustments.total', 0)->assertJsonPath('refund_summary.day.refunded', 0);
    }
}
