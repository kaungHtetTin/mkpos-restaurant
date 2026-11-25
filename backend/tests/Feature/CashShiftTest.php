<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CashShiftTest extends TestCase
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

    private function payment(string $method = 'cash'): array
    {
        $order = $this->action('orders', ['type' => 'takeaway'])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/add-item', ['version' => $order['version'], 'menu_item_id' => 1, 'quantity' => 1])->assertOk()->json();
        $order = $this->action('orders/'.$order['id'].'/submit', ['version' => $order['version']])->assertOk()->json();

        return $order;
    }

    public function test_shift_tracks_net_cash_other_payments_movements_and_immutable_closing_count(): void
    {
        $key = (string) Str::uuid();
        $shift = $this->action('shifts/open', ['opening_cash' => 10000], $key)->assertOk()->json();
        $this->action('shifts/open', ['opening_cash' => 10000], $key)->assertOk()->assertJsonPath('id', $shift['id']);
        $this->action('shifts/open', ['opening_cash' => 20000])->assertConflict();
        $cash = $this->payment();
        $this->action('orders/'.$cash['id'].'/pay', ['version' => $cash['version'], 'method' => 'cash', 'tendered' => 7000])->assertOk();
        $other = $this->payment();
        $this->action('orders/'.$other['id'].'/pay', ['version' => $other['version'], 'method' => 'other', 'tendered' => 6500])->assertOk();
        $this->assertDatabaseHas('payments', ['order_id' => $cash['id'], 'shift_id' => $shift['id'], 'amount' => 6500, 'change' => 500]);
        $this->action('shifts/movement', ['shift_id' => $shift['id'], 'direction' => 'in', 'amount' => 1000, 'reason' => 'Extra change'])->assertOk();
        $this->action('shifts/movement', ['shift_id' => $shift['id'], 'direction' => 'out', 'amount' => 2000, 'reason' => 'Banked cash'])->assertOk();
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active.expected_cash', 15500)->assertJsonPath('active.cash_sales', 6500)->assertJsonPath('active.other_sales', 6500)->assertJsonPath('active.payment_count', 2);
        $this->action('shifts/close', ['shift_id' => $shift['id'], 'expected_cash' => 15500, 'counted_cash' => 15000])->assertUnprocessable();
        $closeKey = (string) Str::uuid();
        $body = ['shift_id' => $shift['id'], 'expected_cash' => 15500, 'counted_cash' => 15000, 'note' => 'Cash short, reviewed by manager'];
        $this->action('shifts/close', $body, $closeKey)->assertOk()->assertJsonPath('variance', -500);
        $this->action('shifts/close', $body, $closeKey)->assertOk()->assertJsonPath('variance', -500);
        $this->action('shifts/close', [...$body, 'counted_cash' => 15500])->assertConflict();
        $this->action('shifts/movement', ['shift_id' => $shift['id'], 'direction' => 'in', 'amount' => 1, 'reason' => 'Late change'])->assertConflict();
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active', null)->assertJsonPath('history.data.0.expected_cash', 15500)->assertJsonPath('history.data.0.variance', -500);
        $nextOrder = $this->payment();
        $this->action('orders/'.$nextOrder['id'].'/pay', ['version' => $nextOrder['version'], 'method' => 'cash', 'tendered' => 7000])->assertUnprocessable();
        $next = $this->action('shifts/open', ['opening_cash' => 15000])->assertOk()->json();
        $this->assertNotSame($shift['id'], $next['id']);
        $this->action('orders/'.$nextOrder['id'].'/pay', ['version' => $nextOrder['version'], 'method' => 'cash', 'tendered' => 7000])->assertOk();
        $this->assertDatabaseHas('payments', ['order_id' => $nextOrder['id'], 'shift_id' => $next['id']]);
    }

    public function test_shift_rejects_stale_counts_invalid_movements_and_cashier_access_to_another_drawer(): void
    {
        $shift = $this->action('shifts/open', ['opening_cash' => 100])->assertOk()->json();
        $this->action('shifts/movement', ['shift_id' => $shift['id'], 'direction' => 'out', 'amount' => 101, 'reason' => 'Too much'])->assertUnprocessable();
        $this->action('shifts/movement', ['shift_id' => $shift['id'], 'direction' => 'in', 'amount' => 0, 'reason' => 'Invalid'])->assertUnprocessable();
        $this->action('shifts/movement', ['shift_id' => $shift['id'], 'direction' => 'in', 'amount' => 50, 'reason' => 'Change'])->assertOk();
        $this->action('shifts/close', ['shift_id' => $shift['id'], 'expected_cash' => 100, 'counted_cash' => 100])->assertConflict();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->withSession(['password_hash_web' => null])->actingAs($cashier);
        $this->action('shifts/close', ['shift_id' => $shift['id'], 'expected_cash' => 150, 'counted_cash' => 150])->assertForbidden();
        $this->action('shifts/movement', ['shift_id' => $shift['id'], 'direction' => 'out', 'amount' => 1, 'reason' => 'Not opener'])->assertForbidden();
        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'waiter')->firstOrFail());
        $this->getJson('/api/v1/shifts')->assertForbidden();
        $this->action('shifts/open', ['opening_cash' => 100])->assertForbidden();
    }

    public function test_cashier_can_open_and_close_own_shift_and_tenant_history_is_isolated(): void
    {
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $shift = $this->action('shifts/open', ['opening_cash' => 0])->assertOk()->json();
        $this->action('shifts/close', ['shift_id' => $shift['id'], 'expected_cash' => 0, 'counted_cash' => 0])->assertOk()->assertJsonPath('variance', 0);
        $business = DB::table('businesses')->insertGetId(['name' => 'Second', 'code' => 'second', 'setup_completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('restaurant_settings')->insert(['business_id' => $business, 'name' => 'Second']);
        $subscription = (array) DB::table('subscriptions')->where('business_id', 1)->first();
        unset($subscription['id']);
        DB::table('subscriptions')->insert([...$subscription, 'business_id' => $business]);
        $other = User::factory()->create(['business_id' => $business, 'roles' => ['manager'], 'active' => true]);
        $this->withSession(['password_hash_web' => null])->actingAs($other);
        $this->getJson('/api/v1/shifts')->assertOk()->assertJsonPath('active', null)->assertJsonCount(0, 'history.data');
        $this->action('shifts/close', ['shift_id' => $shift['id'], 'expected_cash' => 0, 'counted_cash' => 0])->assertConflict();
        $this->action('shifts/open', ['opening_cash' => 900])->assertOk()->assertJsonPath('business_id', $business)->assertJsonPath('expected_cash', 900);
    }
}
