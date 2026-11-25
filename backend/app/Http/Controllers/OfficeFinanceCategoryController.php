<?php

namespace App\Http\Controllers;

use App\Models\OfficeData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfficeFinanceCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $size = OfficeData::pageSize($request);
        $data = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:active,archived']);
        $query = DB::table('office_finance_categories')->select('office_finance_categories.*')->selectSub(DB::table('office_ledger')->selectRaw('COUNT(*)')->whereColumn('category_id', 'office_finance_categories.id'), 'entries_count');
        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }
        if (! empty($data['status'])) {
            $query->where('active', $data['status'] === 'active');
        }

        return response()->json($query->orderBy('name')->orderBy('id')->paginate($size));
    }

    public function save(Request $request): JsonResponse
    {
        if ($request->input('action') === 'save' && is_string($request->input('name'))) {
            $request->merge(['name' => preg_replace('/\\s+/u', ' ', trim($request->input('name')))]);
        }
        $data = $request->validate(['action' => 'required|in:save,delete', 'id' => 'required_if:action,delete|nullable|integer|min:1', 'version' => 'required_with:id|integer|min:1', 'name' => 'required_if:action,save|string|max:100', 'active' => 'required_if:action,save|boolean']);

        return OfficeData::write($request, function () use ($data) {
            $id = $data['id'] ?? null;
            $old = $id ? DB::table('office_finance_categories')->find($id) : null;
            if ($id) {
                abort_unless($old, 404);
                abort_unless($old->version === (int) $data['version'], 409, 'This category changed. Reopen it before saving.');
            }
            if ($data['action'] === 'delete') {
                abort_if(DB::table('office_ledger')->where('category_id', $id)->exists(), 422, 'This category is used by financial entries. Archive it to preserve their history.');
                DB::table('office_finance_categories')->where('id', $id)->delete();
                OfficeData::audit(null, 'financial_category_deleted', ['id' => $id, 'name' => $old->name]);
            } else {
                $key = Str::lower($data['name']);
                $duplicate = DB::table('office_finance_categories')->where('name_key', $key);
                if ($id) {
                    $duplicate->where('id', '<>', $id);
                }
                abort_if($duplicate->exists(), 422, 'A financial category with this name already exists.');
                $values = ['name' => $data['name'], 'name_key' => $key, 'active' => $data['active'], 'updated_at' => now()];
                if ($id) {
                    DB::table('office_finance_categories')->where('id', $id)->update([...$values, 'version' => $old->version + 1]);
                } else {
                    $id = DB::table('office_finance_categories')->insertGetId([...$values, 'version' => 1, 'created_at' => now()]);
                }
                OfficeData::audit(null, 'financial_category_saved', ['id' => $id, 'name' => $data['name'], 'active' => $data['active']]);
            }

            return ['saved' => true, 'id' => $id];
        });
    }
}
