<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class Controller
{
    protected function allow(Request $r, array $roles): void
    {
        abort_unless(array_intersect($r->user()->roles ?? [], ['manager', ...$roles]), 403, 'Your role cannot perform this action.');
    }

    protected function write(Request $r, callable $callback): JsonResponse
    {
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 100, 422, 'An idempotency key is required.');

        return DB::transaction(function () use ($r, $callback, $key) {
            DB::table('businesses')->where('id', BusinessData::id())->lockForUpdate()->first();
            abort_unless(BusinessData::writable(BusinessData::id()), 403, 'This business is read-only. Contact the platform office to restore access.');
            BusinessData::table('restaurant_settings')->lockForUpdate()->first();
            $payload = $r->except('image');
            ksort($payload);
            $imageHash = $r->hasFile('image') ? hash_file('sha256', $r->file('image')->getRealPath()) : '';
            $hash = hash('sha256', $r->path().'|'.json_encode($payload).'|'.$imageHash);
            $existing = BusinessData::table('idempotency_keys')->where('user_id', $r->user()->id)->where('key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $hash), 409, 'This request key was already used for another action.');

                return response()->json(json_decode($existing->response, true));
            }
            $data = $callback();
            BusinessData::table('idempotency_keys')->insert(['business_id' => BusinessData::id(), 'user_id' => $r->user()->id, 'key' => $key, 'fingerprint' => $hash, 'response' => json_encode($data), 'created_at' => now()]);

            return response()->json($data);
        }, 3);
    }

    protected function event(Request $r, ?int $order, string $action, array $details = []): void
    {
        BusinessData::table('operational_events')->insert(['business_id' => BusinessData::id(), 'order_id' => $order, 'user_id' => $r->user()->id, 'action' => $action, 'details' => json_encode($details), 'created_at' => now()]);
    }
}
