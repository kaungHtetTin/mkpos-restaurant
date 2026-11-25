<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Realtime
{
    public static function credentials(Request $request, array $channels): array
    {
        abort_unless(config('realtime.enabled'), 503, 'Realtime is unavailable.');
        $expires = now()->addMinutes(max(1, min(1440, (int) config('session.lifetime', 120))))->timestamp;
        $payload = rtrim(strtr(base64_encode(json_encode(['channels' => $channels, 'exp' => $expires, 'nonce' => (string) Str::uuid()])), '+/', '-_'), '=');
        $token = $payload.'.'.hash_hmac('sha256', $payload, config('realtime.secret'));
        $url = config('realtime.public_url') ?: ($request->isSecure() ? 'wss://' : 'ws://').$request->getHost().':'.config('realtime.port').'/socket';

        return ['url' => $url, 'token' => $token, 'expires_at' => $expires];
    }

    public static function publish(array $channels, string $topic, array $disconnect = [], ?string $requestKey = null): void
    {
        if (! config('realtime.enabled') || (! $channels && ! $disconnect)) {
            return;
        }
        $body = json_encode(['id' => (string) Str::uuid(), 'channels' => array_values(array_unique($channels)), 'topic' => $topic, 'disconnect' => $disconnect, 'request_key' => $requestKey]);
        $timestamp = (string) time();
        try {
            Http::connectTimeout(0.3)->timeout(1)->withHeaders([
                'X-Realtime-Timestamp' => $timestamp,
                'X-Realtime-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, config('realtime.secret')),
            ])->withBody($body, 'application/json')->post(config('realtime.publish_url'))->throw();
        } catch (\Throwable $error) {
            Log::warning('Realtime delivery failed; committed data is retained.', ['topic' => $topic, 'error' => $error->getMessage()]);
        }
    }
}
