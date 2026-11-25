<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class ActiveStaff
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !$request->user()->active) {
            Auth::logout(); abort(401, 'Please sign in with an active staff account.');
        }
        return $next($request);
    }
}
