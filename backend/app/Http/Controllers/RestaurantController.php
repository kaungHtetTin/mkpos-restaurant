<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use App\Models\MenuOptions;
use App\Models\OrderSettlement;
use App\Models\PaymentLedger;
use App\Models\TableAssignments;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RestaurantController extends Controller
{
    private function order(int $id): array
    {
        $order = BusinessData::table('orders')->find($id);
        abort_unless($order, 404);
        $items = BusinessData::table('order_items')->where('order_id', $id)->orderBy('id')->get();
        $items->each(function ($item): void {
            $item->options_snapshot = json_decode($item->options_snapshot ?? '[]', true);
        });
        $payments = BusinessData::table('payments')->where('order_id', $id)->orderBy('id')->get();
        $payments->each(function ($payment): void {
            $payment->restaurant_snapshot = json_decode($payment->restaurant_snapshot);
            $payment->totals_snapshot = $payment->totals_snapshot ? json_decode($payment->totals_snapshot, true) : null;
            PaymentLedger::describe($payment);
        });
        $payment = $payments->last();
        $snapshot = OrderSettlement::snapshot($order, $items);
        $totals = $snapshot['totals'];
        if (! $order->settlement_snapshot && $order->paid && $payment?->totals_snapshot) {
            $totals = $payment->totals_snapshot;
            $snapshot['totals'] = $totals;
        }
        $balance = OrderSettlement::balance($order, $snapshot);
        $receipts = BusinessData::table('payment_receipts')->where('order_id', $id)->orderBy('id')->get();
        $receipts->each(function ($receipt) use ($payments): void {
            $receipt->restaurant_snapshot = json_decode($receipt->restaurant_snapshot);
            $receipt->totals_snapshot = $receipt->totals_snapshot ? json_decode($receipt->totals_snapshot, true) : null;
            $receipt->items_snapshot = json_decode($receipt->items_snapshot, true);
            $receipt->payments = $payments->where('receipt_id', $receipt->id)->values();
        });

        return [...(array) $order, 'settlement_snapshot' => null, 'paid' => (bool) $order->paid, 'bill_requested' => (bool) $order->bill_requested, 'items' => $items,
            'table_ids' => BusinessData::table('order_tables')->where('order_id', $id)->pluck('table_id')->all(), 'table_names_snapshot' => json_decode($order->table_names_snapshot ?? '[]', true),
            ...$totals, ...$balance, 'bill_items_snapshot' => $order->settlement_snapshot ? $snapshot['items'] : ($order->paid && $payment ? $payment->items_snapshot : $snapshot['items']), 'total_refunded_amount' => (int) $payments->sum('refunded_amount'), 'payments' => $payments, 'receipts' => $receipts, 'payment' => $payment];
    }

    private function visibleOrder(Request $r, array $order): array
    {
        if (! array_intersect($r->user()->roles ?? [], ['manager', 'waiter', 'cashier'])) {
            unset($order['phone'], $order['customer_name'], $order['payment'], $order['total'], $order['subtotal'], $order['discount_amount'], $order['service_amount'], $order['tax_amount'], $order['discount_type'], $order['discount_value'], $order['discount_reason'], $order['discount_approved_by'], $order['tax_rate_bps'], $order['service_rate_bps'], $order['tax_label'], $order['service_charge_label'], $order['tax_on_service'], $order['charge_lines'], $order['billing_groups'], $order['payments'], $order['receipts'], $order['balance_due'], $order['received_amount'], $order['settlement_units'], $order['bill_items_snapshot'], $order['total_refunded_amount']);
            $order['items'] = collect($order['items'])->whereNotNull('ticket_id')->map(function ($item) {
                $safe = (array) $item;
                unset($safe['price']);
                $safe['options_snapshot'] = array_map(fn ($option) => array_diff_key($option, ['price' => true]), $safe['options_snapshot']);

                return $safe;
            })->values();
        }

        return $order;
    }

    public function state(Request $r)
    {
        $manager = in_array('manager', $r->user()->roles ?? []);
        $categories = BusinessData::table('categories')->orderBy('sort')->get();
        $menu = BusinessData::table('menu_items')->orderBy('id')->get();
        $options = BusinessData::table('menu_options')->whereNull('archived_at')->orderBy('id')->get()->groupBy('menu_item_id');
        $menu->each(function ($item) use ($options): void {
            $item->image_url = $item->image_path ? '/api/v1/menu-images/'.basename($item->image_path) : null;
            $item->options = $options->get($item->id, collect())->values();
            $variantPrices = $item->options->where('kind', 'variant')->where('active', true)->pluck('price');
            $item->display_price = $item->uses_variants ? $variantPrices->min() : $item->price;
            unset($item->track_stock, $item->stock_on_hand, $item->stock_low_at, $item->stock_version);
            $item->orderable = (bool) $item->available && (! $item->uses_variants || $variantPrices->isNotEmpty());
        });
        if (! $manager) {
            $categories = $categories->where('active', true)->values();
            $menu = $menu->where('active', true)->whereIn('category_id', $categories->pluck('id'))->values();
        }
        $orderIds = BusinessData::table('orders')->where('status', 'open')->pluck('id');
        // Retain closed cancellations until kitchen acknowledgment.
        $pendingIds = BusinessData::table('order_items')->where('status', 'cancelled')->whereNotNull('ticket_id')->whereNull('cancel_ack_at')->pluck('order_id');
        $orders = $orderIds->merge($pendingIds)->unique()->map(fn ($id) => $this->order($id))->values();
        $kitchenOnly = ! array_intersect($r->user()->roles ?? [], ['manager', 'waiter', 'cashier']);
        if ($kitchenOnly) {
            $orders = $orders->map(fn ($o) => $this->visibleOrder($r, $o));
        }

        return response()->json(['user' => $r->user(), 'settings' => BusinessData::table('restaurant_settings')->first(), 'categories' => $categories, 'menu' => $menu,
            'tables' => BusinessData::table('dining_tables')->orderBy('id')->get(), 'orders' => $orders,
            'tickets' => BusinessData::table('kitchen_tickets')->whereIn('order_id', $orders->pluck('id'))->orderBy('id')->get(),
            'business' => DB::table('businesses')->find(BusinessData::id()), 'subscription' => BusinessData::subscription(BusinessData::id()), 'writable' => BusinessData::writable(BusinessData::id()),
            'guest_pending_count' => $kitchenOnly ? 0 : BusinessData::table('guest_order_requests')->where('status', 'pending')->where('created_at', '>', now()->subHours(2))->count(),
            'active_shift_id' => BusinessData::table('cash_shifts')->whereNull('closed_at')->value('id'),
            'staff' => $manager ? User::where('business_id', BusinessData::id())->orderBy('id')->get() : [], 'server_time' => now()->toIso8601String()]);
    }

    public function show(Request $r, int $id)
    {
        $this->allow($r, ['waiter', 'cashier']);
        $order = $this->order($id);
        if (! in_array($order['status'], ['open', 'merged'])) {
            $this->allow($r, ['cashier']);
        }
        $related = BusinessData::table('orders')->where('merged_into_id', $id)->pluck('id')->push($id);
        $order['events'] = BusinessData::table('operational_events')->leftJoin('users', 'users.id', '=', 'operational_events.user_id')->whereIn('order_id', $related)->select('operational_events.*')->selectRaw("COALESCE(users.name, 'Customer (QR menu)') as actor")->orderBy('operational_events.id')->get();

        return response()->json($order);
    }

    public function history(Request $r)
    {
        $this->allow($r, ['cashier']);
        $r->validate(['date' => 'nullable|date_format:Y-m-d', 'q' => 'nullable|string|max:100', 'status' => 'nullable|in:closed,cancelled,merged,refunded', 'type' => 'nullable|in:dine_in,takeaway']);
        $paymentSummary = BusinessData::table('payments')->selectRaw('order_id, SUM(amount) as amount, MIN(receipt_number) as receipt_number, COUNT(DISTINCT receipt_number) as receipt_count')->groupBy('order_id');
        $query = BusinessData::table('orders')->leftJoinSub($paymentSummary, 'payments', 'payments.order_id', '=', 'orders.id')->whereIn('orders.status', ['closed', 'cancelled', 'merged']);
        $refunds = BusinessData::table('payment_adjustments')->where('kind', 'refund')->selectRaw('order_id, SUM(amount) as refunded_amount')->groupBy('order_id');
        $query->leftJoinSub($refunds, 'refund_totals', 'refund_totals.order_id', '=', 'orders.id');
        if ($r->filled('q')) {
            $query->where(fn ($q) => $q->where('orders.number', 'like', '%'.$r->q.'%')->orWhereExists(fn ($receipts) => $receipts->selectRaw('1')->from('payments as matching_payments')->whereColumn('matching_payments.order_id', 'orders.id')->where('matching_payments.business_id', BusinessData::id())->where('matching_payments.receipt_number', 'like', '%'.$r->q.'%')));
        }
        if ($r->filled('date')) {
            $timezone = BusinessData::table('restaurant_settings')->value('timezone');
            $start = CarbonImmutable::parse($r->date, $timezone)->startOfDay()->utc();
            $query->where('orders.closed_at', '>=', $start)->where('orders.closed_at', '<', $start->addDay());
        }
        if ($r->filled('status')) {
            $r->status === 'refunded' ? $query->where('refund_totals.refunded_amount', '>', 0) : $query->where('orders.status', $r->status);
        }
        if ($r->filled('type')) {
            $query->where('orders.type', $r->type);
        }

        return response()->json($query->select('orders.*', 'payments.amount', 'payments.receipt_number', 'payments.receipt_count')->selectRaw('COALESCE(refund_totals.refunded_amount, 0) as refunded_amount')->orderByDesc('orders.id')->paginate(25));
    }

    public function create(Request $r)
    {
        $this->allow($r, ['waiter', 'cashier']);
        $data = $r->validate(['type' => 'required|in:dine_in,takeaway', 'table_id' => ['required_if:type,dine_in', 'nullable', 'integer', BusinessData::exists('dining_tables')], 'customer_name' => 'nullable|string|max:100', 'phone' => 'nullable|string|max:40']);

        return $this->write($r, function () use ($r, $data) {
            $table = null;
            if ($data['type'] === 'dine_in') {
                $table = BusinessData::table('dining_tables')->where('id', $data['table_id'])->where('active', true)->first();
                abort_unless($table, 422, 'This table is inactive.');
                $existing = BusinessData::table('order_tables')->where('table_id', $table->id)->first();
                if ($existing) {
                    return $this->order($existing->order_id);
                }
            }
            $settings = BusinessData::table('restaurant_settings')->first();
            $id = BusinessData::table('orders')->insertGetId(['business_id' => BusinessData::id(), 'number' => 'ORD-'.strtoupper((string) Str::ulid()), 'type' => $data['type'], 'active_table_id' => $table?->id, 'table_name' => $table?->name,
                'tax_rate_bps' => $settings->tax_rate_bps, 'service_rate_bps' => $settings->service_dine_in_only && $data['type'] === 'takeaway' ? 0 : $settings->service_rate_bps,
                'tax_label' => $settings->tax_label, 'service_charge_label' => $settings->service_charge_label, 'tax_on_service' => $settings->tax_on_service,
                'billing_label' => $table?->name, 'customer_name' => $data['customer_name'] ?? null, 'phone' => $data['phone'] ?? null, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($table) {
                TableAssignments::occupy($id, $table->id);
            }
            BusinessData::table('orders')->where('id', $id)->update(['number' => 'LL-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT)]);
            $this->event($r, $id, 'order_created');

            return $this->order($id);
        });
    }

    public function command(Request $r, int $id, string $action)
    {
        $kitchen = ['prepare', 'ready', 'acknowledge'];
        $this->allow($r, in_array($action, $kitchen) ? ['kitchen'] : (in_array($action, ['pay', 'change-price']) ? ['cashier'] : (in_array($action, ['cancel-item', 'cancel', 'discount']) ? [] : ['waiter', 'cashier'])));
        $r->validate(['version' => 'required|integer|min:1']);

        return $this->write($r, function () use ($r, $id, $action) {
            $order = BusinessData::table('orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            abort_unless($order->version === (int) $r->version, 409, 'This order changed on another device. Review the latest order and try again.');
            abort_unless($action === 'acknowledge' || $order->status === 'open', 422, 'This order is closed.');
            abort_if($order->paid && ! in_array($action, ['prepare', 'ready', 'collect', 'acknowledge']), 422, 'Paid orders cannot be edited.');
            abort_if($order->collected_at && ! in_array($action, ['pay', 'change-price', 'discount', 'acknowledge']), 422, 'Collected order contents cannot be edited.');
            $started = BusinessData::table('payments')->where('order_id', $id)->exists();
            abort_if($started && ! in_array($action, ['pay', 'prepare', 'ready', 'serve', 'collect', 'acknowledge', 'bill', 'transfer']), 422, 'This bill is locked after its first payment. Settle or refund the recorded payments before making changes.');
            $items = BusinessData::table('order_items')->where('order_id', $id);
            $resultId = $id;
            $line = null;
            $eventDetails = $r->except(['version']);
            if (in_array($action, ['edit-item', 'change-price', 'cancel-item', 'prepare', 'ready', 'serve', 'acknowledge'])) {
                $r->validate(['item_id' => 'required|integer']);
                $line = (clone $items)->where('id', $r->item_id)->first();
                abort_unless($line, 404, 'Item not found in this order.');
            }
            switch ($action) {
                case 'transfer':
                    $d = $r->validate(['table_id' => ['required', 'integer', BusinessData::exists('dining_tables')], 'reason' => 'required|string|max:255']);
                    $eventDetails = [...TableAssignments::transfer($order, (int) $d['table_id']), 'reason' => $d['reason']];
                    break;
                case 'merge':
                    $d = $r->validate(['target_order_id' => ['required', 'integer', BusinessData::exists('orders')], 'target_version' => 'required|integer|min:1', 'reason' => 'required|string|max:255']);
                    $target = BusinessData::table('orders')->where('id', $d['target_order_id'])->lockForUpdate()->first();
                    abort_unless($target->version === (int) $d['target_version'], 409, 'The other order changed. Review both bills before combining them.');
                    $eventDetails = [...TableAssignments::merge($order, $target), 'reason' => $d['reason']];
                    $this->event($r, $target->id, 'merge-received', $eventDetails);
                    $resultId = $target->id;
                    break;
                case 'discount':
                    $d = $r->validate(['discount_type' => 'required|in:none,fixed,percent', 'discount_value' => 'required|integer|min:0|max:1000000000', 'reason' => 'required|string|max:255', 'group_id' => 'nullable|integer']);
                    $d['discount_value'] = (int) $d['discount_value'];
                    $groupId = (int) ($d['group_id'] ?? $id);
                    $groupIds = json_decode($order->billing_group_ids ?? 'null', true) ?? [$id];
                    abort_unless(in_array($groupId, $groupIds, true), 422, 'Choose a billing group from this order.');
                    $group = BusinessData::table('orders')->find($groupId);
                    $subtotal = (int) (clone $items)->where('status', '!=', 'cancelled')->get()->filter(fn ($item) => ($item->origin_order_id ?? $item->order_id) === $groupId)->sum(fn ($item) => $item->price * $item->quantity);
                    abort_if($d['discount_type'] === 'percent' && $d['discount_value'] > 10000, 422, 'Percentage discounts cannot exceed 100%.');
                    abort_if($d['discount_type'] === 'fixed' && $d['discount_value'] > $subtotal, 422, 'A fixed discount cannot exceed the item subtotal.');
                    abort_if($d['discount_type'] === 'none' && $d['discount_value'] !== 0, 422, 'Removing a discount requires a zero value.');
                    abort_if($d['discount_type'] !== 'none' && $d['discount_value'] === 0, 422, 'Enter a discount greater than zero.');
                    $eventDetails = ['old_type' => $group->discount_type, 'old_value' => $group->discount_value, 'group_id' => $groupId, ...$d];
                    BusinessData::table('orders')->where('id', $groupId)->update(['discount_type' => $d['discount_type'], 'discount_value' => $d['discount_value'], 'discount_reason' => $d['reason'], 'discount_approved_by' => $r->user()->id]);
                    BusinessData::table('orders')->where('id', $id)->update(['bill_requested' => false]);
                    break;
                case 'change-price':
                    abort_if($line->status === 'cancelled', 422, 'A cancelled item cannot be repriced.');
                    $d = $r->validate(['price' => 'required|integer|min:0|max:10000000', 'reason' => 'nullable|string|max:255']);
                    (clone $items)->where('id', $line->id)->update(['price' => $d['price'], 'updated_at' => now()]);
                    $eventDetails = [
                        'item_id' => $line->id, 'item_name' => $line->name, 'old_price' => $line->price,
                        'new_price' => (int) $d['price'], 'quantity' => $line->quantity, 'reason' => $d['reason'] ?? null,
                    ];
                    break;
                case 'add-item':
                    $d = $r->validate(['menu_item_id' => 'required|integer', 'quantity' => 'required|integer|min:1|max:99', 'notes' => 'nullable|string|max:500', 'variant_id' => 'nullable|integer', 'addon_ids' => 'sometimes|array|max:30', 'addon_ids.*' => 'required|integer|distinct', 'options_version' => 'sometimes|integer|min:1']);
                    $menu = BusinessData::table('menu_items')->join('categories', 'categories.id', '=', 'menu_items.category_id')->where('menu_items.id', $d['menu_item_id'])->where('menu_items.active', true)->where('categories.active', true)->where('available', true)->select('menu_items.*')->first();
                    abort_unless($menu, 422, 'This food is unavailable.');
                    $quote = MenuOptions::quote($menu, $d);
                    BusinessData::table('order_items')->insert(['business_id' => BusinessData::id(), 'order_id' => $id, 'origin_order_id' => $id, 'menu_item_id' => $menu->id, 'name' => $menu->name, ...$quote, 'quantity' => $d['quantity'], 'notes' => $d['notes'] ?? '', 'created_at' => now(), 'updated_at' => now()]);
                    BusinessData::table('orders')->where('id', $id)->update(['bill_requested' => false]);
                    break;
                case 'configure-item':
                    $d = $r->validate(['item_id' => 'required|integer', 'quantity' => 'required|integer|min:1|max:99', 'notes' => 'nullable|string|max:500', 'variant_id' => 'nullable|integer', 'addon_ids' => 'present|array|max:30', 'addon_ids.*' => 'required|integer|distinct', 'options_version' => 'required|integer|min:1']);
                    $line = (clone $items)->where('id', $d['item_id'])->first();
                    abort_unless($line, 404);
                    abort_unless($line->status === 'draft', 422, 'Only unsent item choices can be edited.');
                    $menu = BusinessData::table('menu_items')->where('id', $line->menu_item_id)->where('active', true)->where('available', true)->first();
                    abort_unless($menu && BusinessData::table('categories')->where('id', $menu->category_id)->value('active'), 422, 'This food is unavailable.');
                    $quote = MenuOptions::quote($menu, $d);
                    (clone $items)->where('id', $line->id)->update([...$quote, 'quantity' => $d['quantity'], 'notes' => $d['notes'] ?? '', 'updated_at' => now()]);
                    break;
                case 'edit-item':
                    abort_unless($line->status === 'draft', 422, 'Only unsent items can be edited.');
                    $d = $r->validate(['quantity' => 'required|integer|min:0|max:99', 'notes' => 'nullable|string|max:500']);
                    if ($d['quantity'] === 0) {
                        (clone $items)->where('id', $line->id)->delete();
                    } else {
                        (clone $items)->where('id', $line->id)->update(['quantity' => $d['quantity'], 'notes' => $d['notes'] ?? $line->notes, 'updated_at' => now()]);
                    }
                    break;
                case 'submit':
                    $drafts = (clone $items)->where('status', 'draft')->get();
                    abort_if($drafts->isEmpty(), 422, 'There are no new items to send.');
                    foreach ($drafts as $draft) {
                        $food = BusinessData::table('menu_items')->find($draft->menu_item_id);
                        abort_unless($food->active && $food->available && BusinessData::table('categories')->where('id', $food->category_id)->value('active'), 422, "$draft->name is unavailable. Remove it before sending.");
                        MenuOptions::validateDraft($draft);
                    }
                    $ticket = BusinessData::table('kitchen_tickets')->insertGetId(['business_id' => BusinessData::id(), 'order_id' => $id, 'table_name' => $order->table_name, 'submitted_by' => $r->user()->id, 'created_at' => now()]);
                    (clone $items)->where('status', 'draft')->update(['status' => 'sent', 'ticket_id' => $ticket, 'updated_at' => now()]);
                    break;
                case 'prepare': case 'ready': case 'serve':
                    $from = match ($action) {
                        'prepare' => ['sent'], 'ready' => ['sent', 'preparing'], 'serve' => ['ready']
                    };
                    abort_unless(in_array($line->status, $from), 422, 'This item cannot move to that status.');
                    abort_if($action === 'serve' && $order->type !== 'dine_in', 422, 'Use collection for takeaway orders.');
                    (clone $items)->where('id', $line->id)->update(['status' => match ($action) {
                        'prepare' => 'preparing', 'ready' => 'ready', 'serve' => 'served'
                    }, 'updated_at' => now()]);
                    break;
                case 'cancel-item':
                    $r->validate(['reason' => 'required|string|max:500']);
                    abort_if(in_array($line->status, ['served', 'cancelled']), 422, 'This item cannot be cancelled.');
                    (clone $items)->where('id', $line->id)->update(['status' => 'cancelled', 'cancel_reason' => $r->reason, 'cancel_ack_at' => $line->ticket_id ? null : now(), 'updated_at' => now()]);
                    if (! (clone $items)->where('status', '!=', 'cancelled')->exists()) {
                        $this->close($id, 'cancelled');
                    }
                    break;
                case 'acknowledge':
                    abort_unless($line->status === 'cancelled' && $line->ticket_id && ! $line->cancel_ack_at, 422, 'There is no cancellation to acknowledge.');
                    (clone $items)->where('id', $line->id)->update(['cancel_ack_at' => now(), 'updated_at' => now()]);
                    break;
                case 'cancel':
                    $r->validate(['reason' => 'required|string|max:500']);
                    abort_if((clone $items)->where('status', 'served')->exists(), 422, 'An order with served food cannot be cancelled.');
                    foreach ((clone $items)->where('status', '!=', 'cancelled')->get() as $item) {
                        (clone $items)->where('id', $item->id)->update(['status' => 'cancelled', 'cancel_reason' => $r->reason, 'cancel_ack_at' => $item->ticket_id ? null : now(), 'updated_at' => now()]);
                    }
                    $this->close($id, 'cancelled');
                    break;
                case 'discard':
                    abort_if($items->exists(), 422, 'Only an empty order can be discarded.');
                    $this->close($id, 'cancelled');
                    break;
                case 'bill':
                    abort_unless($order->type === 'dine_in', 422, 'Only tables request a bill.');
                    BusinessData::table('orders')->where('id', $id)->update(['bill_requested' => true]);
                    break;
                case 'collect':
                    abort_unless($order->type === 'takeaway' && ! $order->collected_at, 422, 'This order cannot be collected.');
                    abort_if(! (clone $items)->where('status', '!=', 'cancelled')->exists() || (clone $items)->whereNotIn('status', ['ready', 'cancelled'])->exists(), 422, 'All food must be ready before collection.');
                    BusinessData::table('orders')->where('id', $id)->update(['collected_at' => now()]);
                    if ($order->paid) {
                        $this->close($id);
                    } break;
                case 'pay':
                    $d = $r->validate(['mode' => 'sometimes|in:full,amount,equal,items', 'amount' => 'required_if:mode,amount|integer|min:1|max:1000000000', 'shares' => 'required_if:mode,equal|integer|min:1|max:100',
                        'items' => 'required_if:mode,items|array|min:1|max:500', 'items.*' => 'array:item_id,quantity', 'items.*.item_id' => 'required|integer|distinct', 'items.*.quantity' => 'required|integer|min:1|max:99',
                        'payer_label' => 'nullable|string|max:100', 'method' => 'required_without:tenders|in:cash,other', 'tendered' => 'required_without:tenders|integer|min:0|max:1000000000',
                        'tenders' => 'sometimes|array|min:1|max:2', 'tenders.*' => 'array:method,amount,tendered,confirmed', 'tenders.*.method' => 'required|in:cash,other|distinct', 'tenders.*.amount' => 'required|integer|min:0|max:1000000000', 'tenders.*.tendered' => 'nullable|integer|min:0|max:1000000000', 'tenders.*.confirmed' => 'sometimes|boolean']);
                    foreach ($d['tenders'] ?? [] as $tender) {
                        abort_if($tender['method'] === 'other' && $tender['amount'] > 0 && ! ($tender['confirmed'] ?? false), 422, 'Confirm that the non-cash payment has been received.');
                    }
                    $validItems = (clone $items)->where('status', '!=', 'cancelled')->get();
                    abort_if($validItems->isEmpty() || $validItems->contains('status', 'draft'), 422, 'Submit or remove all draft items before payment.');
                    abort_if((clone $items)->where('status', 'cancelled')->whereNotNull('ticket_id')->whereNull('cancel_ack_at')->exists(), 422, 'The kitchen must acknowledge cancellations before payment.');
                    abort_if($order->type === 'dine_in' && $validItems->contains(fn ($i) => $i->status !== 'served'), 422, 'Serve all food before taking dine-in payment.');
                    $receiptId = OrderSettlement::pay($order, $d);
                    $eventDetails = ['receipt_id' => $receiptId, 'mode' => $d['mode'] ?? 'full'];
                    $settled = BusinessData::table('orders')->where('id', $id)->value('paid');
                    if ($settled && ($order->type === 'dine_in' || $order->collected_at)) {
                        $this->close($id);
                    } break;
                default: abort(404);
            }
            BusinessData::table('orders')->where('id', $id)->increment('version', 1, ['updated_at' => now()]);
            $this->event($r, $id, $action, $eventDetails);

            return $this->visibleOrder($r, $this->order($resultId));
        });
    }

    private function close(int $id, string $status = 'closed'): void
    {
        TableAssignments::release($id);
        BusinessData::table('orders')->where('id', $id)->update(['status' => $status, 'active_table_id' => null, 'closed_at' => now()]);
    }

    public function manage(Request $r, string $resource)
    {
        $this->allow($r, $resource === 'availability' ? ['kitchen'] : []);

        return $this->write($r, function () use ($r, $resource) {
            $id = $r->input('id');
            switch ($resource) {
                case 'menu-options':
                    $d = $r->validate(['id' => ['required', BusinessData::exists('menu_items')], 'options_version' => 'required|integer|min:1', 'variants' => 'present|array|max:20', 'addons' => 'present|array|max:30',
                        'variants.*' => 'array:id,name,price,active', 'variants.*.id' => 'nullable|integer', 'variants.*.name' => 'required|string|max:60|distinct:ignore_case', 'variants.*.price' => 'required|integer|min:0|max:10000000', 'variants.*.active' => 'required|boolean',
                        'addons.*' => 'array:id,name,price,active', 'addons.*.id' => 'nullable|integer', 'addons.*.name' => 'required|string|max:60|distinct:ignore_case', 'addons.*.price' => 'required|integer|min:0|max:10000000', 'addons.*.active' => 'required|boolean']);
                    MenuOptions::save((int) $d['id'], $d);
                    break;
                case 'charges':
                    $d = $r->validate(['tax_rate_bps' => 'required|integer|min:0|max:10000', 'service_rate_bps' => 'required|integer|min:0|max:10000', 'tax_label' => 'required|string|max:40', 'service_charge_label' => 'required|string|max:40', 'tax_on_service' => 'required|boolean', 'service_dine_in_only' => 'required|boolean']);
                    BusinessData::table('restaurant_settings')->update($d);
                    break;
                case 'availability':
                    $d = $r->validate(['id' => ['required', BusinessData::exists('menu_items')], 'available' => 'required|boolean']);
                    BusinessData::table('menu_items')->where('id', $d['id'])->update(['available' => $d['available']]);
                    break;
                case 'menu':
                    $d = $r->validate(['id' => ['nullable', BusinessData::exists('menu_items')], 'category_id' => ['required', BusinessData::exists('categories')], 'name' => 'required|string|max:100', 'description' => 'nullable|string|max:255', 'emoji' => 'required|string|max:12', 'price' => 'required|integer|min:0|max:10000000', 'available' => 'required|boolean', 'active' => 'required|boolean',
                        'image' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=6000,max_height=6000',
                        'remove_image' => 'sometimes|boolean',
                    ]);
                    $previousPath = $id ? BusinessData::table('menu_items')->where('id', $id)->value('image_path') : null;
                    unset($d['id'], $d['image'], $d['remove_image']);
                    $d['description'] = $d['description'] ?? '';
                    if ($r->hasFile('image')) {
                        $image = $r->file('image');
                        $filename = hash_file('sha256', $image->getRealPath()).'.'.$image->extension();
                        $path = $image->storeAs('menu-images/'.BusinessData::id(), $filename, 'local');
                        abort_unless($path, 500, 'The food image could not be saved. Please try again.');
                        $d['image_path'] = $path;
                        DB::afterRollBack(function () use ($path): void {
                            if (! BusinessData::table('menu_items')->where('image_path', $path)->exists()) {
                                Storage::disk('local')->delete($path);
                            }
                        });
                    } elseif ($r->boolean('remove_image')) {
                        $d['image_path'] = null;
                    }
                    if ($id) {
                        BusinessData::table('menu_items')->where('id', $id)->update($d);
                    } else {
                        BusinessData::table('menu_items')->insert([...$d, 'business_id' => BusinessData::id()]);
                    }
                    if ($previousPath && array_key_exists('image_path', $d) && $previousPath !== $d['image_path']) {
                        DB::afterCommit(function () use ($previousPath): void {
                            if (! BusinessData::table('menu_items')->where('image_path', $previousPath)->exists()) {
                                Storage::disk('local')->delete($previousPath);
                            }
                        });
                    }
                    break;
                case 'categories':
                    $d = $r->validate(['id' => ['nullable', BusinessData::exists('categories')], 'name' => ['required', 'string', 'max:80', Rule::unique('categories')->where('business_id', BusinessData::id())->ignore($id)], 'sort' => 'required|integer|min:0|max:999', 'active' => 'required|boolean']);
                    unset($d['id']);
                    $id ? BusinessData::table('categories')->where('id', $id)->update($d) : BusinessData::table('categories')->insert([...$d, 'business_id' => BusinessData::id()]);
                    break;
                case 'tables':
                    $d = $r->validate(['id' => ['nullable', BusinessData::exists('dining_tables')], 'name' => ['required', 'string', 'max:30', Rule::unique('dining_tables')->where('business_id', BusinessData::id())->ignore($id)], 'seats' => 'required|integer|min:1|max:30', 'active' => 'required|boolean']);
                    abort_if($id && ! $d['active'] && BusinessData::table('order_tables')->where('table_id', $id)->exists(), 422, 'Close the active order before deactivating this table.');
                    unset($d['id']);
                    $id ? BusinessData::table('dining_tables')->where('id', $id)->update($d) : BusinessData::table('dining_tables')->insert([...$d, 'business_id' => BusinessData::id()]);
                    break;
                case 'staff':
                    $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
                    $d = $r->validate(['id' => ['nullable', BusinessData::exists('users')], 'name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($id)], 'username' => ['required', 'alpha_dash', 'max:60', Rule::unique('users')->where('business_id', BusinessData::id())->ignore($id)], 'password' => ($id ? 'nullable' : 'required').'|string|min:6|max:100', 'roles' => 'required|array|min:1', 'roles.*' => 'in:manager,waiter,cashier,kitchen', 'active' => 'required|boolean']);
                    if ($id) {
                        abort_if(DB::table('businesses')->where('id', BusinessData::id())->value('owner_id') == $id, 422, 'The owner account is managed separately.');
                        $old = User::where('business_id', BusinessData::id())->findOrFail($id);
                        abort_if($old->active && in_array('manager', $old->roles ?? []) && (! $d['active'] || ! in_array('manager', $d['roles'])) && User::where('business_id', BusinessData::id())->where('active', true)->get()->filter(fn ($u) => in_array('manager', $u->roles ?? []))->count() <= 1, 422, 'Keep at least one active manager.');
                    }
                    $password = $d['password'] ?? null;
                    unset($d['id'], $d['password']);
                    $user = $id ? User::where('business_id', BusinessData::id())->findOrFail($id) : new User;
                    $user->fill($d);
                    if (! $id) {
                        $user->business_id = BusinessData::id();
                    } if ($password) {
                        $user->password = Hash::make($password);
                    } $user->save();
                    if ($id && ($password || ! $d['active'])) {
                        DB::table('sessions')->where('user_id', $id)->delete();
                    } break;
                case 'receipts':
                    $d = $r->validate([
                        'receipt_title' => 'present|nullable|string|max:100',
                        'footer' => 'present|nullable|string|max:255',
                        'receipt_paper_width' => 'required|integer|min:40|max:120',
                        'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:256|dimensions:max_width=1024,max_height=1024',
                        'remove_logo' => 'sometimes|boolean',
                    ]);
                    $d['receipt_title'] = $d['receipt_title'] ?? '';
                    $d['footer'] = $d['footer'] ?? '';
                    unset($d['image'], $d['remove_logo']);
                    if ($r->hasFile('image')) {
                        $logo = $r->file('image');
                        $d['logo_data_url'] = 'data:'.$logo->getMimeType().';base64,'.base64_encode(file_get_contents($logo->getRealPath()));
                    } elseif ($r->boolean('remove_logo')) {
                        $d['logo_data_url'] = null;
                    }
                    BusinessData::table('restaurant_settings')->update($d);
                    break;
                case 'settings':
                    $d = $r->validate(['name' => 'required|string|max:100', 'currency' => 'required|string|size:3', 'timezone' => 'required|timezone', 'contact' => 'nullable|string|max:255', 'footer' => 'sometimes|nullable|string|max:255', 'other_payment_label' => 'required|string|max:60']);
                    abort_if(BusinessData::table('business_finance_entries')->exists() && $d['currency'] !== BusinessData::table('restaurant_settings')->value('currency'), 422, 'Currency is locked after the first financial entry.');
                    abort_if(BusinessData::table('orders')->exists() && $d['currency'] !== BusinessData::table('restaurant_settings')->value('currency'), 422, 'Currency is locked after the first order.');
                    abort_if(BusinessData::table('cash_shifts')->whereNull('closed_at')->exists() && $d['currency'] !== BusinessData::table('restaurant_settings')->value('currency'), 422, 'Close the active cash shift before changing currency.');
                    $d['contact'] = $d['contact'] ?? '';
                    if (array_key_exists('footer', $d)) {
                        $d['footer'] = $d['footer'] ?? '';
                    }
                    BusinessData::table('restaurant_settings')->update($d);
                    break;
                default: abort(404);
            }
            $this->event($r, null, 'manage_'.$resource, ['id' => $id]);

            return ['ok' => true];
        });
    }
}
