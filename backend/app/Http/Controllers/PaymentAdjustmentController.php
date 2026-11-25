<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use App\Models\PaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentAdjustmentController extends Controller
{
    public function command(Request $request, int $id, string $action): JsonResponse
    {
        $this->allow($request, []);
        abort_unless(in_array($action, ['refund', 'correct-method']), 404);

        return $this->write($request, function () use ($request, $id, $action): array {
            $payment = BusinessData::table('payments')->where('id', $id)->lockForUpdate()->first();
            abort_unless($payment, 404);
            $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:500', 'method' => 'required|in:cash,other', 'confirmed' => 'required|accepted', 'stop_preparation' => 'sometimes|boolean']);
            abort_unless($payment->adjustment_version === (int) $data['version'], 409, 'This payment was adjusted on another device. Review the latest balance and try again.');
            $payment = PaymentLedger::describe($payment);
            $settings = BusinessData::table('restaurant_settings')->first();
            $methodLabel = $data['method'] === 'cash' ? 'Cash' : $settings->other_payment_label;
            $activeShift = BusinessData::table('cash_shifts')->whereNull('closed_at')->first();
            $cashEffect = 0;
            $shiftId = null;
            if ($action === 'refund') {
                $request->validate(['expected_shift_id' => 'sometimes|nullable|integer']);
                $amount = (int) $request->validate(['amount' => 'required|integer|min:1|max:1000000000'])['amount'];
                abort_if($amount > $payment->refundable_amount, 422, 'The refund exceeds the remaining refundable amount.');
                $cashEffect = $data['method'] === 'cash' ? -(int) $amount : 0;
                abort_if($data['method'] === 'cash' && $settings->shifts_enabled && ! $activeShift, 422, 'Open a cashier shift before recording a cash refund.');
                abort_if($data['method'] === 'cash' && $request->exists('expected_shift_id') && (int) $request->input('expected_shift_id') !== (int) $activeShift?->id, 409, 'The active cash drawer changed. Review the current shift before recording the refund.');
                if ($activeShift) {
                    $shiftId = $activeShift->id;
                    abort_if($data['method'] === 'cash' && PaymentLedger::expectedCash($shiftId) < $amount, 422, 'The drawer has insufficient expected cash. Add cash or choose the actual non-cash refund method.');
                }
            } else {
                abort_if($data['method'] === $payment->effective_method, 422, 'Choose a different payment method.');
                $amount = $payment->amount;
                if ($payment->original_shift_open) {
                    $shiftId = $payment->shift_id;
                    $cashEffect = (($data['method'] === 'cash' ? 1 : 0) - ($payment->effective_method === 'cash' ? 1 : 0)) * $amount;
                }
                $expected = $request->validate(['expected_cash_effect' => 'required|integer'])['expected_cash_effect'];
                abort_unless((int) $expected === $cashEffect, 409, 'The original shift state changed. Review the correction and its cash effect before confirming.');
                BusinessData::table('payments')->where('id', $id)->update(['effective_method' => $data['method'], 'effective_method_label' => $methodLabel]);
            }
            $order = BusinessData::table('orders')->find($payment->order_id);
            $kind = $action === 'refund' ? 'refund' : 'correction';
            if ($data['stop_preparation'] ?? false) {
                $received = (int) BusinessData::table('payments')->where('order_id', $order->id)->sum('amount');
                $returned = (int) BusinessData::table('payment_adjustments')->where('order_id', $order->id)->where('kind', 'refund')->sum('amount');
                abort_unless($kind === 'refund' && $returned + $amount === $received && $order->type === 'takeaway' && ! $order->collected_at && $order->status === 'open', 422, 'All received payment portions must be refunded before stopping an uncollected takeaway.');
                BusinessData::table('payments')->where('id', $id)->update(['items_snapshot' => json_encode($payment->items_snapshot)]);
                foreach (BusinessData::table('order_items')->where('order_id', $order->id)->where('status', '!=', 'cancelled')->get() as $item) {
                    BusinessData::table('order_items')->where('id', $item->id)->update(['status' => 'cancelled', 'cancel_reason' => Str::substr('Refund: '.$data['reason'], 0, 500), 'cancel_ack_at' => $item->ticket_id ? null : now(), 'updated_at' => now()]);
                }
                $frozen = json_decode($order->settlement_snapshot ?? 'null', true);
                $cancelled = max(0, ($frozen['totals']['total'] ?? $received) - $received);
                BusinessData::table('orders')->where('id', $order->id)->update(['status' => 'closed', 'closed_at' => now(), 'cancelled_balance' => $cancelled]);
            }
            $snapshot = ['restaurant' => json_decode($payment->restaurant_snapshot), 'original_receipt' => $payment->receipt_number, 'order_number' => $order->number,
                'remaining_after' => $payment->refundable_amount - ($kind === 'refund' ? $amount : 0), 'original_amount' => $payment->amount];
            $adjustmentId = BusinessData::table('payment_adjustments')->insertGetId(['business_id' => BusinessData::id(), 'payment_id' => $id, 'order_id' => $order->id,
                'shift_id' => $shiftId, 'recorded_by' => $request->user()->id, 'kind' => $kind, 'reference_number' => ($kind === 'refund' ? 'RF-' : 'PC-').strtoupper((string) Str::ulid()),
                'amount' => $amount, 'from_method' => $payment->effective_method, 'from_label' => $payment->effective_method_label, 'to_method' => $data['method'], 'to_label' => $methodLabel,
                'cash_effect' => $cashEffect, 'reason' => $data['reason'], 'snapshot' => json_encode($snapshot), 'created_at' => now()]);
            BusinessData::table('payments')->where('id', $id)->increment('adjustment_version');
            BusinessData::table('orders')->where('id', $order->id)->increment('version', 1, ['updated_at' => now()]);
            $this->event($request, $order->id, 'payment_'.$kind, ['adjustment_id' => $adjustmentId, 'payment_id' => $id, 'amount' => $amount, 'cash_effect' => $cashEffect, 'from_method' => $payment->effective_method, 'to_method' => $data['method'], 'reason' => $data['reason'], 'stop_preparation' => $data['stop_preparation'] ?? false]);

            return ['ok' => true, 'adjustment_id' => $adjustmentId];
        });
    }
}
