<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user && (! $user->active || $user->is_platform_admin || ! $user->business_id)) {
            Auth::logout();
            $user = null;
        }

        return response()->json(['user' => $user, 'csrf' => csrf_token()]);
    }

    public function login(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => 'required|email|max:255', 'password' => 'required|string|max:100',
        ]);
        $user = User::where('active', true)->where('is_platform_admin', false)->whereNotNull('business_id')->where('email', $data['email'])->first();
        if (! $user || ! Auth::getProvider()->validateCredentials($user, ['password' => $data['password']])) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return $this->session($request);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return response()->json(['csrf' => csrf_token()]);
    }
}
