<?php

namespace App\Http\Controllers;

use App\Models\OfficeData;
use App\Models\PlatformLedger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OfficeOperationsController extends Controller
{
    public function overview(Request $r): JsonResponse
    {
        $r->validate(['month' => 'nullable|date_format:Y-m']);
        $settings = DB::table('office_settings')->find(1);
        $start = CarbonImmutable::parse($r->input('month', now($settings->timezone)->format('Y-m')).'-01', $settings->timezone)->startOfDay();
        $payments = DB::table('subscription_payments')->where('created_at', '>=', $start->utc())->where('created_at', '<', $start->addMonth()->utc())->get();
        $income = $payments->groupBy('currency')->map(fn ($rows, $currency) => ['currency' => $currency, 'amount' => (int) $rows->sum('amount'), 'count' => $rows->count(), 'days' => $rows->groupBy(fn ($p) => CarbonImmutable::parse($p->created_at)->setTimezone($settings->timezone)->toDateString())->map(fn ($day) => (int) $day->sum('amount'))])->values();
        $subscriptions = DB::table('subscriptions')->join('businesses', 'businesses.id', '=', 'subscriptions.business_id')->leftJoin('users', 'users.id', '=', 'businesses.owner_id');
        $active = (clone $subscriptions)->where('businesses.status', 'active')->where('users.active', true)->whereIn('subscriptions.status', ['active', 'trial'])->where('starts_at', '<=', now())->where('ends_at', '>', now());

        return response()->json(['month' => $start->format('Y-m'), 'timezone' => $settings->timezone, 'income' => $income, 'summary' => ['businesses' => DB::table('businesses')->count(), 'paid' => (clone $active)->where('subscriptions.status', 'active')->where('subscriptions.is_complimentary', false)->count(), 'complimentary' => (clone $active)->where('subscriptions.status', 'active')->where('subscriptions.is_complimentary', true)->count(), 'trials' => (clone $active)->where('subscriptions.status', 'trial')->count(), 'suspended' => DB::table('businesses')->where('status', 'suspended')->count(), 'expiring' => (clone $active)->where('ends_at', '<=', now()->addDays(7))->count(), 'pending_payments' => DB::table('subscription_requests')->where('status', 'pending')->count(), 'open_reports' => DB::table('platform_support_reports')->whereIn('status', ['open', 'in_review'])->count(), 'setup_pending' => DB::table('businesses')->whereNull('setup_completed_at')->count(), 'staff' => DB::table('users')->whereNotNull('business_id')->where('active', true)->count()],
            'expiring' => (clone $active)->where('ends_at', '<=', now()->addDays(7))->orderBy('ends_at')->limit(10)->get(['businesses.id', 'businesses.name', 'subscriptions.ends_at', 'subscriptions.plan_name']), 'cities' => DB::table('businesses')->selectRaw('city, COUNT(*) as count')->groupBy('city')->orderByDesc('count')->limit(20)->get()]);
    }

    public function calendar(Request $r): JsonResponse
    {
        $size = OfficeData::pageSize($r);
        $r->validate(['month' => 'nullable|date_format:Y-m', 'day' => 'nullable|date_format:Y-m-d', 'q' => 'nullable|string|max:100']);
        $timezone = DB::table('office_settings')->value('timezone');
        $start = CarbonImmutable::parse($r->input('month', now($timezone)->format('Y-m')).'-01', $timezone)->startOfDay();
        $query = DB::table('subscriptions')->join('businesses', 'businesses.id', '=', 'subscriptions.business_id')->where('ends_at', '>=', $start->utc())->where('ends_at', '<', $start->addMonth()->utc());
        if ($r->filled('q')) {
            $query->where(fn ($q) => $q->where('businesses.name', 'like', '%'.$r->q.'%')->orWhere('businesses.code', 'like', '%'.$r->q.'%'));
        }
        $days = (clone $query)->get(['ends_at'])->groupBy(fn ($row) => CarbonImmutable::parse($row->ends_at)->setTimezone($timezone)->toDateString())->map->count();
        if ($r->filled('day')) {
            $day = CarbonImmutable::parse($r->day, $timezone);
            $query->where('ends_at', '>=', $day->utc())->where('ends_at', '<', $day->addDay()->utc());
        }

        return response()->json(['month' => $start->format('Y-m'), 'days' => $days, 'timezone' => $timezone, 'businesses' => $query->orderBy('ends_at')->paginate($size, ['businesses.id', 'businesses.name', 'businesses.status as business_status', 'subscriptions.status', 'subscriptions.plan_name', 'subscriptions.ends_at'])]);
    }

    public function payments(Request $r): JsonResponse
    {
        $size = OfficeData::pageSize($r);
        $r->validate(['q' => 'nullable|string|max:100', 'currency' => 'nullable|regex:/^[A-Z]{3}$/', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($r->filled('from') ? '|after_or_equal:from' : ''), 'business_id' => 'nullable|integer']);
        $query = DB::table('subscription_payments')->join('businesses', 'businesses.id', '=', 'subscription_payments.business_id')->join('users', 'users.id', '=', 'subscription_payments.recorded_by')->leftJoin('office_ledger', 'office_ledger.subscription_payment_id', '=', 'subscription_payments.id')->leftJoin('office_bank_books', 'office_bank_books.id', '=', 'office_ledger.book_id');
        if ($r->filled('q')) {
            $query->where(fn ($q) => $q->where('businesses.name', 'like', '%'.$r->q.'%')->orWhere('subscription_payments.reference', 'like', '%'.$r->q.'%'));
        }
        if ($r->filled('currency')) {
            $query->where('subscription_payments.currency', $r->currency);
        }
        if ($r->filled('business_id')) {
            $query->where('subscription_payments.business_id', $r->business_id);
        }
        $timezone = DB::table('office_settings')->value('timezone');
        if ($r->filled('from')) {
            $query->where('subscription_payments.created_at', '>=', CarbonImmutable::parse($r->from, $timezone)->utc());
        }
        if ($r->filled('to')) {
            $query->where('subscription_payments.created_at', '<', CarbonImmutable::parse($r->to, $timezone)->addDay()->utc());
        }
        $totals = (clone $query)->selectRaw('subscription_payments.currency, SUM(subscription_payments.amount) as amount, COUNT(*) as count')->groupBy('subscription_payments.currency')->get();

        return response()->json(['totals' => $totals, 'payments' => $query->orderByDesc('subscription_payments.id')->paginate($size, ['subscription_payments.*', 'businesses.name as business_name', 'users.name as actor_name', 'office_bank_books.name as book_name'])]);
    }

    public function requests(Request $r): JsonResponse
    {
        $size = OfficeData::pageSize($r);
        $r->validate(['status' => 'nullable|in:pending,approved,rejected', 'q' => 'nullable|string|max:100']);
        $query = DB::table('subscription_requests')->join('businesses', 'businesses.id', '=', 'subscription_requests.business_id')->leftJoin('users', 'users.id', '=', 'subscription_requests.reviewed_by');
        if ($r->filled('status')) {
            $query->where('subscription_requests.status', $r->status);
        }
        if ($r->filled('q')) {
            $query->where(fn ($q) => $q->where('businesses.name', 'like', '%'.$r->q.'%')->orWhere('reference', 'like', '%'.$r->q.'%'));
        }
        $page = $query->select('subscription_requests.*', 'businesses.name as business_name', 'users.name as reviewer_name')->orderByRaw("CASE WHEN subscription_requests.status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('subscription_requests.id')->paginate($size);
        $page->getCollection()->each(function ($row) {
            $row->plan_snapshot = json_decode($row->plan_snapshot);
            $row->method_snapshot = json_decode($row->method_snapshot);
            $row->has_receipt = (bool) $row->receipt_path;
            unset($row->receipt_path);
        });

        return response()->json(['requests' => $page, 'books' => DB::table('office_bank_books')->where('active', true)->get()]);
    }

    public function review(Request $r, int $id): JsonResponse
    {
        $data = $r->validate(['version' => 'required|integer|min:1', 'action' => 'required|in:approve,reject', 'reason' => 'required|string|max:500', 'confirmed' => 'exclude_unless:action,approve|required|accepted', 'book_id' => 'nullable|integer']);

        return OfficeData::write($r, function () use ($data, $id) {
            $request = DB::table('subscription_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($request, 404);
            abort_unless($request->status === 'pending' && $request->version === (int) $data['version'], 409, 'This request has already changed. Refresh before reviewing.');
            $payment = null;
            if ($data['action'] === 'approve') {
                $plan = DB::table('plans')->where('active', true)->where('is_trial', false)->find($request->plan_id);
                $snapshot = json_decode($request->plan_snapshot);
                abort_unless($plan && $plan->price === $request->amount && $plan->currency === $request->currency && $plan->billing_period === $snapshot->billing_period && $plan->name === $snapshot->name, 409, 'The plan changed. Reject this request and ask the owner to submit a current payment request.');
                $payment = PlatformLedger::renew($request->business_id, $plan, $request->reference, 'approval-'.$id, $data['book_id'] ?? null);
            }
            DB::table('subscription_requests')->where('id', $id)->update(['status' => $data['action'] === 'approve' ? 'approved' : 'rejected', 'payment_id' => $payment, 'reviewed_by' => Auth::guard('office')->id(), 'review_reason' => $data['reason'], 'reviewed_at' => now(), 'version' => $request->version + 1]);
            OfficeData::audit($request->business_id, 'subscription_request_'.$data['action'], ['id' => $id, 'payment_id' => $payment, 'reason' => $data['reason']]);

            return ['saved' => true, 'payment_id' => $payment];
        });
    }

    public function attachment(int $id)
    {
        $path = DB::table('subscription_requests')->where('id', $id)->value('receipt_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function health(): JsonResponse
    {
        OfficeData::super();
        DB::select('SELECT 1');

        return response()->json(['checked_at' => now()->toIso8601String(), 'checks' => ['database' => 'ok', 'private_storage' => is_writable(storage_path('app/private')) ? 'ok' : 'needs attention', 'public_build' => file_exists(public_path('app/index.html')) ? 'ok' : 'missing', 'debug' => config('app.debug') ? 'enabled' : 'disabled'], 'versions' => ['php' => PHP_VERSION, 'laravel' => app()->version()], 'pending_jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count()]);
    }
}
