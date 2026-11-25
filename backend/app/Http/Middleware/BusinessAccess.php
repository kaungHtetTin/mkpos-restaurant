<?php

namespace App\Http\Middleware;

use App\Models\BusinessData;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class BusinessAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = BusinessData::id();
        $business = DB::table('businesses')->find($id);
        abort_unless($business, 403);
        $owner = $business->owner_id === $request->user()->id;
        abort_if(! $business->setup_completed_at && ! $request->is('api/v1/state', 'api/v1/setup'), 403, 'Complete your business setup before using the workspace.');
        abort_if($business->status !== 'active' && (! $owner || ! $request->isMethod('GET')), 403, 'This business is suspended. Please contact the platform office.');
        abort_if(! $request->isMethod('GET') && ! BusinessData::writable($id), 403, 'Your subscription has expired or was cancelled. Contact the platform office to renew.');

        return $next($request);
    }
}
