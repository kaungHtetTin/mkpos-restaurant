<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessSupportController extends Controller
{
    private function owner(Request $r): void
    {
        abort_unless(DB::table('businesses')->where('id', BusinessData::id())->value('owner_id') === $r->user()->id, 403, 'Only the business owner can submit subscription payments.');
    }

    public function help(Request $r): JsonResponse
    {
        $owner = DB::table('businesses')->where('id', BusinessData::id())->value('owner_id') === $r->user()->id;
        $reports = BusinessData::table('platform_support_reports');
        if (! $owner) {
            $reports->where('submitted_by', $r->user()->id);
        }

        return response()->json(['settings' => OfficeManagementController::settings(), 'content' => DB::table('office_content')->where('published', true)->whereIn('audience', ['all', $owner ? 'owner' : 'staff'])->orderBy('sort')->get(['id', 'kind', 'title', 'body', 'url']), 'reports' => $reports->orderByDesc('id')->limit(100)->get(['id', 'title', 'description', 'status', 'priority', 'resolution', 'created_at', 'updated_at'])]);
    }

    public function requests(Request $r): JsonResponse
    {
        $this->owner($r);
        $rows = BusinessData::table('subscription_requests')->orderByDesc('id')->limit(100)->get(['id', 'plan_snapshot', 'method_snapshot', 'amount', 'currency', 'reference', 'status', 'review_reason', 'created_at', 'reviewed_at']);
        $rows->each(function ($row) {
            $row->plan_snapshot = json_decode($row->plan_snapshot);
            $row->method_snapshot = json_decode($row->method_snapshot);
        });

        return response()->json(['requests' => $rows, 'methods' => DB::table('office_payment_methods')->where('active', true)->get(), 'plans' => DB::table('plans')->where('is_public', true)->where('active', true)->where('is_trial', false)->get(), 'settings' => OfficeManagementController::settings()]);
    }

    private function submission(Request $r, callable $callback): JsonResponse
    {
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 100, 422, 'A request key is required.');

        return DB::transaction(function () use ($r, $callback, $key) {
            DB::table('office_settings')->where('id', 1)->lockForUpdate()->first();
            DB::table('businesses')->where('id', BusinessData::id())->lockForUpdate()->first();
            $payload = $r->except(['receipt', 'screenshot']);
            ksort($payload);
            $files = '';
            foreach (['receipt', 'screenshot'] as $field) {
                if ($r->hasFile($field)) {
                    $files .= hash_file('sha256', $r->file($field)->getRealPath());
                }
            }
            $hash = hash('sha256', $r->path().'|'.json_encode($payload).'|'.$files);
            $previous = BusinessData::table('idempotency_keys')->where('user_id', $r->user()->id)->where('key', $key)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->fingerprint, $hash), 409, 'This request key was already used.');

                return response()->json(json_decode($previous->response, true));
            }
            $result = $callback();
            BusinessData::table('idempotency_keys')->insert(['business_id' => BusinessData::id(), 'user_id' => $r->user()->id, 'key' => $key, 'fingerprint' => $hash, 'response' => json_encode($result), 'created_at' => now()]);

            return response()->json($result);
        }, 3);
    }

    public function submitPayment(Request $r): JsonResponse
    {
        $this->owner($r);
        $data = $r->validate(['plan_id' => 'required|integer', 'method_id' => 'required|integer', 'amount' => 'required|integer|min:0|max:1000000000', 'reference' => 'nullable|string|max:150', 'confirmed' => 'required|accepted', 'receipt' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=6000,max_height=6000']);

        $data['reference'] = trim($data['reference'] ?? '');

        return $this->submission($r, function () use ($data, $r) {
            $plan = DB::table('plans')->where('is_public', true)->where('active', true)->where('is_trial', false)->find($data['plan_id']);
            $method = DB::table('office_payment_methods')->where('active', true)->find($data['method_id']);
            abort_unless($plan && $method && $plan->currency === $method->currency && $plan->price === (int) $data['amount'], 422, 'Choose a current plan and matching payment method, then confirm the exact plan amount.');
            abort_if(BusinessData::table('subscription_requests')->where('status', 'pending')->exists(), 422, 'You already have a payment awaiting review. Wait for a decision before submitting another.');
            abort_if($data['reference'] !== '' && BusinessData::table('subscription_payments')->where('reference', $data['reference'])->exists(), 422, 'This payment reference has already been credited.');
            $id = BusinessData::table('subscription_requests')->insertGetId(['business_id' => BusinessData::id(), 'submitted_by' => $r->user()->id, 'plan_id' => $plan->id, 'method_id' => $method->id, 'plan_snapshot' => json_encode($plan), 'method_snapshot' => json_encode($method), 'amount' => $plan->price, 'currency' => $plan->currency, 'reference' => $data['reference'], 'receipt_path' => $r->hasFile('receipt') ? $r->file('receipt')->store('subscription-receipts/'.BusinessData::id(), 'local') : null, 'created_at' => now()]);

            return ['saved' => true, 'id' => $id];
        });
    }

    public function submitReport(Request $r): JsonResponse
    {
        $data = $r->validate(['title' => 'required|string|max:150', 'description' => 'required|string|max:5000', 'priority' => 'required|in:low,normal,high', 'screenshot' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=6000,max_height=6000']);

        return $this->submission($r, function () use ($data, $r) {
            $id = BusinessData::table('platform_support_reports')->insertGetId(['business_id' => BusinessData::id(), 'submitted_by' => $r->user()->id, 'title' => $data['title'], 'description' => $data['description'], 'priority' => $data['priority'], 'screenshot_path' => $r->hasFile('screenshot') ? $r->file('screenshot')->store('support-reports/'.BusinessData::id(), 'local') : null, 'created_at' => now(), 'updated_at' => now()]);

            return ['saved' => true, 'id' => $id];
        });
    }
}
