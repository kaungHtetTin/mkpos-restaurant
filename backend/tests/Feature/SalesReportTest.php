<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function signIn(string $username = 'manager'): void
    {
        $this->seed();
        $this->actingAs(User::where('username', $username)->firstOrFail());
    }

    private function sale(string $paidAt, int $amount, array $attributes = [], string $method = 'cash'): int
    {
        $this->sequence++;
        $order = [
            'number' => 'TEST-'.str_pad((string) $this->sequence, 6, '0', STR_PAD_LEFT),
            'type' => 'dine_in', 'table_name' => '01', 'paid' => true, 'status' => 'closed',
            'created_by' => $this->userId(), 'created_at' => '2024-01-01 00:00:00',
            'updated_at' => $paidAt, 'closed_at' => $paidAt, ...$attributes,
        ];
        $id = DB::table('orders')->insertGetId($order);
        if ($order['paid']) {
            DB::table('payments')->insert([
                'order_id' => $id, 'receipt_number' => 'RS-'.str_pad((string) $this->sequence, 6, '0', STR_PAD_LEFT),
                'amount' => $amount, 'method' => $method, 'method_label' => $method === 'cash' ? 'Cash' : 'QR / Card',
                'tendered' => $amount + 10000, 'change' => 10000, 'recorded_by' => $this->userId(),
                'restaurant_snapshot' => json_encode(['name' => 'Little Lemon', 'currency' => 'MMK']), 'created_at' => $paidAt,
            ]);
        }

        return $id;
    }

    private function userId(): int
    {
        return (int) User::where('username', 'manager')->value('id');
    }

    public function test_daily_monthly_and_yearly_totals_use_local_payment_boundaries(): void
    {
        $this->signIn();
        foreach ([
            ['2026-11-09 17:29:59', 100], ['2026-11-09 17:30:00', 200],
            ['2026-11-10 17:29:59', 300], ['2026-11-10 17:30:00', 400],
            ['2026-10-31 17:30:00', 500], ['2026-10-31 17:29:59', 600],
            ['2025-12-31 17:30:00', 700], ['2025-12-31 17:29:59', 800],
            ['2026-12-31 17:29:59', 900], ['2026-12-31 17:30:00', 1000],
        ] as [$date, $amount]) {
            $this->sale($date, $amount);
        }
        $this->getJson('/api/v1/sales?date=2026-11-10')->assertOk()
            ->assertJsonPath('summary.day', ['amount' => 500, 'count' => 2])
            ->assertJsonPath('summary.month', ['amount' => 1500, 'count' => 5])
            ->assertJsonPath('summary.year', ['amount' => 3700, 'count' => 8])
            ->assertJsonPath('filtered.amount', 500)->assertJsonCount(2, 'sales.data');
        $this->getJson('/api/v1/sales?date=2026-11-10&period=month')->assertOk()
            ->assertJsonPath('filtered.amount', 1500)->assertJsonPath('sales.total', 5)
            ->assertJsonPath('from', '2026-11-01')->assertJsonPath('to', '2026-11-30');
        $this->getJson('/api/v1/sales?date=2026-11-10&period=year')->assertOk()
            ->assertJsonPath('filtered.amount', 3700)->assertJsonPath('sales.total', 8)
            ->assertJsonPath('from', '2026-01-01')->assertJsonPath('to', '2026-12-31');
    }

    public function test_paid_open_takeaway_is_included_and_unpaid_or_cancelled_orders_are_excluded(): void
    {
        $this->signIn();
        $paid = $this->sale('2026-11-10 08:00:00', 6500, ['type' => 'takeaway', 'table_name' => null, 'status' => 'open', 'closed_at' => null]);
        $this->sale('2026-11-10 08:00:00', 100000, ['paid' => false, 'status' => 'open']);
        $this->sale('2026-11-10 08:00:00', 200000, ['status' => 'cancelled']);
        $this->getJson('/api/v1/sales?date=2026-11-10')->assertOk()->assertJsonPath('summary.day.amount', 6500)
            ->assertJsonPath('sales.total', 1)->assertJsonPath('sales.data.0.order_id', $paid)
            ->assertJsonPath('sales.data.0.cashier_name', 'Manager');
    }

    public function test_record_filters_and_matching_amount_do_not_change_calendar_totals(): void
    {
        $this->signIn();
        $this->sale('2026-11-10 08:00:00', 6500);
        $match = $this->sale('2026-11-10 09:00:00', 2500, ['type' => 'takeaway', 'table_name' => null, 'customer_name' => 'PICKUP customer'], 'other');
        $this->sale('2026-11-10 10:00:00', 3000, ['type' => 'takeaway', 'table_name' => null]);
        $this->sale('2026-11-10 11:00:00', 0);
        $this->getJson('/api/v1/sales?date=2026-11-10&type=takeaway&method=other&q=PICKUP')->assertOk()
            ->assertJsonPath('summary.day', ['amount' => 12000, 'count' => 4])
            ->assertJsonPath('filtered', ['amount' => 2500, 'count' => 1])->assertJsonPath('sales.data.0.order_id', $match);
        foreach (['RS-000002', 'TEST-000002'] as $search) {
            $this->getJson('/api/v1/sales?date=2026-11-10&q='.$search)->assertOk()->assertJsonPath('filtered.amount', 2500);
        }
        $this->getJson('/api/v1/sales?date=2026-11-10&q=01&type=dine_in&method=cash')->assertOk()
            ->assertJsonPath('filtered', ['amount' => 6500, 'count' => 2]);
    }

    public function test_pagination_keeps_the_full_matching_amount_and_stable_order(): void
    {
        $this->signIn();
        foreach (range(1, 27) as $index) {
            $this->sale('2026-11-10 08:00:00', 100);
        }
        $this->getJson('/api/v1/sales?date=2026-11-10&page=2')->assertOk()
            ->assertJsonPath('filtered', ['amount' => 2700, 'count' => 27])
            ->assertJsonPath('sales.total', 27)->assertJsonPath('sales.current_page', 2)
            ->assertJsonCount(2, 'sales.data')->assertJsonPath('sales.data.0.order_number', 'TEST-000002');
    }

    public function test_empty_period_has_zero_totals_and_no_records(): void
    {
        $this->signIn();
        $this->getJson('/api/v1/sales?date=2026-11-10')->assertOk()
            ->assertJsonPath('summary.day', ['amount' => 0, 'count' => 0])
            ->assertJsonPath('summary.month', ['amount' => 0, 'count' => 0])
            ->assertJsonPath('summary.year', ['amount' => 0, 'count' => 0])
            ->assertJsonPath('filtered', ['amount' => 0, 'count' => 0])->assertJsonCount(0, 'sales.data');
    }

    public function test_default_date_uses_the_restaurant_timezone(): void
    {
        $this->signIn();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 18:00:00', 'UTC'));
        $this->sale('2026-11-09 17:30:00', 100);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('date', '2026-11-10')->assertJsonPath('summary.day.amount', 100);
    }

    public function test_daylight_saving_day_uses_local_midnight_for_both_ends(): void
    {
        $this->signIn();
        DB::table('restaurant_settings')->update(['timezone' => 'America/New_York']);
        $this->sale('2026-03-08 04:59:59', 40);
        $this->sale('2026-03-08 05:00:00', 10);
        $this->sale('2026-03-09 03:59:59', 20);
        $this->sale('2026-03-09 04:00:00', 80);
        $this->getJson('/api/v1/sales?date=2026-03-08')->assertOk()->assertJsonPath('summary.day', ['amount' => 30, 'count' => 2]);
    }

    public function test_leap_day_is_included_in_february_and_the_year(): void
    {
        $this->signIn();
        $this->sale('2024-02-28 08:00:00', 20);
        $this->sale('2024-02-29 08:00:00', 10);
        $this->sale('2024-03-01 08:00:00', 40);
        $this->getJson('/api/v1/sales?date=2024-02-29&period=month')->assertOk()
            ->assertJsonPath('summary.day.amount', 10)->assertJsonPath('summary.month.amount', 30)
            ->assertJsonPath('summary.year.amount', 70)->assertJsonPath('to', '2024-02-29');
    }

    public function test_daily_chart_groups_by_local_hour_and_keeps_zero_hours(): void
    {
        $this->signIn();
        $this->sale('2026-11-09 17:30:00', 100);
        $this->sale('2026-11-09 18:29:59', 200);
        $this->sale('2026-11-09 18:30:00', 400);
        $this->sale('2026-11-10 17:29:59', 800);
        $this->sale('2026-11-10 17:30:00', 1600);
        $report = $this->getJson('/api/v1/sales?date=2026-11-10&q=no-match')->assertOk()
            ->assertJsonPath('chart.interval', 'hour')->assertJsonCount(24, 'chart.points')
            ->assertJsonPath('chart.points.0.label', '00:00')->assertJsonPath('chart.points.0.amount', 300)
            ->assertJsonPath('chart.points.0.count', 2)->assertJsonPath('chart.points.1.amount', 400)
            ->assertJsonPath('chart.points.2.amount', 0)->assertJsonPath('chart.points.23.amount', 800)
            ->assertJsonPath('filtered.amount', 0)->json();
        $this->assertSame(1500, array_sum(array_column($report['chart']['points'], 'amount')));
        $this->assertSame($report['summary']['day']['amount'], array_sum(array_column($report['chart']['points'], 'amount')));
    }

    public function test_monthly_chart_includes_leap_day_and_yearly_chart_includes_all_months(): void
    {
        $this->signIn();
        $this->sale('2024-01-31 17:30:00', 100);
        $this->sale('2024-02-29 17:29:59', 200);
        $this->sale('2024-02-29 17:30:00', 400);
        $this->getJson('/api/v1/sales?date=2024-02-10&period=month')->assertOk()
            ->assertJsonPath('chart.interval', 'day')->assertJsonCount(29, 'chart.points')
            ->assertJsonPath('chart.points.0.amount', 100)->assertJsonPath('chart.points.28.amount', 200)
            ->assertJsonPath('chart.points.1.count', 0);
        $this->getJson('/api/v1/sales?date=2024-02-10&period=year')->assertOk()
            ->assertJsonPath('chart.interval', 'month')->assertJsonCount(12, 'chart.points')
            ->assertJsonPath('chart.points.0.label', 'Jan')->assertJsonPath('chart.points.0.amount', 0)
            ->assertJsonPath('chart.points.1.amount', 300)->assertJsonPath('chart.points.2.amount', 400)
            ->assertJsonPath('chart.points.11.label', 'Dec');
    }

    public function test_daylight_saving_charts_preserve_skipped_and_repeated_hours(): void
    {
        $this->signIn();
        DB::table('restaurant_settings')->update(['timezone' => 'America/New_York']);
        $this->sale('2026-03-08 06:30:00', 100);
        $this->sale('2026-03-08 07:30:00', 200);
        $this->getJson('/api/v1/sales?date=2026-03-08')->assertOk()->assertJsonCount(23, 'chart.points')
            ->assertJsonPath('chart.points.1.label', '01:00')->assertJsonPath('chart.points.1.amount', 100)
            ->assertJsonPath('chart.points.2.label', '03:00')->assertJsonPath('chart.points.2.amount', 200);
        $this->sale('2026-11-01 05:30:00', 400);
        $this->sale('2026-11-01 06:30:00', 800);
        $this->getJson('/api/v1/sales?date=2026-11-01')->assertOk()->assertJsonCount(25, 'chart.points')
            ->assertJsonPath('chart.points.1.label', '01:00')->assertJsonPath('chart.points.1.amount', 400)
            ->assertJsonPath('chart.points.2.label', '01:00')->assertJsonPath('chart.points.2.amount', 800);
    }

    #[TestWith(['manager'])]
    #[TestWith(['cashier'])]
    public function test_managers_and_cashiers_can_view_sales(string $role): void
    {
        $this->signIn($role);
        $this->getJson('/api/v1/sales')->assertOk();
    }

    #[TestWith(['waiter'])]
    #[TestWith(['kitchen'])]
    public function test_other_staff_roles_receive_403(string $role): void
    {
        $this->signIn($role);
        $this->getJson('/api/v1/sales')->assertForbidden();
    }

    public function test_cashier_report_only_returns_daily_totals_and_records(): void
    {
        $this->signIn('cashier');
        $this->sale('2026-11-09 17:29:59', 100);
        $this->sale('2026-11-09 17:30:00', 200);
        $this->sale('2026-11-10 17:29:59', 300);
        $this->sale('2026-11-10 17:30:00', 400);

        foreach (['', '&period=day'] as $period) {
            $this->getJson('/api/v1/sales?date=2026-11-10'.$period)->assertOk()
                ->assertJsonPath('period', 'day')
                ->assertJsonPath('from', '2026-11-10')->assertJsonPath('to', '2026-11-10')
                ->assertJsonPath('summary', ['day' => ['amount' => 500, 'count' => 2]])
                ->assertJsonPath('refund_summary', ['day' => ['refunded' => 0, 'net' => 500]])
                ->assertJsonPath('chart.interval', 'hour')
                ->assertJsonPath('filtered.amount', 500)->assertJsonCount(2, 'sales.data');
        }
    }

    #[TestWith(['month'])]
    #[TestWith(['year'])]
    public function test_cashiers_cannot_request_broader_sales_periods(string $period): void
    {
        $this->signIn('cashier');

        $this->getJson('/api/v1/sales?date=2026-11-10&period='.$period)->assertForbidden();
    }

    public function test_manager_with_cashier_role_keeps_all_sales_periods(): void
    {
        $this->signIn();
        User::where('username', 'manager')->firstOrFail()->update(['roles' => ['manager', 'cashier']]);
        $this->actingAs(User::where('username', 'manager')->firstOrFail());

        foreach (['day', 'month', 'year'] as $period) {
            $this->getJson('/api/v1/sales?period='.$period)->assertOk()
                ->assertJsonPath('period', $period)
                ->assertJsonStructure(['summary' => ['day', 'month', 'year'], 'refund_summary' => ['day', 'month', 'year']]);
        }
    }

    public function test_guests_receive_401(): void
    {
        $this->getJson('/api/v1/sales')->assertUnauthorized();
    }

    #[TestWith(['date', '2026-02-30'])]
    #[TestWith(['period', 'week'])]
    #[TestWith(['method', 'bank'])]
    #[TestWith(['type', 'delivery'])]
    #[TestWith(['page', '0'])]
    public function test_invalid_filters_return_422(string $field, string $value): void
    {
        $this->signIn();
        $this->getJson('/api/v1/sales?'.http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
}
