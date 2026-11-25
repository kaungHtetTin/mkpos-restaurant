<?php

namespace App\Models;

use Illuminate\Database\Query\Builder;

class PaymentLedger
{
    public static function adjustments(): Builder
    {
        return BusinessData::table('payment_adjustments')->join('users', 'users.id', '=', 'payment_adjustments.recorded_by')
            ->select('payment_adjustments.*', 'users.name as actor_name');
    }

    public static function describe(object $payment): object
    {
        $adjustments = self::adjustments()->where('payment_id', $payment->id)->orderByDesc('payment_adjustments.id')->get();
        $adjustments->each(function ($adjustment): void {
            $adjustment->snapshot = json_decode($adjustment->snapshot);
        });
        $payment->refunded_amount = (int) $adjustments->where('kind', 'refund')->sum('amount');
        $payment->refundable_amount = max(0, $payment->amount - $payment->refunded_amount);
        $payment->effective_method = $payment->effective_method ?? $payment->method;
        $payment->effective_method_label = $payment->effective_method_label ?? $payment->method_label;
        $payment->original_shift_open = $payment->shift_id && BusinessData::table('cash_shifts')->where('id', $payment->shift_id)->whereNull('closed_at')->exists();
        $payment->adjustments = $adjustments;
        $payment->items_snapshot = is_string($payment->items_snapshot) ? json_decode($payment->items_snapshot, true) : ($payment->items_snapshot ?? self::receiptItems($payment->order_id));

        return $payment;
    }

    public static function receiptItems(int $orderId): array
    {
        return BusinessData::table('order_items')->where('order_id', $orderId)->where('status', '!=', 'cancelled')->orderBy('id')->get()->map(fn ($item) => [
            'id' => $item->id, 'name' => $item->name, 'quantity' => $item->quantity, 'price' => $item->price, 'options_snapshot' => json_decode($item->options_snapshot ?? '[]', true),
        ])->all();
    }

    public static function expectedCash(int $shiftId): int
    {
        $shift = BusinessData::table('cash_shifts')->find($shiftId);
        abort_unless($shift, 404);

        return $shift->opening_cash + (int) BusinessData::table('payments')->where('shift_id', $shiftId)->where('method', 'cash')->sum('amount')
            + (int) BusinessData::table('cash_movements')->where('shift_id', $shiftId)->sum('amount')
            + (int) BusinessData::table('payment_adjustments')->where('shift_id', $shiftId)->sum('cash_effect');
    }
}
