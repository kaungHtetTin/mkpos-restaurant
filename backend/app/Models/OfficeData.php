<?php

namespace App\Models;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OfficeData
{
    public static function super(): void
    {
        abort_unless(Auth::guard('office')->user()?->office_role === 'super_admin', 403, 'Super administrator access is required.');
    }

    private static function fingerprint(Request $request): string
    {
        $payload = $request->except(['image', 'receipt', 'screenshot']);
        ksort($payload);
        $files = '';
        foreach (['image', 'receipt', 'screenshot'] as $field) {
            if ($request->hasFile($field)) {
                $files .= hash_file('sha256', $request->file($field)->getRealPath());
            }
        }

        return hash('sha256', $request->path().'|'.json_encode($payload).'|'.$files);
    }

    public static function replay(Request $request): ?JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key)) {
            return null;
        }
        $existing = DB::table('office_commands')->where('admin_id', Auth::guard('office')->id())->where('key', $key)->first();
        if (! $existing) {
            return null;
        }
        abort_unless(hash_equals($existing->fingerprint, self::fingerprint($request)), 409, 'This request key was already used for another action.');

        return response()->json(json_decode($existing->response, true));
    }

    public static function write(Request $request, callable $callback): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 100, 422, 'A request key is required.');

        return DB::transaction(function () use ($request, $callback, $key) {
            DB::table('office_settings')->where('id', 1)->lockForUpdate()->first();
            $admin = Auth::guard('office')->id();
            $fingerprint = self::fingerprint($request);
            $existing = DB::table('office_commands')->where('admin_id', $admin)->where('key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'This request key was already used for another action.');

                return response()->json(json_decode($existing->response, true));
            }
            $result = $callback();
            DB::table('office_commands')->insert(['admin_id' => $admin, 'key' => $key, 'fingerprint' => $fingerprint, 'response' => json_encode($result), 'created_at' => now()]);

            return response()->json($result);
        }, 3);
    }

    public static function audit(?int $business, string $action, array $details = []): void
    {
        DB::table('platform_events')->insert(['business_id' => $business, 'actor_id' => Auth::guard('office')->id(), 'action' => $action, 'details' => json_encode($details), 'created_at' => now()]);
    }

    public static function pageSize(Request $request): int
    {
        $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|in:10,25,50,100']);

        return (int) $request->input('per_page', 25);
    }
}
