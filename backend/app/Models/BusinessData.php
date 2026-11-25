<?php

namespace App\Models;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class BusinessData
{
    public static function id(): int
    {
        $user = request()->user();
        abort_unless($user && $user->business_id && ! $user->is_platform_admin, 403, 'A business account is required.');

        return (int) $user->business_id;
    }

    public static function table(string $table): Builder
    {
        return DB::table($table)->where($table.'.business_id', self::id());
    }

    public static function exists(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('business_id', self::id());
    }

    public static function subscription(int $businessId): ?object
    {
        $subscription = DB::table('subscriptions')->where('business_id', $businessId)->first();
        if ($subscription && in_array($subscription->status, ['trial', 'active']) && now()->gte($subscription->ends_at)) {
            $subscription->status = 'expired';
        }

        if ($subscription) {
            $subscription->is_complimentary = (bool) ($subscription->is_complimentary ?? false);
        }

        return $subscription;
    }

    public static function writable(int $businessId): bool
    {
        $subscription = self::subscription($businessId);

        return DB::table('businesses')->where('id', $businessId)->value('status') === 'active'
            && $subscription && in_array($subscription->status, ['trial', 'active']) && now()->gte($subscription->starts_at);
    }
}
