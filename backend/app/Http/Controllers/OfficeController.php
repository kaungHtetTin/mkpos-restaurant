<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use App\Models\OfficeData;
use App\Models\PlatformLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfficeController extends Controller
{
    public function createBusiness(Request $request): JsonResponse
    {
        $request->merge(['owner_email' => strtolower(trim((string) $request->input('owner_email')))]);
        if ($response = OfficeData::replay($request)) {
            return $response;
        }
        $data = $request->validate(['name' => 'required|string|max:100', 'code' => 'nullable|string|min:3|max:60|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:businesses,code', 'owner_name' => 'required|string|max:100', 'owner_email' => 'required|email|max:255|unique:users,email', 'password' => 'required|string|min:6|max:100|confirmed']);

        return OfficeData::write($request, function () use ($data) {
            $plan = DB::table('plans')->where('is_trial', true)->where('active', true)->first();
            abort_unless($plan, 422, 'An active registration trial is required.');
            $code = $data['code'] ?? substr(Str::slug($data['name']) ?: 'business', 0, 50);
            if (strlen($code) < 3) {
                $code .= '-business';
            }
            if (DB::table('businesses')->where('code', $code)->exists()) {
                $code .= '-'.strtolower(Str::random(6));
            }
            $id = DB::table('businesses')->insertGetId(['name' => $data['name'], 'code' => $code, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $owner = User::create(['name' => $data['owner_name'], 'email' => $data['owner_email'], 'username' => 'owner', 'password' => $data['password'], 'roles' => ['manager'], 'business_id' => $id, 'active' => true]);
            DB::table('businesses')->where('id', $id)->update(['owner_id' => $owner->id]);
            DB::table('restaurant_settings')->insert(['business_id' => $id, 'name' => $data['name']]);
            DB::table('subscriptions')->insert(['business_id' => $id, 'plan_id' => $plan->id, 'status' => 'trial', 'plan_name' => $plan->name, 'price' => 0, 'currency' => $plan->currency, 'billing_period' => $plan->billing_period, 'starts_at' => now(), 'ends_at' => now()->addMonthNoOverflow(), 'created_at' => now(), 'updated_at' => now()]);
            OfficeData::audit($id, 'business_created', ['owner_id' => $owner->id]);

            return ['id' => $id, 'code' => $code];
        });
    }

    public function session(): JsonResponse
    {
        $user = Auth::guard('office')->user();
        if ($user && (! $user->active || ! $user->is_platform_admin || $user->business_id !== null || ($user->office_version !== (int) session('office_version', $user->office_version)))) {
            Auth::guard('office')->logout();
            $user = null;
        }

        return response()->json(['user' => $user, 'csrf' => csrf_token(), 'settings' => OfficeManagementController::settings()]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string|max:100', 'remember' => 'sometimes|boolean']);
        if (! Auth::guard('office')->attempt(['email' => strtolower($data['email']), 'password' => $data['password'], 'active' => true, 'is_platform_admin' => true, 'business_id' => null], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'The office account or password is incorrect.']);
        }
        $request->session()->regenerate();

        $request->session()->put('office_version', Auth::guard('office')->user()->office_version);
        OfficeData::audit(null, 'office_signed_in');

        return $this->session();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('office')->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return response()->json(['csrf' => csrf_token()]);
    }

    public function index(Request $request): JsonResponse
    {
        $size = OfficeData::pageSize($request);
        $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:active,suspended,trial,expired,cancelled', 'page' => 'nullable|integer|min:1']);
        $query = DB::table('businesses')->leftJoin('users', 'users.id', '=', 'businesses.owner_id')
            ->join('subscriptions', 'subscriptions.business_id', '=', 'businesses.id');
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(fn ($q) => $q->where('businesses.name', 'like', $term)->orWhere('businesses.code', 'like', $term)->orWhere('users.email', 'like', $term)->orWhere('users.name', 'like', $term));
        }
        $statusSql = "CASE WHEN subscriptions.status IN ('trial','active') AND subscriptions.ends_at <= ? THEN 'expired' ELSE subscriptions.status END";
        if ($request->filled('status')) {
            if ($request->input('status') === 'suspended') {
                $query->where('businesses.status', 'suspended');
            } else {
                $query->where('businesses.status', 'active')->whereRaw('('.$statusSql.') = ?', [now(), $request->input('status')]);
            }
        }
        $businesses = $query->select('businesses.*', 'users.email as owner_email', 'users.name as owner_name', 'users.active as owner_active', 'subscriptions.plan_name', 'subscriptions.is_complimentary', 'subscriptions.ends_at')
            ->selectRaw($statusSql.' as subscription_status', [now()])->orderByDesc('businesses.id')->paginate($size);

        return response()->json([
            'businesses' => $businesses,
            'summary' => $this->businessSummary(),
            'plans' => DB::table('plans')->orderBy('id')->get(),
        ]);
    }

    private function businessSummary(): array
    {
        $subscriptions = DB::table('subscriptions')->where('ends_at', '>', now());

        return [
            'businesses' => DB::table('businesses')->count(),
            'active' => (clone $subscriptions)->where('status', 'active')->where('is_complimentary', false)->count(),
            'complimentary' => (clone $subscriptions)->where('status', 'active')->where('is_complimentary', true)->count(),
            'trials' => (clone $subscriptions)->where('status', 'trial')->count(),
            'expiring' => (clone $subscriptions)->whereIn('status', ['trial', 'active'])->where('ends_at', '<=', now()->addDays(7))->count(),
            'suspended' => DB::table('businesses')->where('status', 'suspended')->count(),
        ];
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $request->validate(['payments_page' => 'nullable|integer|min:1']);
        $business = DB::table('businesses')->find($id);
        abort_unless($business, 404);
        $owner = $business->owner_id ? User::find($business->owner_id) : null;

        return response()->json([
            'business' => $business, 'owner' => $owner, 'subscription' => BusinessData::subscription($id), 'summary' => $this->businessSummary(),
            'books' => DB::table('office_bank_books')->where('active', true)->get(['id', 'name', 'currency', 'is_default']),
            'payments' => DB::table('subscription_payments')->where('business_id', $id)->orderByDesc('id')->paginate(25, ['*'], 'payments_page'),
            'events' => DB::table('platform_events')->leftJoin('users', 'users.id', '=', 'platform_events.actor_id')->where('platform_events.business_id', $id)->select('platform_events.*', 'users.name as actor_name')->orderByDesc('platform_events.id')->limit(100)->get(),
        ]);
    }

    private function audit(?int $businessId, string $action, array $details): void
    {
        DB::table('platform_events')->insert(['business_id' => $businessId, 'actor_id' => Auth::guard('office')->id(), 'action' => $action, 'details' => json_encode($details), 'created_at' => now()]);
    }

    public function updateBusiness(Request $request, int $id): JsonResponse
    {
        $request->merge(['owner_email' => strtolower(trim((string) $request->input('owner_email')))]);
        $business = DB::table('businesses')->find($id);
        abort_unless($business, 404);
        $data = $request->validate([
            'name' => 'required|string|max:100', 'status' => 'required|in:active,suspended',
            'owner_name' => 'required|string|max:100', 'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($business->owner_id)],
            'contact' => 'sometimes|nullable|string|max:255', 'address' => 'sometimes|nullable|string|max:500', 'city' => 'sometimes|nullable|string|max:100', 'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180', 'owner_active' => 'required|boolean', 'owner_password' => 'nullable|string|min:6|max:100',
        ]);
        DB::transaction(function () use ($data, $id): void {
            DB::table('office_settings')->where('id', 1)->lockForUpdate()->first();
            $locked = DB::table('businesses')->where('id', $id)->lockForUpdate()->first();
            abort_unless($locked->owner_id, 422, 'Create the initial restaurant owner using restaurant:manager first.');
            DB::table('businesses')->where('id', $id)->update(['name' => $data['name'], 'status' => $data['status'], 'updated_at' => now()]);
            $location = array_intersect_key($data, array_flip(['contact', 'address', 'city', 'latitude', 'longitude']));
            foreach (['contact', 'address', 'city'] as $field) {
                if (array_key_exists($field, $location)) {
                    $location[$field] = $location[$field] ?? '';
                }
            }
            if ($location) {
                DB::table('businesses')->where('id', $id)->update($location);
            }
            $owner = User::findOrFail($locked->owner_id);
            $owner->name = $data['owner_name'];
            $owner->email = strtolower($data['owner_email']);
            $owner->active = $data['owner_active'];
            if (! empty($data['owner_password'])) {
                $owner->password = $data['owner_password'];
            }
            $owner->save();
            if (! $data['owner_active'] || ! empty($data['owner_password'])) {
                DB::table('sessions')->where('user_id', $owner->id)->delete();
            }
            $safe = $data;
            unset($safe['owner_password']);
            $safe['password_changed'] = ! empty($data['owner_password']);
            $this->audit($id, 'business_updated', $safe);
        });

        return $this->show($request, $id);
    }

    public function savePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:plans,id', 'name' => 'required|string|max:100', 'price' => 'required|integer|min:0|max:1000000000', 'currency' => 'required|in:MMK,USD,THB,SGD,EUR', 'billing_period' => 'required|in:month,year', 'active' => 'required|boolean',
            'description' => 'nullable|string|max:2000', 'features' => 'sometimes|array|max:30', 'features.*' => 'string|max:150', 'is_public' => 'sometimes|boolean', 'sort' => 'sometimes|integer|min:0|max:999',
        ]);

        return OfficeData::write($request, function () use ($data) {
            $id = $data['id'] ?? null;
            if ($id) {
                $plan = DB::table('plans')->where('id', $id)->lockForUpdate()->first();
                abort_if($plan->is_trial, 422, 'The registration trial is fixed at one calendar month. Create a paid plan separately.');
            }
            unset($data['id']);
            if (isset($data['features'])) {
                $data['features'] = json_encode($data['features']);
            }
            if ($id) {
                DB::table('plans')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            } else {
                $id = DB::table('plans')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit(null, 'plan_saved', ['id' => $id, ...$data]);

            return ['plans' => DB::table('plans')->orderBy('sort')->orderBy('id')->get()];
        });
    }

    public function subscription(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:renew,extend,cancel,assign,foc', 'plan_id' => 'required_if:action,renew,assign,foc|nullable|integer|exists:plans,id',
            'days' => 'required_if:action,extend|nullable|integer|min:1|max:366',
            'amount' => 'prohibited_if:action,foc|required_if:action,renew|nullable|integer|min:0|max:1000000000',
            'confirmed' => 'exclude_unless:action,foc|required|accepted',
            'book_id' => 'prohibited_if:action,foc|nullable|integer', 'reference' => 'prohibited_if:action,foc|required_if:action,renew|nullable|string|max:150', 'reason' => 'required|string|max:500',
        ]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 100, 422, 'A request key is required.');
        DB::transaction(function () use ($id, $data, $key): void {
            DB::table('office_settings')->where('id', 1)->lockForUpdate()->first();
            $business = DB::table('businesses')->where('id', $id)->lockForUpdate()->first();
            abort_unless($business, 404);
            $subscription = DB::table('subscriptions')->where('business_id', $id)->lockForUpdate()->first();
            abort_unless($subscription, 404);
            $previous = DB::table('platform_events')->where('details->request_key', $key)->first();
            if ($previous) {
                $details = json_decode($previous->details, true);
                abort_unless($previous->business_id === $id && $details['request'] === $data, 409, 'This request key was already used.');

                return;
            }
            $changes = ['updated_at' => now()];
            if (in_array($data['action'], ['renew', 'assign', 'foc'])) {
                $plan = DB::table('plans')->where('id', $data['plan_id'])->where('active', true)->where('is_trial', false)->first();
                abort_unless($plan, 422, 'Select an active paid plan.');
                $changes += ['plan_id' => $plan->id, 'plan_name' => $plan->name, 'price' => $data['action'] === 'foc' || ($data['action'] === 'assign' && $subscription->is_complimentary) ? 0 : $plan->price, 'currency' => $plan->currency, 'billing_period' => $plan->billing_period];
            }
            if ($data['action'] === 'renew') {
                abort_unless((int) $data['amount'] === (int) $plan->price, 422, 'Record the full plan price for this renewal.');
                $start = $subscription->status === 'active' && now()->lt($subscription->ends_at) ? CarbonImmutable::parse($subscription->ends_at) : CarbonImmutable::now();
                $end = $plan->billing_period === 'year' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
                $changes += ['status' => 'active', 'is_complimentary' => false, 'starts_at' => now(), 'ends_at' => $end];
                $paymentId = DB::table('subscription_payments')->insertGetId(['business_id' => $id, 'subscription_id' => $subscription->id, 'recorded_by' => Auth::guard('office')->id(), 'request_key' => $key, 'amount' => $data['amount'], 'currency' => $plan->currency, 'reference' => $data['reference'], 'plan_name' => $plan->name, 'period_start' => $start, 'period_end' => $end, 'created_at' => now()]);
                PlatformLedger::receipt($paymentId, $data['book_id'] ?? null);
            } elseif ($data['action'] === 'foc') {
                $start = in_array($subscription->status, ['active', 'trial']) && now()->lt($subscription->ends_at) ? CarbonImmutable::parse($subscription->ends_at) : CarbonImmutable::now();
                $end = $plan->billing_period === 'year' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
                $changes += ['status' => 'active', 'is_complimentary' => true, 'starts_at' => now(), 'ends_at' => $end];
            } elseif ($data['action'] === 'extend') {
                $end = now()->gt($subscription->ends_at) ? now() : CarbonImmutable::parse($subscription->ends_at);
                $changes += ['status' => $subscription->status === 'trial' ? 'trial' : 'active', 'ends_at' => $end->addDays($data['days'])];
            } elseif ($data['action'] === 'cancel') {
                $changes += ['status' => 'cancelled', 'ends_at' => now()];
            }
            DB::table('subscriptions')->where('id', $subscription->id)->update($changes);
            $this->audit($id, 'subscription_'.$data['action'], ['request_key' => $key, 'request' => $data, 'before' => (array) $subscription, 'after' => $changes]);
        }, 3);

        return $this->show($request, $id);
    }

    public function events(Request $request): JsonResponse
    {
        $size = OfficeData::pageSize($request);
        $request->validate(['q' => 'nullable|string|max:100', 'actor_id' => 'nullable|integer', 'action' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : '')]);

        $query = DB::table('platform_events')->leftJoin('users', 'users.id', '=', 'platform_events.actor_id')->leftJoin('businesses', 'businesses.id', '=', 'platform_events.business_id');
        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('action', 'like', '%'.$request->q.'%')->orWhere('users.name', 'like', '%'.$request->q.'%')->orWhere('businesses.name', 'like', '%'.$request->q.'%'));
        }
        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->actor_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        $timezone = DB::table('office_settings')->value('timezone');
        if ($request->filled('from')) {
            $query->where('platform_events.created_at', '>=', CarbonImmutable::parse($request->from, $timezone)->utc());
        }
        if ($request->filled('to')) {
            $query->where('platform_events.created_at', '<', CarbonImmutable::parse($request->to, $timezone)->addDay()->utc());
        }
        $events = $query->select('platform_events.*', 'users.name as actor_name', 'businesses.name as business_name')->orderByDesc('platform_events.id')->paginate($size);

        return response()->json([...$events->toArray(), 'actors' => DB::table('users')->where('is_platform_admin', true)->whereNull('business_id')->get(['id', 'name'])]);
    }
}
