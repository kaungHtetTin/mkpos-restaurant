<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(array_intersect($request->user()->roles ?? [], ['manager', 'cashier']), 403, 'Your role cannot view sales.');
        $request->validate([
            'date' => 'nullable|date_format:Y-m-d|before:9999-01-01',
            'period' => 'nullable|in:day,month,year',
            'q' => 'nullable|string|max:100',
            'type' => 'nullable|in:dine_in,takeaway',
            'method' => 'nullable|in:cash,other',
            'page' => 'nullable|integer|min:1',
            'adjustment_page' => 'nullable|integer|min:1',
        ]);
        $settings = BusinessData::table('restaurant_settings')->first();
        abort_unless($settings, 503, 'Configure the restaurant before viewing sales.');
        $timezone = $settings->timezone;
        $anchor = CarbonImmutable::parse($request->input('date') ?: CarbonImmutable::now($timezone)->toDateString(), $timezone)->startOfDay();
        $period = $request->input('period') ?: 'day';
        $canViewAllPeriods = in_array('manager', $request->user()->roles ?? [], true);
        abort_unless($canViewAllPeriods || $period === 'day', 403, 'Cashiers can only view daily sales.');
        $ranges = [
            'day' => [$anchor, $anchor->addDay()],
            'month' => [$anchor->startOfMonth(), $anchor->startOfMonth()->addMonth()],
            'year' => [$anchor->startOfYear(), $anchor->startOfYear()->addYear()],
        ];
        if (! $canViewAllPeriods) {
            $ranges = ['day' => $ranges['day']];
        }

        return DB::transaction(function () use ($request, $settings, $timezone, $anchor, $period, $ranges): JsonResponse {
            $base = BusinessData::table('payments')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->where('orders.status', '!=', 'cancelled');
            $summary = [];
            $refundSummary = [];
            foreach ($ranges as $key => [$start, $end]) {
                $summary[$key] = $this->totals($this->withinPeriod($base, $start, $end));
                $refunds = (int) BusinessData::table('payment_adjustments')->where('kind', 'refund')->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->utc())->sum('amount');
                $refundSummary[$key] = ['refunded' => $refunds, 'net' => $summary[$key]['amount'] - $refunds];
            }
            [$start, $end] = $ranges[$period];
            $chart = $this->chart($this->withinPeriod($base, $start, $end), $start, $end, $period);
            $records = $this->withinPeriod($base, $start, $end);
            if ($request->filled('type')) {
                $records->where('orders.type', $request->input('type'));
            }
            if ($request->filled('method')) {
                $records->whereRaw('COALESCE(payments.effective_method, payments.method) = ?', [$request->input('method')]);
            }
            if ($request->filled('q')) {
                $term = '%'.$request->input('q').'%';
                $records->where(function (Builder $query) use ($term): void {
                    $query->where('orders.number', 'like', $term)
                        ->orWhere('payments.receipt_number', 'like', $term)
                        ->orWhere('orders.customer_name', 'like', $term)
                        ->orWhere('orders.table_name', 'like', $term);
                });
            }
            $filteredTotals = $this->totals($records);
            $sales = $records->join('users', 'users.id', '=', 'payments.recorded_by')
                ->select([
                    'payments.id', 'payments.order_id', 'payments.receipt_number', 'payments.amount',
                    DB::raw('COALESCE(payments.effective_method, payments.method) as method'), DB::raw('COALESCE(payments.effective_method_label, payments.method_label) as method_label'), 'payments.created_at as paid_at',
                    'orders.number as order_number', 'orders.type', 'orders.table_name', 'orders.customer_name',
                    'users.name as cashier_name',
                ])->orderByDesc('payments.created_at')->orderByDesc('payments.id')->paginate(25);
            $refundsByPayment = BusinessData::table('payment_adjustments')->where('kind', 'refund')->whereIn('payment_id', $sales->getCollection()->pluck('id'))->selectRaw('payment_id, SUM(amount) as refunded')->groupBy('payment_id')->pluck('refunded', 'payment_id');
            $sales->getCollection()->each(function ($sale) use ($refundsByPayment): void {
                $sale->refunded_amount = (int) ($refundsByPayment[$sale->id] ?? 0);
                $sale->retained_amount = $sale->amount - $sale->refunded_amount;
            });
            $adjustments = BusinessData::table('payment_adjustments')->join('payments', 'payments.id', '=', 'payment_adjustments.payment_id')->join('orders', 'orders.id', '=', 'payment_adjustments.order_id')->join('users', 'users.id', '=', 'payment_adjustments.recorded_by')
                ->where('payment_adjustments.created_at', '>=', $start->utc())->where('payment_adjustments.created_at', '<', $end->utc());
            if ($request->filled('q')) {
                $term = '%'.$request->input('q').'%';
                $adjustments->where(fn ($query) => $query->where('orders.number', 'like', $term)->orWhere('payments.receipt_number', 'like', $term)->orWhere('payment_adjustments.reference_number', 'like', $term)->orWhere('orders.customer_name', 'like', $term)->orWhere('orders.table_name', 'like', $term));
            }
            if ($request->filled('type')) {
                $adjustments->where('orders.type', $request->input('type'));
            }
            if ($request->filled('method')) {
                $adjustments->where('payment_adjustments.to_method', $request->input('method'));
            }
            $adjustments = $adjustments->select('payment_adjustments.*', 'orders.number as order_number', 'payments.receipt_number', 'users.name as actor_name')->orderByDesc('payment_adjustments.id')->paginate(15, ['*'], 'adjustment_page');

            return response()->json([
                'date' => $anchor->toDateString(), 'period' => $period,
                'timezone' => $timezone, 'currency' => $settings->currency,
                'from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(),
                'summary' => $summary, 'refund_summary' => $refundSummary, 'adjustments' => $adjustments, 'chart' => $chart, 'filtered' => $filteredTotals, 'sales' => $sales,
            ]);
        });
    }

    private function withinPeriod(Builder $query, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return (clone $query)->where('payments.created_at', '>=', $start->utc())
            ->where('payments.created_at', '<', $end->utc());
    }

    /** @return array{interval: string, points: array<int, array<string, int|string>>} */
    private function chart(Builder $query, CarbonImmutable $start, CarbonImmutable $end, string $period): array
    {
        $points = [];
        $lookup = [];
        $cursor = $period === 'day' ? $start->utc() : $start;
        $limit = $period === 'day' ? $end->utc() : $end;
        while ($cursor < $limit) {
            $local = $cursor->setTimezone($start->timezone);
            $key = match ($period) {
                'day' => (string) $cursor->timestamp,
                'month' => $local->format('Y-m-d'),
                'year' => $local->format('Y-m'),
            };
            $lookup[$key] = count($points);
            $points[] = [
                'key' => $key,
                'label' => $local->format(match ($period) {
                    'day' => 'H:i', 'month' => 'j', 'year' => 'M'
                }),
                'description' => $local->format(match ($period) {
                    'day' => 'j M Y, H:i (\U\T\C P)', 'month' => 'j M Y', 'year' => 'F Y'
                }),
                'amount' => 0, 'count' => 0,
            ];
            $cursor = match ($period) {
                'day' => $cursor->addHour(), 'month' => $cursor->addDay(), 'year' => $cursor->addMonth()
            };
        }
        foreach ((clone $query)->select('payments.amount', 'payments.created_at')->orderBy('payments.id')->cursor() as $payment) {
            $paid = CarbonImmutable::parse($payment->created_at, 'UTC');
            $local = $paid->setTimezone($start->timezone);
            $key = match ($period) {
                'day' => (string) ($start->utc()->timestamp + (int) floor(($paid->timestamp - $start->utc()->timestamp) / 3600) * 3600),
                'month' => $local->format('Y-m-d'),
                'year' => $local->format('Y-m'),
            };
            if (isset($lookup[$key])) {
                $index = $lookup[$key];
                $points[$index]['amount'] += (int) $payment->amount;
                $points[$index]['count']++;
            }
        }

        return ['interval' => match ($period) {
            'day' => 'hour', 'month' => 'day', 'year' => 'month'
        }, 'points' => $points];
    }

    /** @return array{amount: int, count: int} */
    private function totals(Builder $query): array
    {
        $totals = (clone $query)->selectRaw('COALESCE(SUM(payments.amount), 0) as amount, COUNT(*) as count')->first();

        return ['amount' => (int) $totals->amount, 'count' => (int) $totals->count];
    }
}
