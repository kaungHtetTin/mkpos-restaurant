<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use App\Models\MenuOptions;
use App\Models\OrderTotals;
use App\Support\GuestOrderFulfillment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GuestMenuController extends Controller
{
    private function context(string $token): array
    {
        $link = DB::table('guest_menu_links')->where('token', $token)->first();
        abort_unless($link, 404, 'This menu link is no longer available. Ask a staff member for the current menu.');
        $business = DB::table('businesses')->find($link->business_id);
        $settings = DB::table('restaurant_settings')->where('business_id', $link->business_id)->first();
        abort_unless($business?->setup_completed_at && $settings?->guest_menu_enabled && BusinessData::writable($link->business_id), 404, 'This restaurant menu is currently unavailable. Please ask a staff member.');
        $table = $link->table_id ? DB::table('dining_tables')->where('business_id', $link->business_id)->where('active', true)->find($link->table_id) : null;
        abort_if($link->table_id && ! $table, 404, 'This table link is unavailable. Please ask a staff member.');

        return [$link, $business, $settings, $table];
    }

    public function menu(string $token): JsonResponse
    {
        [$link, $business, $settings, $table] = $this->context($token);
        $currentOrder = $table ? DB::table('orders')
            ->join('order_tables', 'order_tables.order_id', '=', 'orders.id')
            ->where('orders.business_id', $business->id)->where('order_tables.business_id', $business->id)
            ->where('order_tables.table_id', $table->id)->where('orders.status', 'open')
            ->select('orders.id', 'orders.number', 'orders.bill_requested', 'orders.paid')->first() : null;
        $tableOrder = $currentOrder ? [
            'number' => $currentOrder->number,
            'bill_requested' => (bool) $currentOrder->bill_requested,
            'can_request_bill' => ! $currentOrder->paid,
            'items' => DB::table('order_items')->where('business_id', $business->id)->where('order_id', $currentOrder->id)
                ->whereIn('status', ['sent', 'preparing', 'ready', 'served'])->orderBy('id')
                ->get(['name', 'quantity', 'price', 'status', 'options_snapshot'])
                ->map(fn ($item): array => [
                    'name' => $item->name, 'quantity' => $item->quantity, 'price' => $item->price,
                    'status' => $item->status, 'options_snapshot' => json_decode($item->options_snapshot ?: '[]', true),
                ])->values(),
        ] : null;
        $categories = DB::table('categories')->where('business_id', $business->id)->where('active', true)->orderBy('sort')->get(['id', 'name']);
        $options = DB::table('menu_options')->where('business_id', $business->id)->whereNull('archived_at')->where('active', true)->get(['id', 'menu_item_id', 'kind', 'name', 'price', 'active'])->groupBy('menu_item_id');
        $menu = DB::table('menu_items')->where('business_id', $business->id)->where('active', true)->whereIn('category_id', $categories->pluck('id'))->orderBy('id')->get()->map(function ($food) use ($options, $token): array {
            $choices = $options->get($food->id, collect())->values();
            $price = $food->uses_variants ? $choices->where('kind', 'variant')->min('price') : $food->price;

            return ['id' => $food->id, 'category_id' => $food->category_id, 'name' => $food->name, 'description' => $food->description, 'emoji' => $food->emoji, 'price' => $food->price, 'display_price' => $price, 'uses_variants' => (bool) $food->uses_variants, 'options_version' => $food->options_version, 'options' => $choices,
                'orderable' => (bool) $food->available && $price !== null, 'image_url' => $food->image_path ? '/api/v1/guest/menu/'.$token.'/images/'.basename($food->image_path) : null];
        });

        return response()->json(['name' => $business->name, 'currency' => $settings->currency, 'table_name' => $table?->name, 'table_order' => $tableOrder, 'ordering_enabled' => (bool) $settings->guest_ordering_enabled, 'direct_to_kitchen' => (bool) $settings->guest_direct_to_kitchen, 'categories' => $categories, 'menu' => $menu,
            'charges' => ['tax_rate_bps' => $settings->tax_rate_bps, 'service_rate_bps' => ! $table && $settings->service_dine_in_only ? 0 : $settings->service_rate_bps, 'tax_on_service' => (bool) $settings->tax_on_service, 'tax_label' => $settings->tax_label, 'service_charge_label' => $settings->service_charge_label]])->header('Cache-Control', 'no-store');
    }

    public function image(string $token, string $filename): BinaryFileResponse
    {
        [$link] = $this->context($token);
        $paths = ['menu-images/'.$link->business_id.'/'.$filename, 'menu-images/'.$filename];
        $path = DB::table('menu_items')->where('business_id', $link->business_id)->where('active', true)->whereIn('image_path', $paths)->value('image_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function requestBill(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['order_number' => 'required|string|max:100']);

        return DB::transaction(function () use ($token, $data): JsonResponse {
            [$link] = $this->context($token);
            DB::table('businesses')->where('id', $link->business_id)->lockForUpdate()->first();
            [, $business, , $table] = $this->context($token);
            abort_unless($table, 422, 'Bill requests are only available for table orders.');
            $order = DB::table('orders')->join('order_tables', 'order_tables.order_id', '=', 'orders.id')
                ->where('orders.business_id', $business->id)->where('order_tables.business_id', $business->id)
                ->where('order_tables.table_id', $table->id)->where('orders.status', 'open')
                ->where('orders.number', $data['order_number'])->select('orders.*')->lockForUpdate()->first();
            abort_unless($order, 409, 'The table order has changed. Refresh the menu before requesting the bill.');
            abort_if($order->paid, 422, 'This table bill has already been paid.');
            abort_unless(DB::table('order_items')->where('business_id', $business->id)->where('order_id', $order->id)
                ->whereIn('status', ['sent', 'preparing', 'ready', 'served'])->exists(), 422, 'There are no confirmed items to bill yet.');
            if (! $order->bill_requested) {
                DB::table('orders')->where('business_id', $business->id)->where('id', $order->id)
                    ->increment('version', 1, ['bill_requested' => true, 'updated_at' => now()]);
                DB::table('operational_events')->insert([
                    'business_id' => $business->id, 'order_id' => $order->id, 'user_id' => null,
                    'action' => 'guest-bill-request', 'details' => json_encode(['source' => 'qr_menu']), 'created_at' => now(),
                ]);
            }

            return response()->json(['bill_requested' => true]);
        }, 3);
    }

    public function submit(Request $r, string $token): JsonResponse
    {
        $data = $r->validate(['guest_name' => 'nullable|string|max:100', 'items' => 'required|array|min:1|max:30', 'items.*.menu_item_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:1|max:20', 'items.*.price' => 'required|integer|min:0|max:10000000', 'items.*.notes' => 'nullable|string|max:500', 'items.*.options_version' => 'required|integer|min:1', 'items.*.variant_id' => 'nullable|integer', 'items.*.addon_ids' => 'present|array|max:30', 'items.*.addon_ids.*' => 'integer|distinct', 'charges' => 'required|array']);
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 100, 422, 'A request key is required.');

        return DB::transaction(function () use ($token, $data, $key) {
            [$link] = $this->context($token);
            DB::table('businesses')->where('id', $link->business_id)->lockForUpdate()->first();
            [$link, $business, $settings, $table] = $this->context($token);
            $hash = hash('sha256', $token.'|'.json_encode($data));
            $existing = DB::table('guest_order_requests')->where('business_id', $business->id)->where('request_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $hash), 409, 'This request key was already used.');

                return response()->json($this->statusData($existing));
            }
            abort_unless($settings->guest_ordering_enabled, 422, 'Online requests are paused. Please order with a staff member.');
            abort_if(! $settings->guest_direct_to_kitchen && DB::table('guest_order_requests')->where('business_id', $business->id)->where('status', 'pending')->where('created_at', '>', now()->subHours(2))->count() >= 100, 429, 'The restaurant is busy reviewing requests. Please order with a staff member.');
            $items = collect($data['items'])->map(function ($item) use ($business): array {
                $food = DB::table('menu_items')->where('business_id', $business->id)->where('active', true)->where('available', true)->find($item['menu_item_id']);
                abort_unless($food && DB::table('categories')->where('id', $food->category_id)->where('business_id', $business->id)->value('active'), 422, 'A dish is no longer available. Refresh the menu and review your request.');
                $quote = MenuOptions::quote($food, $item, $business->id);
                abort_unless($quote['price'] === (int) $item['price'], 409, 'Prices changed. Refresh the menu and review your request.');

                return [...$item, 'name' => $food->name, 'price' => $quote['price'], 'options_snapshot' => json_decode($quote['options_snapshot'], true), 'status' => 'draft'];
            });
            $pricing = (object) [...(array) $settings, 'discount_type' => 'none', 'discount_value' => 0, 'service_rate_bps' => ! $table && $settings->service_dine_in_only ? 0 : $settings->service_rate_bps];
            $totals = OrderTotals::calculate($pricing, $items->map(fn ($item) => (object) $item));
            foreach (['tax_rate_bps', 'service_rate_bps', 'tax_on_service', 'tax_label', 'service_charge_label'] as $field) {
                abort_unless(array_key_exists($field, $data['charges']) && $data['charges'][$field] == $totals[$field], 409, 'Charges changed. Refresh the menu and review your request.');
            }
            $id = DB::table('guest_order_requests')->insertGetId(['business_id' => $business->id, 'table_id' => $table?->id, 'table_label' => $table?->name, 'public_token' => Str::random(48), 'request_key' => $key, 'fingerprint' => $hash, 'guest_name' => $data['guest_name'] ?? null, 'items' => json_encode($items), 'totals_snapshot' => json_encode($totals), 'created_at' => now()]);

            if ($settings->guest_direct_to_kitchen) {
                $orderId = GuestOrderFulfillment::accept(DB::table('guest_order_requests')->find($id), null);
                DB::table('guest_order_requests')->where('id', $id)->update(['status' => 'accepted', 'auto_accepted' => true, 'order_id' => $orderId, 'reviewed_at' => now()]);
                DB::table('operational_events')->insert(['business_id' => $business->id, 'order_id' => $orderId, 'user_id' => null, 'action' => 'guest-order-direct', 'details' => json_encode(['request_id' => $id, 'source' => 'qr_menu']), 'created_at' => now()]);
            }

            return response()->json($this->statusData(DB::table('guest_order_requests')->find($id)), 201);
        }, 3);
    }

    public function status(string $token): JsonResponse
    {
        $request = DB::table('guest_order_requests')->where('public_token', $token)->first();
        abort_unless($request, 404);

        return response()->json($this->statusData($request))->header('Cache-Control', 'no-store');
    }

    private function statusData(object $request): array
    {
        return ['token' => $request->public_token, 'reference' => 'G-'.str_pad((string) $request->id, 5, '0', STR_PAD_LEFT), 'auto_accepted' => (bool) $request->auto_accepted, 'status' => $request->status === 'pending' && now()->diffInMinutes($request->created_at, true) > 120 ? 'expired' : $request->status, 'reason' => $request->reason, 'created_at' => $request->created_at];
    }
}
