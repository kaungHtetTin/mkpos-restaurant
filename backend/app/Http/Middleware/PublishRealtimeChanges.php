<?php

namespace App\Http\Middleware;

use App\Support\Realtime;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PublishRealtimeChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionChannel = 'session:'.hash('sha256', $request->session()->getId());
        $response = $next($request);
        if ($request->isMethod('GET') || ! $response->isSuccessful() || $request->is('api/v1/realtime/*', 'api/v1/office/realtime')) {
            return $response;
        }
        $channels = [];
        $disconnect = [];
        $topic = 'business';
        if ($request->is('api/v1/logout', 'api/v1/office/logout')) {
            $disconnect[] = $sessionChannel;
        } elseif ($request->is('api/v1/office/*')) {
            $channels[] = 'office';
            $topic = 'office';
            if ($request->is('api/v1/office/manage/settings')) {
                $channels[] = 'platform';
                $topic = 'platform';
            }
            if ($request->route('id') && $request->is('api/v1/office/businesses/*')) {
                $id = (int) $request->route('id');
                $channels = [...$channels, 'business:'.$id, 'menu:'.$id];
                if (! $request->is('*/subscription')) {
                    $disconnect[] = 'business:'.$id;
                }
            } elseif ($request->is('api/v1/office/requests/*/review')) {
                $id = DB::table('subscription_requests')->where('id', $request->route('id'))->value('business_id');
                if ($id) {
                    $channels[] = 'business:'.$id;
                    $channels[] = 'menu:'.$id;
                }
            } elseif ($request->is('api/v1/office/manage/admins', 'api/v1/office/account')) {
                $disconnect[] = 'office';
            } elseif ($request->is('api/v1/office/reports/*', 'api/v1/office/manage/reports', 'api/v1/office/manage/content')) {
                $channels[] = 'businesses';
            }
        } elseif ($request->is('api/v1/guest/menu/*/requests', 'api/v1/guest/menu/*/bill')) {
            $id = DB::table('guest_menu_links')->where('token', $request->route('token'))->value('business_id');
            if ($id) {
                $channels[] = 'business:'.$id;
                $channels[] = 'menu:'.$id;
            }
        } elseif ($request->user()?->business_id && $request->is('api/v1/business-finance/*')) {
            $channels[] = 'finance:'.$request->user()->business_id;
            $topic = 'finance';
        } elseif ($request->user()?->business_id) {
            $id = $request->user()->business_id;
            $channels[] = 'business:'.$id;
            if ($request->is('api/v1/manage/*', 'api/v1/orders/*', 'api/v1/payments/*', 'api/v1/guest-orders/configure', 'api/v1/setup')) {
                $channels[] = 'menu:'.$id;
            }
            if ($request->is('api/v1/manage/staff')) {
                $disconnect[] = 'business:'.$id;
            }
            if ($request->is('api/v1/guest-orders/*/review')) {
                $guest = DB::table('guest_order_requests')->where('business_id', $id)->find($request->route('id'));
                if ($guest) {
                    $channels[] = 'guest:'.$guest->id;
                }
                $channels[] = 'menu:'.$id;
            }
            if ($request->is('api/v1/billing/requests', 'api/v1/help/reports')) {
                $channels[] = 'office';
            }
        }
        $key = $request->header('Idempotency-Key');
        DB::afterCommit(fn () => Realtime::publish($channels, $topic, $disconnect, is_string($key) && strlen($key) <= 100 ? $key : null));

        return $response;
    }
}
