<?php

namespace App\Http\Controllers;

use App\Models\BusinessData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessFinanceController extends Controller
{
    private function owner(Request $request): void
    {
        abort_unless(DB::table('businesses')->where('id', BusinessData::id())->value('owner_id') === $request->user()->id, 403, 'Only the business owner can access finances.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->owner($request);
        $data = $request->validate(['q' => 'nullable|string|max:100', 'kind' => 'nullable|in:income,expense', 'category_id' => ['nullable', 'integer', BusinessData::exists('business_finance_categories')], 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'status' => 'nullable|in:active,voided,all', 'page' => 'nullable|integer|min:1']);
        $query = BusinessData::table('business_finance_entries');
        if (! empty($data['q'])) {
            $query->where(function ($query) use ($data) {
                $query->where('description', 'like', '%'.$data['q'].'%')->orWhere('category_name', 'like', '%'.$data['q'].'%');
            });
        }
        foreach (['kind', 'category_id'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['from'])) {
            $query->where('entry_date', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->where('entry_date', '<=', $data['to']);
        }
        $totals = (clone $query)->whereNull('voided_at')->selectRaw("COALESCE(SUM(CASE WHEN kind = 'income' THEN amount ELSE 0 END), 0) AS income, COALESCE(SUM(CASE WHEN kind = 'expense' THEN amount ELSE 0 END), 0) AS expense")->first();
        $status = $data['status'] ?? 'active';
        if ($status !== 'all') {
            $status === 'voided' ? $query->whereNotNull('voided_at') : $query->whereNull('voided_at');
        }
        $categories = BusinessData::table('business_finance_categories')->select('business_finance_categories.*')->selectSub(BusinessData::table('business_finance_entries')->selectRaw('COUNT(*)')->whereColumn('category_id', 'business_finance_categories.id'), 'entries_count')->orderBy('name')->orderBy('id')->get();

        return response()->json(['entries' => $query->orderByDesc('entry_date')->orderByDesc('id')->paginate(20), 'categories' => $categories, 'totals' => ['income' => (int) $totals->income, 'expense' => (int) $totals->expense, 'net' => (int) $totals->income - (int) $totals->expense], 'currency' => BusinessData::table('restaurant_settings')->value('currency')]);
    }

    public function category(Request $request): JsonResponse
    {
        $this->owner($request);
        if (is_string($request->input('name'))) {
            $request->merge(['name' => preg_replace('/\\s+/u', ' ', trim($request->input('name')))]);
        }
        $data = $request->validate(['action' => 'required|in:save,delete', 'id' => 'required_if:action,delete|nullable|integer|min:1', 'version' => 'required_with:id|integer|min:1', 'name' => 'required_if:action,save|string|max:100', 'active' => 'required_if:action,save|boolean']);

        return $this->write($request, function () use ($request, $data): array {
            $id = $data['id'] ?? null;
            $old = $id ? BusinessData::table('business_finance_categories')->find($id) : null;
            if ($id) {
                abort_unless($old, 404);
                abort_unless($old->version === (int) $data['version'], 409, 'This category changed. Reopen it before saving.');
            }
            if ($data['action'] === 'delete') {
                abort_if(BusinessData::table('business_finance_entries')->where('category_id', $id)->exists(), 422, 'This category has financial records. Archive it instead.');
                BusinessData::table('business_finance_categories')->where('id', $id)->delete();
                $this->event($request, null, 'finance_category_deleted', ['before' => (array) $old]);
            } else {
                $key = Str::lower($data['name']);
                abort_if(BusinessData::table('business_finance_categories')->where('name_key', $key)->when($id, fn ($query) => $query->where('id', '<>', $id))->exists(), 422, 'A category with this name already exists.');
                $values = ['name' => $data['name'], 'name_key' => $key, 'active' => $data['active'], 'updated_at' => now()];
                if ($id) {
                    BusinessData::table('business_finance_categories')->where('id', $id)->update([...$values, 'version' => $old->version + 1]);
                } else {
                    $id = DB::table('business_finance_categories')->insertGetId([...$values, 'business_id' => BusinessData::id(), 'created_at' => now()]);
                }
                $this->event($request, null, 'finance_category_saved', ['before' => $old, 'after' => BusinessData::table('business_finance_categories')->find($id)]);
            }

            return ['saved' => true, 'id' => $id];
        });
    }

    public function entry(Request $request): JsonResponse
    {
        $this->owner($request);
        $data = $request->validate(['action' => 'required|in:save,void', 'id' => 'required_if:action,void|nullable|integer|min:1', 'version' => 'required_with:id|integer|min:1', 'kind' => 'required_if:action,save|in:income,expense', 'category_id' => ['required_if:action,save', 'integer', BusinessData::exists('business_finance_categories')], 'amount' => 'required_if:action,save|integer|min:1|max:1000000000', 'entry_date' => 'required_if:action,save|date_format:Y-m-d', 'description' => 'required_if:action,save|string|max:255', 'notes' => 'nullable|string|max:2000', 'reason' => 'required_if:action,void|string|max:255']);

        return $this->write($request, function () use ($request, $data): array {
            $id = $data['id'] ?? null;
            $old = $id ? BusinessData::table('business_finance_entries')->find($id) : null;
            if ($id) {
                abort_unless($old, 404);
                abort_unless($old->version === (int) $data['version'], 409, 'This entry changed. Reopen it before saving.');
                abort_if($old->voided_at, 422, 'Voided entries cannot be changed.');
            }
            if ($data['action'] === 'void') {
                BusinessData::table('business_finance_entries')->where('id', $id)->update(['voided_at' => now(), 'void_reason' => $data['reason'], 'updated_by' => $request->user()->id, 'version' => $old->version + 1, 'updated_at' => now()]);
            } else {
                $category = BusinessData::table('business_finance_categories')->find($data['category_id']);
                abort_unless($category && ($category->active || $old?->category_id === $category->id), 422, 'Choose an active category.');
                $values = ['kind' => $data['kind'], 'category_id' => $category->id, 'category_name' => $old?->category_id === $category->id ? $old->category_name : $category->name, 'amount' => $data['amount'], 'entry_date' => $data['entry_date'], 'description' => $data['description'], 'notes' => $data['notes'] ?? null, 'updated_by' => $request->user()->id, 'updated_at' => now()];
                if ($id) {
                    BusinessData::table('business_finance_entries')->where('id', $id)->update([...$values, 'version' => $old->version + 1]);
                } else {
                    $id = DB::table('business_finance_entries')->insertGetId([...$values, 'business_id' => BusinessData::id(), 'currency' => BusinessData::table('restaurant_settings')->value('currency'), 'created_by' => $request->user()->id, 'created_at' => now()]);
                }
            }
            $this->event($request, null, 'finance_entry_'.$data['action'], ['before' => $old, 'after' => BusinessData::table('business_finance_entries')->find($id)]);

            return ['saved' => true, 'id' => $id];
        });
    }
}
