<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OfficeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('office')->user();
        abort_unless($user && $user->active && $user->is_platform_admin && $user->business_id === null, 401, 'Please sign in to the platform office.');

        $version = $request->session()->get('office_version');
        if ($version !== null && (int) $version !== $user->office_version) {
            Auth::guard('office')->logout();
            abort(401, 'Your office access changed. Please sign in again.');
        }
        $request->session()->put('office_version', $user->office_version);

        return $next($request);
    }
}
