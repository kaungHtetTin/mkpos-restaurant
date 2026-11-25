<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendSubscriptionNotices extends Command
{
    protected $signature = 'platform:subscription-notices';

    protected $description = 'Email owners once when their current subscription is within seven days of expiry';

    public function handle(): int
    {
        $subscriptions = DB::table('subscriptions')->join('businesses', 'businesses.id', '=', 'subscriptions.business_id')
            ->join('users', 'users.id', '=', 'businesses.owner_id')
            ->whereIn('subscriptions.status', ['trial', 'active'])->where('users.active', true)->where('businesses.status', 'active')
            ->whereBetween('subscriptions.ends_at', [now(), now()->addDays(7)])
            ->select('subscriptions.*', 'users.email', 'businesses.name as business_name')->get();
        foreach ($subscriptions as $subscription) {
            DB::transaction(function () use ($subscription): void {
                DB::table('subscriptions')->where('id', $subscription->id)->lockForUpdate()->first();
                if (DB::table('subscription_notices')->where('subscription_id', $subscription->id)->where('period_end', $subscription->ends_at)->exists()) {
                    return;
                }
                $body = $subscription->business_name.': your '.$subscription->plan_name.' subscription ends at '.$subscription->ends_at.' UTC. Contact the platform office to renew. View your account at '.rtrim(config('app.url'), '/').'/billing';
                Mail::raw($body, fn ($message) => $message->to($subscription->email)->subject('Your business subscription is expiring'));
                DB::table('subscription_notices')->insert(['subscription_id' => $subscription->id, 'period_end' => $subscription->ends_at, 'sent_at' => now()]);
            });
        }
        $this->info('Subscription notices checked.');

        return self::SUCCESS;
    }
}
