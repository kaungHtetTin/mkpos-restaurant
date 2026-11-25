<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use App\Support\GuestOrderFulfillment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GuestOrdersController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $this->allow($r, ['waiter', 'cashier']);
        $requests = BusinessData::table('guest_order_requests')->where(fn ($query) => $query->where('status', 'pending')->orWhere('reviewed_at', '>', now()->subDay()))->orderByRaw("CASE WHEN status = 'pending' AND created_at > ? THEN 0 ELSE 1 END", [now()->subHours(2)])->orderByDesc('id')->limit(150)->get();
        $requests->each(function ($request): void {
            $request->items = json_decode($request->items, true);
            $request->totals_snapshot = json_decode($request->totals_snapshot, true);
            $request->auto_accepted = (bool) $request->auto_accepted;
            $request->expired = $request->status === 'pending' && now()->diffInMinutes($request->created_at, true) > 120;
            unset($request->public_token, $request->fingerprint, $request->request_key);
        });

        return response()->json(['requests' => $requests, 'links' => in_array('manager', $r->user()->roles) ? BusinessData::table('guest_menu_links')->orderBy('id')->get() : []]);
    }

    public function configure(Request $r): JsonResponse
    {
        $this->allow($r, []);
        $data = $r->validate(['action' => 'required|in:settings,link,rotate,enable', 'guest_menu_enabled' => 'required_if:action,settings|boolean', 'guest_ordering_enabled' => 'required_if:action,settings|boolean', 'guest_direct_to_kitchen' => 'sometimes|boolean', 'table_id' => ['nullable', 'integer', BusinessData::exists('dining_tables')]]);

        return $this->write($r, function () use ($r, $data) {
            if ($data['action'] === 'enable') {
                $tables = BusinessData::table('dining_tables')->where('active', true)->orderBy('id')->pluck('id')->all();
                foreach ([null, ...$tables] as $tableId) {
                    if (! BusinessData::table('guest_menu_links')->where('table_id', $tableId)->exists()) {
                        BusinessData::table('guest_menu_links')->insert(['business_id' => BusinessData::id(), 'table_id' => $tableId, 'token' => Str::random(48), 'created_at' => now()]);
                    }
                }
                BusinessData::table('restaurant_settings')->update(['guest_menu_enabled' => true, 'guest_ordering_enabled' => true]);
                $this->event($r, null, 'guest-menu-enabled', ['table_ids' => $tables]);

                return ['saved' => true];
            }
            if ($data['action'] === 'settings') {
                abort_if($data['guest_ordering_enabled'] && ! $data['guest_menu_enabled'], 422, 'Enable the guest menu before taking requests.');
                abort_if(($data['guest_direct_to_kitchen'] ?? false) && ! $data['guest_ordering_enabled'], 422, 'Enable guest orders before sending them directly to the kitchen.');
                $direct = $data['guest_ordering_enabled'] && ($data['guest_direct_to_kitchen'] ?? BusinessData::table('restaurant_settings')->value('guest_direct_to_kitchen'));
                BusinessData::table('restaurant_settings')->update(['guest_menu_enabled' => $data['guest_menu_enabled'], 'guest_ordering_enabled' => $data['guest_ordering_enabled'], 'guest_direct_to_kitchen' => $direct]);
                $this->event($r, null, 'guest-menu-settings', $data);

                return ['saved' => true];
            }
            $tableId = $data['table_id'] ?? null;
            if ($tableId) {
                abort_unless(BusinessData::table('dining_tables')->where('id', $tableId)->value('active'), 422, 'Choose an active table.');
            }
            $query = BusinessData::table('guest_menu_links')->where('table_id', $tableId);
            $link = (clone $query)->first();
            if (! $link) {
                $id = BusinessData::table('guest_menu_links')->insertGetId(['business_id' => BusinessData::id(), 'table_id' => $tableId, 'token' => Str::random(48), 'created_at' => now()]);
            } else {
                $id = $link->id;
                if ($data['action'] === 'rotate') {
                    (clone $query)->update(['token' => Str::random(48)]);
                }
            }
            $this->event($r, null, 'guest-menu-'.$data['action'], ['table_id' => $tableId]);

            return (array) BusinessData::table('guest_menu_links')->find($id);
        });
    }

    public function review(Request $r, int $id): JsonResponse
    {
        $this->allow($r, ['waiter', 'cashier']);
        $data = $r->validate(['action' => 'required|in:accept,reject', 'reason' => 'required_if:action,reject|nullable|string|max:255', 'confirmed' => 'exclude_unless:action,accept|required|accepted']);

        return $this->write($r, function () use ($r, $id, $data) {
            $request = BusinessData::table('guest_order_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($request, 404);
            abort_unless($request->status === 'pending', 409, 'This request has already been reviewed. Refresh to see its status.');
            $orderId = null;
            if ($data['action'] === 'accept') {
                $orderId = GuestOrderFulfillment::accept($request, $r->user()->id);
            }
            BusinessData::table('guest_order_requests')->where('id', $id)->update(['status' => $data['action'] === 'accept' ? 'accepted' : 'rejected', 'reason' => $data['reason'] ?? null, 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'order_id' => $orderId]);
            $this->event($r, $orderId, 'guest-request-'.$data['action'], ['request_id' => $id, 'reason' => $data['reason'] ?? null]);

            return ['order_id' => $orderId, 'status' => $data['action'] === 'accept' ? 'accepted' : 'rejected'];
        });
    }
}
