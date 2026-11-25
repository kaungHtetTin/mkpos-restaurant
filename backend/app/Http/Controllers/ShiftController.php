<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use App\Models\PaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    private function summary(int $id): array
    {
        $shift = BusinessData::table('cash_shifts')->find($id);
        abort_unless($shift, 404);
        $payments = BusinessData::table('payments')->where('shift_id', $id);
        $cashSales = (int) (clone $payments)->where('method', 'cash')->sum('amount');
        $otherSales = (int) (clone $payments)->where('method', 'other')->sum('amount');
        $adjustments = PaymentLedger::adjustments()->where('shift_id', $id)->orderByDesc('payment_adjustments.id')->get();
        $correctionEffect = (int) $adjustments->where('kind', 'correction')->sum('cash_effect');
        $cashSales += $correctionEffect;
        $otherSales -= $correctionEffect;
        $cashRefunds = -(int) $adjustments->where('kind', 'refund')->sum('cash_effect');
        $movements = BusinessData::table('cash_movements')->where('shift_id', $id)->orderByDesc('id')->get();
        $expected = PaymentLedger::expectedCash($id);

        return [...(array) $shift, 'cash_sales' => $cashSales, 'other_sales' => $otherSales, 'cash_refunds' => $cashRefunds, 'adjustments' => $adjustments, 'payment_count' => $payments->count(),
            'expected_cash' => $shift->closed_at ? $shift->closing_expected : $expected, 'movements' => $movements,
            'opener_name' => DB::table('users')->where('id', $shift->opened_by)->value('name'),
            'closer_name' => $shift->closed_by ? DB::table('users')->where('id', $shift->closed_by)->value('name') : null];
    }

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, ['cashier']);
        $request->validate(['page' => 'nullable|integer|min:1']);
        $active = BusinessData::table('cash_shifts')->whereNull('closed_at')->value('id');
        $history = BusinessData::table('cash_shifts')->whereNotNull('closed_at')->orderByDesc('id')->paginate(15);
        $history->setCollection($history->getCollection()->map(fn ($shift) => $this->summary($shift->id)));

        return response()->json(['active' => $active ? $this->summary($active) : null, 'history' => $history,
            'enabled' => (bool) BusinessData::table('restaurant_settings')->value('shifts_enabled')]);
    }

    public function command(Request $request, string $action): JsonResponse
    {
        $this->allow($request, ['cashier']);

        return $this->write($request, function () use ($request, $action): array {
            $active = BusinessData::table('cash_shifts')->whereNull('closed_at')->lockForUpdate()->first();
            if ($action === 'open') {
                $data = $request->validate(['opening_cash' => 'required|integer|min:0|max:1000000000']);
                abort_if($active, 409, 'A shift is already open. Refresh to see the active drawer.');
                $id = BusinessData::table('cash_shifts')->insertGetId(['business_id' => BusinessData::id(), 'active_business_id' => BusinessData::id(),
                    'opened_by' => $request->user()->id, 'currency' => BusinessData::table('restaurant_settings')->value('currency'), 'opening_cash' => $data['opening_cash'], 'opened_at' => now()]);
                BusinessData::table('restaurant_settings')->update(['shifts_enabled' => true]);
                $this->event($request, null, 'shift_opened', ['shift_id' => $id, 'opening_cash' => $data['opening_cash']]);

                return $this->summary($id);
            }
            abort_unless(in_array($action, ['movement', 'close']), 404);
            $request->validate(['shift_id' => 'required|integer']);
            abort_unless($active && $active->id === (int) $request->shift_id, 409, 'This shift is no longer open. Refresh and review the current drawer.');
            abort_unless($active->opened_by === $request->user()->id || in_array('manager', $request->user()->roles ?? []), 403, 'Only the shift opener or a manager can adjust or close this drawer.');
            if ($action === 'movement') {
                $data = $request->validate(['direction' => 'required|in:in,out', 'amount' => 'required|integer|min:1|max:1000000000', 'reason' => 'required|string|max:255']);
                $amount = $data['direction'] === 'out' ? -$data['amount'] : $data['amount'];
                abort_if($data['direction'] === 'out' && $this->summary($active->id)['expected_cash'] + $amount < 0, 422, 'Cash out exceeds the cash expected in the drawer.');
                BusinessData::table('cash_movements')->insert(['business_id' => BusinessData::id(), 'shift_id' => $active->id, 'recorded_by' => $request->user()->id, 'amount' => $amount, 'reason' => $data['reason'], 'created_at' => now()]);
                $this->event($request, null, 'shift_cash_movement', ['shift_id' => $active->id, 'amount' => $amount, 'reason' => $data['reason']]);
            } else {
                $data = $request->validate(['counted_cash' => 'required|integer|min:0|max:1000000000', 'expected_cash' => 'required|integer', 'note' => 'nullable|string|max:500']);
                $expected = $this->summary($active->id)['expected_cash'];
                abort_unless((int) $data['expected_cash'] === $expected, 409, 'Cash activity changed while you were counting. Review the new expected cash before closing.');
                $variance = $data['counted_cash'] - $expected;
                abort_if($variance !== 0 && empty($data['note']), 422, 'Add a note to explain the cash difference.');
                BusinessData::table('cash_shifts')->where('id', $active->id)->update(['active_business_id' => null, 'closed_by' => $request->user()->id, 'closed_at' => now(), 'closing_cash' => $data['counted_cash'], 'closing_expected' => $expected, 'variance' => $variance, 'closing_note' => $data['note'] ?? null]);
                $this->event($request, null, 'shift_closed', ['shift_id' => $active->id, 'expected_cash' => $expected, 'counted_cash' => $data['counted_cash'], 'variance' => $variance, 'note' => $data['note'] ?? null]);
            }

            return $this->summary($active->id);
        });
    }
}
