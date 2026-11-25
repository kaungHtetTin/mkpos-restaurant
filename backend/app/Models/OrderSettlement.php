<?php

namespace App\Models;

use Illuminate\Support\Collection;

class OrderSettlement
{
    public static function snapshot(object $order, Collection $items): array
    {
        if ($order->settlement_snapshot) {
            return json_decode($order->settlement_snapshot, true);
        }
        $totals = OrderTotals::calculate($order, $items);
        $groups = $totals['billing_groups'] ?? [[...$totals, 'id' => $order->id]];
        $units = [];
        foreach ($groups as $group) {
            $groupUnits = [];
            foreach ($items->where('status', '!=', 'cancelled')->filter(fn ($item) => ($item->origin_order_id ?? $item->order_id) === $group['id']) as $item) {
                foreach (range(0, $item->quantity - 1) as $index) {
                    $groupUnits[] = ['item_id' => $item->id, 'unit_index' => $index, 'subtotal' => (int) $item->price, 'group_id' => $group['id']];
                }
            }
            $discounts = self::distribute($group['discount_amount'], array_column($groupUnits, 'subtotal'));
            $net = array_map(fn ($unit, $index) => $unit['subtotal'] - $discounts[$index], $groupUnits, array_keys($groupUnits));
            $services = self::distribute($group['service_amount'], $net);
            $taxWeights = array_map(fn ($amount, $index) => $amount + ($group['tax_on_service'] ? $services[$index] : 0), $net, array_keys($net));
            $taxes = self::distribute($group['tax_amount'], $taxWeights);
            foreach ($groupUnits as $index => $unit) {
                $units[] = [...$unit, 'discount_amount' => $discounts[$index], 'service_amount' => $services[$index], 'tax_amount' => $taxes[$index],
                    'total' => $net[$index] + $services[$index] + $taxes[$index]];
            }
        }

        return ['totals' => $totals, 'units' => $units, 'items' => PaymentLedger::receiptItems($order->id)];
    }

    private static function distribute(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        $result = array_fill(0, count($weights), 0);
        if (! $sum || ! $amount) {
            return $result;
        }
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $result[$index] = intdiv($amount * $weight, $sum);
            $remainders[$index] = ($amount * $weight) % $sum;
        }
        arsort($remainders, SORT_NUMERIC);
        $left = $amount - array_sum($result);
        foreach (array_keys($remainders) as $index) {
            if ($left-- <= 0) {
                break;
            }
            $result[$index]++;
        }

        return $result;
    }

    public static function balance(object $order, array $snapshot): array
    {
        $received = (int) BusinessData::table('payments')->where('order_id', $order->id)->sum('amount');
        $allocations = BusinessData::table('payment_allocations')->where('order_id', $order->id)->selectRaw('item_id, unit_index, SUM(amount) as paid')->groupBy('item_id', 'unit_index')->get()->keyBy(fn ($row) => $row->item_id.':'.$row->unit_index);
        $units = array_map(function ($unit) use ($allocations, $order): array {
            $paid = $order->paid ? $unit['total'] : (int) ($allocations[$unit['item_id'].':'.$unit['unit_index']]->paid ?? 0);

            return [...$unit, 'paid_amount' => $paid, 'due' => max(0, $unit['total'] - $paid)];
        }, $snapshot['units']);

        return ['received_amount' => $received, 'balance_due' => max(0, $snapshot['totals']['total'] - $received - $order->cancelled_balance), 'settlement_units' => $units,
            'payments_started' => BusinessData::table('payments')->where('order_id', $order->id)->exists()];
    }

    public static function pay(object $order, array $data): int
    {
        $items = BusinessData::table('order_items')->where('order_id', $order->id)->get();
        $snapshot = self::snapshot($order, $items);
        $balance = self::balance($order, $snapshot);
        $due = $balance['balance_due'];
        $mode = $data['mode'] ?? 'full';
        $selected = [];
        if ($mode === 'items') {
            foreach ($data['items'] as $selection) {
                $available = array_values(array_filter($balance['settlement_units'], fn ($unit) => $unit['item_id'] === (int) $selection['item_id'] && $unit['due'] > 0));
                abort_if(count($available) < $selection['quantity'], 422, 'A selected item quantity is already settled or does not belong to this bill.');
                $selected = [...$selected, ...array_slice($available, 0, (int) $selection['quantity'])];
            }
            $amount = array_sum(array_column($selected, 'due'));
        } else {
            $amount = match ($mode) {
                'amount' => (int) $data['amount'],
                'equal' => (int) ceil($due / $data['shares']),
                default => $due,
            };
        }
        abort_if($amount > $due || ($amount <= 0 && $snapshot['totals']['total'] > 0), 422, 'Choose an amount greater than zero and no larger than the remaining balance.');
        $tenders = $data['tenders'] ?? [['method' => $data['method'], 'amount' => $amount, 'tendered' => $data['tendered']]];
        abort_unless(array_sum(array_column($tenders, 'amount')) === $amount, 422, 'Cash and other payment amounts must add up to this payment.');
        abort_if($amount === 0 && count($tenders) !== 1, 422, 'A zero-total bill needs one settlement entry.');
        foreach ($tenders as $tender) {
            abort_if($tender['amount'] <= 0 && $amount > 0, 422, 'Remove payment methods with a zero amount.');
            abort_if($tender['method'] === 'cash' && ($tender['tendered'] ?? 0) < $tender['amount'], 422, 'Cash received is below the cash portion.');
        }
        $settings = BusinessData::table('restaurant_settings')->first();
        $shiftId = BusinessData::table('cash_shifts')->whereNull('closed_at')->value('id');
        abort_if($settings->shifts_enabled && ! $shiftId, 422, 'Open a cashier shift before recording payment.');
        $allocations = [];
        if ($mode === 'items') {
            $allocations = array_map(fn ($unit) => [...$unit, 'allocated' => $unit['due']], $selected);
        } else {
            $left = $amount;
            foreach ($balance['settlement_units'] as $unit) {
                if ($left <= 0) {
                    break;
                }
                if ($unit['due'] > 0) {
                    $part = min($left, $unit['due']);
                    $allocations[] = [...$unit, 'allocated' => $part];
                    $left -= $part;
                }
            }
        }
        $receiptTotals = null;
        $receiptItems = [];
        $credit = 0;
        if ($mode === 'items' || $mode === 'full') {
            $chosen = $mode === 'full' && $balance['received_amount'] === 0 ? $balance['settlement_units'] : $allocations;
            $credit = array_sum(array_column($chosen, 'paid_amount'));
            $receiptTotals = $mode === 'full' && $balance['received_amount'] === 0 ? $snapshot['totals'] : self::portionTotals($snapshot, $chosen);
            foreach (collect($chosen)->groupBy('item_id') as $itemId => $units) {
                $item = collect($snapshot['items'])->firstWhere('id', $itemId);
                $receiptItems[] = [...$item, 'quantity' => $units->count()];
            }
        }
        $count = BusinessData::table('payment_receipts')->where('order_id', $order->id)->count();
        $number = 'R-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT).($count ? '-'.($count + 1) : '');
        $receiptId = BusinessData::table('payment_receipts')->insertGetId(['business_id' => BusinessData::id(), 'order_id' => $order->id, 'recorded_by' => request()->user()->id,
            'receipt_number' => $number, 'mode' => $mode, 'payer_label' => $data['payer_label'] ?? null, 'amount' => $amount, 'order_total' => $snapshot['totals']['total'],
            'prior_paid' => $balance['received_amount'], 'balance_after' => $due - $amount, 'credited_amount' => $credit, 'table_label' => $order->table_name,
            'restaurant_snapshot' => json_encode($settings), 'totals_snapshot' => $receiptTotals ? json_encode($receiptTotals) : null, 'items_snapshot' => json_encode($receiptItems), 'created_at' => now()]);
        foreach ($allocations as $allocation) {
            BusinessData::table('payment_allocations')->insert(['business_id' => BusinessData::id(), 'order_id' => $order->id, 'receipt_id' => $receiptId, 'item_id' => $allocation['item_id'], 'unit_index' => $allocation['unit_index'], 'amount' => $allocation['allocated']]);
        }
        foreach ($tenders as $tender) {
            $cash = $tender['method'] === 'cash';
            BusinessData::table('payments')->insert(['business_id' => BusinessData::id(), 'order_id' => $order->id, 'receipt_id' => $receiptId, 'receipt_number' => $number,
                'amount' => $tender['amount'], 'method' => $tender['method'], 'method_label' => $cash ? 'Cash' : $settings->other_payment_label,
                'tendered' => $cash ? $tender['tendered'] : $tender['amount'], 'change' => $cash ? $tender['tendered'] - $tender['amount'] : 0,
                'effective_method' => $tender['method'], 'effective_method_label' => $cash ? 'Cash' : $settings->other_payment_label, 'recorded_by' => request()->user()->id, 'shift_id' => $shiftId,
                'restaurant_snapshot' => json_encode($settings), 'totals_snapshot' => $receiptTotals ? json_encode($receiptTotals) : null, 'items_snapshot' => json_encode($receiptItems), 'created_at' => now()]);
        }
        BusinessData::table('orders')->where('id', $order->id)->update(['settlement_snapshot' => json_encode($snapshot), 'paid' => $amount === $due,
            'equal_shares_left' => $amount === $due ? 0 : ($mode === 'equal' ? max(0, $data['shares'] - 1) : $order->equal_shares_left)]);

        return $receiptId;
    }

    private static function portionTotals(array $snapshot, array $units): array
    {
        $totals = $snapshot['totals'];
        foreach (['subtotal', 'discount_amount', 'service_amount', 'tax_amount', 'total'] as $key) {
            $totals[$key] = (int) array_sum(array_column($units, $key));
        }
        $groups = $snapshot['totals']['billing_groups'] ?? [];
        $lines = [];
        foreach (collect($units)->groupBy('group_id') as $groupId => $groupUnits) {
            $group = collect($groups)->firstWhere('id', $groupId) ?? $snapshot['totals'];
            foreach (['service' => ['service_rate_bps', 'service_charge_label', 'service_amount'], 'tax' => ['tax_rate_bps', 'tax_label', 'tax_amount']] as $kind => [$rate, $label, $value]) {
                if ($group[$rate]) {
                    $key = $kind.'|'.$group[$label].'|'.$group[$rate];
                    $lines[$key] ??= ['kind' => $kind, 'label' => $group[$label], 'rate_bps' => $group[$rate], 'amount' => 0];
                    $lines[$key]['amount'] += $groupUnits->sum($value);
                }
            }
        }
        $totals['charge_lines'] = array_values($lines);
        unset($totals['billing_groups']);

        return $totals;
    }
}
