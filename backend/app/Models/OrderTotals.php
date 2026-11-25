<?php

namespace App\Models;

use Illuminate\Support\Collection;

class OrderTotals
{
    /** @return array<string, mixed> */
    public static function calculate(object $order, Collection $items): array
    {
        $ids = json_decode($order->billing_group_ids ?? 'null', true);
        if (! $ids) {
            return self::single($order, $items);
        }
        $groups = BusinessData::table('orders')->whereIn('id', $ids)->orderBy('id')->get()->map(function ($group) use ($items): array {
            $totals = self::single($group, $items->filter(fn ($item) => ($item->origin_order_id ?? $item->order_id) === $group->id));

            return [...$totals, 'id' => $group->id, 'number' => $group->number, 'label' => $group->billing_label ?? $group->number,
                'discount_type' => $group->discount_type, 'discount_value' => $group->discount_value, 'discount_reason' => $group->discount_reason];
        });
        $result = self::single($order, collect());
        foreach (['subtotal', 'discount_amount', 'service_amount', 'tax_amount', 'total'] as $key) {
            $result[$key] = (int) $groups->sum($key);
        }
        $lines = [];
        foreach ($groups as $group) {
            foreach ($group['charge_lines'] as $line) {
                $key = json_encode([$line['kind'], $line['label'], $line['rate_bps']]);
                if (! isset($lines[$key])) {
                    $lines[$key] = [...$line, 'amount' => 0];
                }
                $lines[$key]['amount'] += $line['amount'];
            }
        }
        $result['charge_lines'] = array_values($lines);
        $result['billing_groups'] = $groups->values()->all();

        return $result;
    }

    /** @return array<string, mixed> */
    private static function single(object $order, Collection $items): array
    {
        $subtotal = (int) $items->where('status', '!=', 'cancelled')->sum(fn ($item) => $item->price * $item->quantity);
        $discount = match ($order->discount_type) {
            'fixed' => min($subtotal, (int) $order->discount_value),
            'percent' => self::percentage($subtotal, (int) $order->discount_value),
            default => 0,
        };
        $net = $subtotal - $discount;
        $service = self::percentage($net, $order->service_rate_bps);
        $tax = self::percentage($net + ($order->tax_on_service ? $service : 0), $order->tax_rate_bps);

        $lines = [];
        if ($order->service_rate_bps) {
            $lines[] = ['kind' => 'service', 'label' => $order->service_charge_label, 'rate_bps' => $order->service_rate_bps, 'amount' => $service];
        }
        if ($order->tax_rate_bps) {
            $lines[] = ['kind' => 'tax', 'label' => $order->tax_label, 'rate_bps' => $order->tax_rate_bps, 'amount' => $tax];
        }

        return ['subtotal' => $subtotal, 'discount_amount' => $discount, 'service_amount' => $service, 'tax_amount' => $tax, 'charge_lines' => $lines,
            'tax_rate_bps' => $order->tax_rate_bps, 'service_rate_bps' => $order->service_rate_bps,
            'tax_label' => $order->tax_label, 'service_charge_label' => $order->service_charge_label, 'tax_on_service' => (bool) $order->tax_on_service,
            'total' => $net + $service + $tax];
    }

    private static function percentage(int $amount, int $basisPoints): int
    {
        return intdiv($amount * $basisPoints + 5000, 10000);
    }
}
