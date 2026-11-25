<?php

namespace App\Http\Middleware;

use App\Support\PlatformBranding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyPlatformBranding
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $name = PlatformBranding::name();
        config(['app.name' => $name, 'mail.from.name' => $name]);

        return $next($request);
    }
}
