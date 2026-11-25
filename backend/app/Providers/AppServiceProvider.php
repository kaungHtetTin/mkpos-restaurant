<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('guest-read', fn (Request $request) => [
            Limit::perMinute(240)->by('link:'.hash('sha256', $request->route('token').'|'.$request->ip())),
            Limit::perMinute(3000)->by('network:'.$request->ip()),
        ]);
        RateLimiter::for('guest-submit', fn (Request $request) => [
            Limit::perMinute(10)->by('link:'.hash('sha256', $request->route('token').'|'.$request->ip())),
            Limit::perMinute(120)->by('network:'.$request->ip()),
        ]);
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
    }
}
