<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RealtimeController extends Controller
{
    public function platform(Request $request): JsonResponse
    {
        return $this->credentials($request, []);
    }

    public function business(Request $request): JsonResponse
    {
        $id = BusinessData::id();

        $channels = ['business:'.$id, 'businesses', 'user:'.$request->user()->id];
        if (DB::table('businesses')->where('id', $id)->value('owner_id') === $request->user()->id) {
            $channels[] = 'finance:'.$id;
        }

        return $this->credentials($request, $channels);
    }

    public function office(Request $request): JsonResponse
    {
        return $this->credentials($request, ['office', 'user:'.Auth::guard('office')->id()]);
    }

    public function guest(Request $request): JsonResponse
    {
        $data = $request->validate(['menu_token' => 'required|string|max:100', 'request_token' => 'nullable|string|max:100']);
        $link = DB::table('guest_menu_links')->where('token', $data['menu_token'])->first();
        abort_unless($link, 404);
        $channels = ['menu:'.$link->business_id];
        if (! empty($data['request_token'])) {
            $guest = DB::table('guest_order_requests')->where('business_id', $link->business_id)->where('public_token', $data['request_token'])->first();
            abort_unless($guest, 404);
            $channels[] = 'guest:'.$guest->id;
        }

        return $this->credentials($request, $channels);
    }

    private function credentials(Request $request, array $channels): JsonResponse
    {
        $channels[] = 'platform';
        $channels[] = 'session:'.hash('sha256', $request->session()->getId());

        return response()->json(Realtime::credentials($request, $channels))->header('Cache-Control', 'no-store');
    }
}
