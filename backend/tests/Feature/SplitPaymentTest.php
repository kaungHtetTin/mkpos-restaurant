<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SplitPaymentTest extends TestCase
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

    private function command(array $order, string $action, array $data = []): array
    {
        return $this->action('orders/'.$order['id'].'/'.$action, ['version' => $order['version'], ...$data])->assertOk()->json();
    }

    private function order(int $quantity = 1, ?int $table = null): array
    {
        $order = $this->action('orders', ['type' => $table ? 'dine_in' : 'takeaway', ...($table ? ['table_id' => $table] : [])])->assertOk()->json();
        $order = $this->command($order, 'add-item', ['menu_item_id' => 1, 'quantity' => $quantity]);
        $order = $this->command($order, 'submit');
        if ($table) {
            $order = $this->command($order, 'ready', ['item_id' => $order['items'][0]['id']]);
            $order = $this->command($order, 'serve', ['item_id' => $order['items'][0]['id']]);
        }

        return $order;
    }

    private function cash(int $amount, ?int $received = null): array
    {
        return ['method' => 'cash', 'amount' => $amount, 'tendered' => $received ?? $amount];
    }

    public function test_partial_payments_keep_tables_occupied_and_are_reported_before_final_settlement(): void
    {
        $this->action('shifts/open', ['opening_cash' => 1000])->assertOk();
        $order = $this->order(table: 1);
        $order = $this->command($order, 'pay', ['mode' => 'amount', 'amount' => 3000, 'tenders' => [$this->cash(3000, 3500)], 'payer_label' => 'Guest one']);
        $this->assertFalse($order['paid']);
        $this->assertSame(6500, $order['total']);
        $this->assertSame(3500, $order['balance_due']);
        $this->assertSame(3000, $order['received_amount']);
        $this->assertCount(1, $order['table_ids']);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('summary.day.amount', 3000);
        $order = $this->command($order, 'pay', ['method' => 'other', 'tendered' => 3500]);
        $this->assertTrue($order['paid']);
        $this->assertSame(0, $order['balance_due']);
        $this->assertCount(2, $order['receipts']);
        $this->assertSame('closed', $order['status']);
        $this->assertDatabaseCount('order_tables', 0);
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active.cash_sales', 3000)->assertJsonPath('active.other_sales', 3500)->assertJsonPath('active.expected_cash', 4000);
        $this->getJson('/api/v1/history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 6500)->assertJsonPath('data.0.receipt_count', 2);
        $this->getJson('/api/v1/history?q='.urlencode($order['receipts'][1]['receipt_number']))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_mixed_tenders_share_one_receipt_and_refunds_and_corrections_apply_to_the_correct_portion(): void
    {
        $this->action('shifts/open', ['opening_cash' => 10000])->assertOk();
        $order = $this->order();
        $order = $this->command($order, 'pay', ['tenders' => [$this->cash(2000, 2500), ['method' => 'other', 'amount' => 4500, 'confirmed' => true]]]);
        $this->assertSame(6500, $order['received_amount']);
        $this->assertCount(1, $order['receipts']);
        $this->assertCount(2, $order['payments']);
        $this->assertSame(6500, $order['receipts'][0]['amount']);
        $this->assertSame(500, $order['payments'][0]['change']);
        $cash = $order['payments'][0];
        $other = $order['payments'][1];
        $this->action('payments/'.$cash['id'].'/refund', ['version' => 1, 'amount' => 1000, 'method' => 'cash', 'reason' => 'Partial return', 'confirmed' => true])->assertOk();
        $this->action('payments/'.$other['id'].'/correct-method', ['version' => 1, 'method' => 'cash', 'reason' => 'Actually received cash', 'confirmed' => true, 'expected_cash_effect' => 4500])->assertOk();
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active.expected_cash', 15500);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('summary.day.amount', 6500)->assertJsonPath('refund_summary.day.net', 5500);
        $this->action('payments/'.$cash['id'].'/refund', ['version' => 2, 'amount' => 1001, 'method' => 'other', 'reason' => 'Too much', 'confirmed' => true])->assertUnprocessable();
    }

    public function test_equal_shares_preserve_every_whole_unit_and_update_the_remaining_people(): void
    {
        $order = $this->order();
        $order = $this->command($order, 'change-price', ['item_id' => $order['items'][0]['id'], 'price' => 10001]);
        $order = $this->command($order, 'pay', ['mode' => 'equal', 'shares' => 3, 'tenders' => [$this->cash(3334)]]);
        $this->assertSame(6667, $order['balance_due']);
        $this->assertSame(2, $order['equal_shares_left']);
        $order = $this->command($order, 'pay', ['mode' => 'equal', 'shares' => 2, 'tenders' => [$this->cash(3334)]]);
        $this->assertSame(3333, $order['balance_due']);
        $order = $this->command($order, 'pay', ['mode' => 'equal', 'shares' => 1, 'tenders' => [$this->cash(3333)]]);
        $this->assertSame(0, $order['equal_shares_left']);
        $this->assertTrue($order['paid']);
        $this->assertSame(10001, array_sum(array_column($order['receipts'], 'amount')));
    }

    public function test_amount_credits_are_reused_when_paying_selected_items_with_rounded_charges(): void
    {
        $this->action('manage/charges', ['tax_rate_bps' => 500, 'service_rate_bps' => 1000, 'tax_label' => 'Tax', 'service_charge_label' => 'Service', 'tax_on_service' => true, 'service_dine_in_only' => false])->assertOk();
        $order = $this->order(2);
        $this->assertSame(15015, $order['total']);
        $itemId = $order['items'][0]['id'];
        $order = $this->command($order, 'pay', ['mode' => 'amount', 'amount' => 2000, 'tenders' => [$this->cash(2000)]]);
        $this->assertSame(5508, $order['settlement_units'][0]['due']);
        $order = $this->command($order, 'pay', ['mode' => 'items', 'items' => [['item_id' => $itemId, 'quantity' => 1]], 'tenders' => [$this->cash(5508)]]);
        $this->assertSame(2000, $order['receipts'][1]['credited_amount']);
        $this->assertSame(7508, $order['receipts'][1]['totals_snapshot']['total']);
        $this->assertSame(358, $order['receipts'][1]['totals_snapshot']['tax_amount']);
        $this->assertSame(7507, $order['balance_due']);
        $this->action('orders/'.$order['id'].'/pay', ['version' => $order['version'], 'mode' => 'items', 'items' => [['item_id' => $itemId, 'quantity' => 2]], 'tenders' => [$this->cash(7507)]])->assertUnprocessable();
        $order = $this->command($order, 'pay', ['mode' => 'items', 'items' => [['item_id' => $itemId, 'quantity' => 1]], 'tenders' => [$this->cash(7507)]]);
        $this->assertTrue($order['paid']);
        $this->assertSame(15015, $order['received_amount']);
        $this->assertSame(15015, (int) DB::table('payment_allocations')->sum('amount'));
    }

    public function test_financial_edits_and_merging_are_locked_after_payment_but_table_moves_still_work(): void
    {
        $order = $this->order(table: 1);
        $target = $this->order(table: 2);
        $order = $this->command($order, 'pay', ['mode' => 'amount', 'amount' => 1000, 'tenders' => [$this->cash(1000)]]);
        foreach (['add-item' => ['menu_item_id' => 1, 'quantity' => 1], 'change-price' => ['item_id' => $order['items'][0]['id'], 'price' => 1], 'discount' => ['discount_type' => 'fixed', 'discount_value' => 1, 'reason' => 'After payment']] as $action => $data) {
            $this->action('orders/'.$order['id'].'/'.$action, ['version' => $order['version'], ...$data])->assertUnprocessable();
        }
        $this->action('orders/'.$target['id'].'/merge', ['version' => $target['version'], 'target_order_id' => $order['id'], 'target_version' => $order['version'], 'reason' => 'Already partly paid'])->assertUnprocessable();
        $order = $this->command($order, 'transfer', ['table_id' => 3, 'reason' => 'Guests moved']);
        $this->assertSame('01', $order['receipts'][0]['table_label']);
        $this->assertSame('03', $order['table_name']);
        $order = $this->command($order, 'pay', ['tenders' => [$this->cash(5500)]]);
        $this->assertSame('03', $order['receipts'][1]['table_label']);
        $this->assertSame(6500, $order['total']);
    }

    public function test_invalid_tender_totals_unconfirmed_other_payments_and_stale_or_duplicate_requests_are_safe(): void
    {
        $order = $this->order();
        $base = ['version' => $order['version'], 'mode' => 'amount', 'amount' => 1000, 'tenders' => [$this->cash(999)]];
        $this->action('orders/'.$order['id'].'/pay', $base)->assertUnprocessable();
        $this->action('orders/'.$order['id'].'/pay', [...$base, 'tenders' => [['method' => 'other', 'amount' => 1000, 'confirmed' => false]]])->assertUnprocessable();
        $this->action('orders/'.$order['id'].'/pay', [...$base, 'tenders' => [$this->cash(1000, 999)]])->assertUnprocessable();
        $key = (string) Str::uuid();
        $body = [...$base, 'tenders' => [$this->cash(1000)]];
        $this->action('orders/'.$order['id'].'/pay', $body, $key)->assertOk();
        $this->action('orders/'.$order['id'].'/pay', $body, $key)->assertOk()->assertJsonCount(1, 'receipts');
        $this->action('orders/'.$order['id'].'/pay', $body)->assertConflict();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_refunding_a_partial_payment_can_cancel_the_unpaid_takeaway_balance_only_after_all_money_is_returned(): void
    {
        $order = $this->order();
        $order = $this->command($order, 'pay', ['mode' => 'amount', 'amount' => 3000, 'tenders' => [$this->cash(3000)]]);
        $payment = $order['payment'];
        $this->action('payments/'.$payment['id'].'/refund', ['version' => 1, 'amount' => 3000, 'method' => 'cash', 'reason' => 'Takeaway canceled', 'confirmed' => true, 'stop_preparation' => true])->assertOk();
        $this->getJson('/api/v1/orders/'.$order['id'])->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('cancelled_balance', 3500)->assertJsonPath('balance_due', 0)->assertJsonPath('total', 6500)->assertJsonCount(1, 'bill_items_snapshot');
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('summary.day.amount', 3000)->assertJsonPath('refund_summary.day.net', 0);
    }
}
