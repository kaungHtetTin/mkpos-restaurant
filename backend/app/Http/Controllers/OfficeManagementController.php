<?php

namespace App\Http\Controllers;

use App\Models\OfficeData;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OfficeManagementController extends Controller
{
    public function account(Request $r): JsonResponse
    {
        $admin = Auth::guard('office')->user();
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        if ($response = OfficeData::replay($r)) {
            return $response;
        }
        $data = $r->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($admin->id)], 'current_password' => 'required|current_password:office', 'password' => 'nullable|string|min:6|max:100|confirmed']);

        return OfficeData::write($r, function () use ($data, $admin, $r) {
            $admin->name = $data['name'];
            $admin->email = strtolower($data['email']);
            if (! empty($data['password'])) {
                $admin->password = $data['password'];
            }
            $admin->office_version++;
            $admin->save();
            $r->session()->put('office_version', $admin->office_version);
            OfficeData::audit(null, 'office_account_updated', ['password_changed' => ! empty($data['password'])]);

            return ['saved' => true, 'user' => $admin];
        });
    }

    public static function settings(): array
    {
        $settings = (array) DB::table('office_settings')->find(1);
        $settings['logo_url'] = $settings['logo_path'] ? '/api/v1/platform-logo?v='.$settings['version'] : null;
        unset($settings['logo_path']);

        return $settings;
    }

    public function index(Request $r, string $resource): JsonResponse
    {
        $size = OfficeData::pageSize($r);
        $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|string|max:30']);
        if ($resource === 'settings') {
            return response()->json(self::settings());
        }
        if ($resource === 'admins') {
            OfficeData::super();
            $query = DB::table('users')->where('is_platform_admin', true)->whereNull('business_id')->select('id', 'name', 'email', 'office_role', 'office_version', 'active');
        } elseif ($resource === 'methods') {
            $query = DB::table('office_payment_methods');
        } elseif ($resource === 'content') {
            $query = DB::table('office_content');
        } elseif ($resource === 'reports') {
            $query = DB::table('platform_support_reports')->join('businesses', 'businesses.id', '=', 'platform_support_reports.business_id')->select('platform_support_reports.*', 'businesses.name as business_name');
        } elseif ($resource === 'distribution') {
            $query = DB::table('businesses')->select('id', 'name', 'city', 'address', 'contact', 'latitude', 'longitude', 'status');
        } else {
            abort(404);
        }
        if ($r->filled('q')) {
            $field = match ($resource) {
                'content', 'reports' => 'title', default => 'name'
            };
            $query->where($field, 'like', '%'.$r->q.'%');
        }
        if ($r->filled('status')) {
            if (in_array($resource, ['admins', 'methods'])) {
                $query->where('active', $r->status === 'active');
            } elseif ($resource === 'content') {
                $query->where('kind', $r->status);
            } else {
                $query->where($resource === 'reports' ? 'platform_support_reports.status' : 'status', $r->status);
            }
        }
        $page = $query->orderByDesc($resource === 'reports' ? 'platform_support_reports.id' : 'id')->paginate($size);
        if ($resource === 'reports') {
            $page->getCollection()->each(function ($row) {
                $row->has_screenshot = (bool) $row->screenshot_path;
                unset($row->screenshot_path);
            });
        }

        return response()->json($page);
    }

    public function save(Request $r, string $resource): JsonResponse
    {
        if (in_array($resource, ['admins', 'settings'])) {
            OfficeData::super();
        }
        if ($resource === 'admins') {
            $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        }
        if ($response = OfficeData::replay($r)) {
            return $response;
        }
        $common = ['id' => 'nullable|integer', 'version' => 'required|integer|min:1'];
        $rules = match ($resource) {
            'admins' => ['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($r->input('id'))], 'office_role' => 'required|in:admin,super_admin', 'active' => 'required|boolean', 'password' => 'nullable|string|min:6|max:100|confirmed'],
            'settings' => ['name' => 'required|string|max:100', 'timezone' => 'required|timezone', 'theme_color' => 'required|regex:/^#[a-fA-F0-9]{6}$/', 'support_email' => 'nullable|email|max:255', 'support_phone' => 'nullable|string|max:100', 'support_url' => 'nullable|url:https|max:500', 'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096', 'remove_logo' => 'nullable|boolean'],
            'methods' => ['name' => 'required|string|max:100', 'currency' => 'required|regex:/^[A-Z]{3}$/', 'instructions' => 'required|string|max:3000', 'active' => 'required|boolean'],
            'content' => ['kind' => 'required|in:tutorial,announcement', 'title' => 'required|string|max:150', 'body' => 'required|string|max:10000', 'url' => 'nullable|url:https|max:500', 'audience' => 'required|in:all,owner,staff', 'published' => 'required|boolean', 'sort' => 'required|integer|between:0,999'],
            'reports' => ['status' => 'required|in:open,in_review,resolved,closed', 'priority' => 'required|in:low,normal,high', 'resolution' => 'required_if:status,resolved|nullable|string|max:5000', 'internal_note' => 'nullable|string|max:5000'],
            default => abort(404),
        };
        $data = $r->validate([...$common, ...$rules]);

        return OfficeData::write($r, function () use ($resource, $data, $r) {
            $id = $data['id'] ?? null;
            if ($resource === 'admins') {
                $admin = $id ? User::where('is_platform_admin', true)->whereNull('business_id')->find($id) : new User;
                abort_unless($admin, 404);
                if ($id) {
                    abort_unless($admin->office_version === (int) $data['version'], 409, 'This account changed. Reopen the editor.');
                } else {
                    abort_unless(! empty($data['password']), 422, 'A password is required for a new administrator.');
                }
                abort_if($id === Auth::guard('office')->id() && (! $data['active'] || $data['office_role'] !== 'super_admin'), 422, 'You cannot deactivate or demote your own super administrator account.');
                if ($id && $admin->active && $admin->office_role === 'super_admin' && (! $data['active'] || $data['office_role'] !== 'super_admin')) {
                    abort_if(User::where('is_platform_admin', true)->whereNull('business_id')->where('active', true)->where('office_role', 'super_admin')->where('id', '!=', $id)->count() === 0, 422, 'Keep at least one active super administrator.');
                }
                $admin->name = $data['name'];
                $admin->email = strtolower($data['email']);
                $admin->active = $data['active'];
                $admin->office_role = $data['office_role'];
                $admin->is_platform_admin = true;
                $admin->business_id = null;
                $admin->roles = [];
                $admin->username = $admin->username ?? 'office';
                if (! empty($data['password'])) {
                    $admin->password = $data['password'];
                }
                $admin->office_version = ($admin->office_version ?? 0) + 1;
                $admin->save();
                $id = $admin->id;
                if ($id === Auth::guard('office')->id()) {
                    $r->session()->put('office_version', $admin->office_version);
                }
            } else {
                $table = match ($resource) {
                    'settings' => 'office_settings', 'methods' => 'office_payment_methods', 'content' => 'office_content', 'reports' => 'platform_support_reports'
                };
                if ($resource === 'settings') {
                    $id = 1;
                }
                $old = $id ? DB::table($table)->find($id) : null;
                if ($id) {
                    abort_unless($old, 404);
                    abort_unless($old->version === (int) $data['version'], 409, 'This record changed. Refresh before saving.');
                }
                $values = $data;
                unset($values['id'], $values['version'], $values['image'], $values['remove_logo']);
                if ($resource === 'settings') {
                    foreach (['support_email', 'support_phone', 'support_url'] as $field) {
                        $values[$field] = $values[$field] ?? '';
                    }
                    if ($r->hasFile('image')) {
                        $values['logo_path'] = $r->file('image')->store('office-brand', 'local');
                    } elseif ($r->boolean('remove_logo')) {
                        $values['logo_path'] = null;
                    }
                }
                if (in_array($resource, ['content', 'reports'])) {
                    $values['updated_at'] = now();
                }
                if ($id) {
                    DB::table($table)->where('id', $id)->update([...$values, 'version' => $old->version + 1]);
                } else {
                    if ($resource === 'content') {
                        $values['created_at'] = now();
                    } $id = DB::table($table)->insertGetId($values);
                }
            }
            OfficeData::audit($resource === 'reports' ? DB::table('platform_support_reports')->where('id', $id)->value('business_id') : null, 'office_'.$resource.'_saved', ['id' => $id, 'password_changed' => ! empty($data['password'])]);

            return ['saved' => true, 'id' => $id];
        });
    }

    public function logo()
    {
        $path = DB::table('office_settings')->where('id', 1)->value('logo_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function screenshot(int $id)
    {
        $path = DB::table('platform_support_reports')->where('id', $id)->value('screenshot_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
