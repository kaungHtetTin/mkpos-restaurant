<?php

namespace App\Support;

use App\Models\BusinessData;
use App\Models\MenuOptions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuestOrderFulfillment
{
    public static function accept(object $request, ?int $actorId): int
    {
        return DB::transaction(function () use ($request, $actorId): int {
            $businessId = (int) $request->business_id;
            $business = DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            abort_unless($business && BusinessData::writable($businessId), 403, 'This restaurant is not accepting orders. Please speak with staff.');
            $businessTable = fn (string $name): Builder => DB::table($name)->where($name.'.business_id', $businessId);
            abort_if(now()->diffInMinutes($request->created_at, true) > 120, 422, 'This request expired. Ask the guest to submit a fresh request.');
            $table = $request->table_id ? $businessTable('dining_tables')->where('active', true)->find($request->table_id) : null;
            abort_if($request->table_id && ! $table, 422, 'This table is inactive. Decline the request and help the guest order.');
            $lines = collect(json_decode($request->items, true));
            foreach ($lines as $item) {
                $food = $businessTable('menu_items')->where('active', true)->where('available', true)->find($item['menu_item_id']);
                abort_unless($food && $businessTable('categories')->where('id', $food->category_id)->value('active'), 422, 'A requested dish is unavailable. Decline the request and help the guest choose again.');
                $quote = MenuOptions::quote($food, $item, $businessId);
                abort_unless($quote['price'] === $item['price'] && $food->name === $item['name'], 409, 'The requested menu or price changed. Decline this request and ask the guest to refresh the menu.');
            }
            $settings = $businessTable('restaurant_settings')->first();
            $expected = json_decode($request->totals_snapshot, true);
            foreach (['tax_rate_bps', 'service_rate_bps', 'tax_on_service', 'tax_label', 'service_charge_label'] as $field) {
                $current = $field === 'service_rate_bps' && ! $table && $settings->service_dine_in_only ? 0 : $settings->$field;
                abort_unless($current == $expected[$field], 409, 'Charges changed after this request. Decline it and ask the guest to refresh the menu.');
            }
            $existing = $table ? $businessTable('order_tables')->where('table_id', $table->id)->first() : null;
            $order = $existing ? $businessTable('orders')->find($existing->order_id) : null;
            if ($order) {
                abort_unless($order->status === 'open' && ! $order->paid && ! $businessTable('payments')->where('order_id', $order->id)->exists(), 422, 'This table is settling its bill. Please order with staff.');
                abort_if($order->billing_group_ids || $order->discount_type !== 'none', 422, 'This table has a combined bill or discount. Please order with staff.');
                foreach (['tax_rate_bps', 'service_rate_bps', 'tax_on_service', 'tax_label', 'service_charge_label'] as $field) {
                    abort_unless($expected[$field] == $order->$field, 409, 'This table uses different charges. Please order with staff.');
                }
                $orderId = $order->id;
            } else {
                $orderId = $businessTable('orders')->insertGetId(['business_id' => $businessId, 'number' => 'GUEST-'.Str::ulid(), 'type' => $table ? 'dine_in' : 'takeaway', 'active_table_id' => $table?->id, 'table_name' => $table?->name, 'billing_label' => $table?->name, 'customer_name' => $request->guest_name, 'created_by' => $actorId, 'tax_rate_bps' => $expected['tax_rate_bps'], 'service_rate_bps' => $expected['service_rate_bps'], 'tax_label' => $expected['tax_label'], 'service_charge_label' => $expected['service_charge_label'], 'tax_on_service' => $expected['tax_on_service'], 'created_at' => now(), 'updated_at' => now()]);
                $businessTable('orders')->where('id', $orderId)->update(['number' => 'LL-'.str_pad((string) $orderId, 5, '0', STR_PAD_LEFT)]);
                if ($table) {
                    $businessTable('order_tables')->insert(['business_id' => $businessId, 'order_id' => $orderId, 'table_id' => $table->id]);
                    $businessTable('orders')->where('id', $orderId)->update(['table_names_snapshot' => json_encode([$table->name])]);
                }
            }
            $ticket = $businessTable('kitchen_tickets')->insertGetId(['business_id' => $businessId, 'order_id' => $orderId, 'table_name' => $table?->name, 'submitted_by' => $actorId, 'created_at' => now()]);
            foreach ($lines as $item) {
                $businessTable('order_items')->insert(['business_id' => $businessId, 'order_id' => $orderId, 'origin_order_id' => $orderId, 'menu_item_id' => $item['menu_item_id'], 'name' => $item['name'], 'price' => $item['price'], 'quantity' => $item['quantity'], 'notes' => $item['notes'] ?? '', 'options_snapshot' => json_encode($item['options_snapshot']), 'status' => 'sent', 'ticket_id' => $ticket, 'created_at' => now(), 'updated_at' => now()]);
            }
            $businessTable('orders')->where('id', $orderId)->increment('version', 1, ['updated_at' => now(), 'bill_requested' => false]);

            return $orderId;
        }, 3);
    }
}
