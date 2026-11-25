<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlatformLedger
{
    public static function balance(int $book): int
    {
        $entries = DB::table('office_ledger')->whereNull('voided_at')->where(fn ($q) => $q->where('book_id', $book)->orWhere('to_book_id', $book))->get();

        return (int) $entries->sum(fn ($entry) => $entry->kind === 'transfer' ? ($entry->book_id === $book ? -$entry->amount : $entry->amount) : (in_array($entry->kind, ['expense', 'allocation']) ? -$entry->amount : $entry->amount));
    }

    public static function book(string $currency, ?int $id = null): object
    {
        $query = DB::table('office_bank_books')->where('currency', $currency)->where('active', true);
        if ($id) {
            $book = $query->find($id);
            abort_unless($book, 422, 'Select an active bank book in the same currency.');

            return $book;
        }
        $book = (clone $query)->where('is_default', true)->first();
        if (! $book) {
            $book = (clone $query)->first();
            if ($book) {
                DB::table('office_bank_books')->where('id', $book->id)->update(['is_default' => true]);
            } else {
                $id = DB::table('office_bank_books')->insertGetId(['name' => 'Subscription receipts', 'currency' => $currency, 'is_default' => true]);
                $book = DB::table('office_bank_books')->find($id);
            }
        }

        return $book;
    }

    public static function receipt(int $paymentId, ?int $bookId = null): void
    {
        if (DB::table('office_ledger')->where('subscription_payment_id', $paymentId)->exists()) {
            return;
        }
        $payment = DB::table('subscription_payments')->find($paymentId);
        $book = self::book($payment->currency, $bookId);
        $timezone = DB::table('office_settings')->where('id', 1)->value('timezone');
        DB::table('office_ledger')->insert(['book_id' => $book->id, 'business_id' => $payment->business_id, 'subscription_payment_id' => $paymentId, 'created_by' => $payment->recorded_by, 'kind' => 'subscription', 'amount' => $payment->amount, 'currency' => $payment->currency, 'description' => $payment->plan_name, 'reference' => $payment->reference, 'entry_date' => CarbonImmutable::parse($payment->created_at)->setTimezone($timezone)->toDateString(), 'created_at' => $payment->created_at]);
    }

    public static function renew(int $businessId, object $plan, string $reference, string $key, ?int $bookId = null): int
    {
        abort_if($reference !== '' && DB::table('subscription_payments')->where('business_id', $businessId)->where('reference', $reference)->exists(), 422, 'This payment reference has already been credited.');
        $subscription = DB::table('subscriptions')->where('business_id', $businessId)->lockForUpdate()->first();
        abort_unless($subscription, 404);
        $start = $subscription->status === 'active' && now()->lt($subscription->ends_at) ? CarbonImmutable::parse($subscription->ends_at) : CarbonImmutable::now();
        $end = $plan->billing_period === 'year' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
        $payment = DB::table('subscription_payments')->insertGetId(['business_id' => $businessId, 'subscription_id' => $subscription->id, 'recorded_by' => Auth::guard('office')->id(), 'request_key' => $key, 'amount' => $plan->price, 'currency' => $plan->currency, 'reference' => $reference, 'plan_name' => $plan->name, 'period_start' => $start, 'period_end' => $end, 'created_at' => now()]);
        DB::table('subscriptions')->where('id', $subscription->id)->update(['plan_id' => $plan->id, 'plan_name' => $plan->name, 'price' => $plan->price, 'currency' => $plan->currency, 'billing_period' => $plan->billing_period, 'status' => 'active', 'is_complimentary' => false, 'starts_at' => now(), 'ends_at' => $end, 'updated_at' => now()]);
        self::receipt($payment, $bookId);

        return $payment;
    }

    public static function profit(string $currency): array
    {
        $entries = DB::table('office_ledger')->where('currency', $currency)->whereNull('voided_at')->get();
        $income = (int) $entries->whereIn('kind', ['subscription', 'income'])->sum('amount');
        $expenses = (int) $entries->where('kind', 'expense')->sum('amount');
        $allocated = (int) $entries->where('kind', 'allocation')->sum('amount');

        return ['income' => $income, 'expenses' => $expenses, 'profit' => $income - $expenses, 'allocated' => $allocated, 'available_profit' => max(0, $income - $expenses - $allocated)];
    }
}
