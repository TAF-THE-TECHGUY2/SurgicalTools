<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Issue a Sanctum bearer token for the SPA / mobile client. */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        $device = $credentials['device_name'] ?? $request->userAgent() ?? 'web';
        $token = $user->createToken($device)->plainTextToken;

        $user->load(['roles', 'hospitals']);

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ]);
    }

    /** Current authenticated user with roles + permissions (drives the UI). */
    public function me(Request $request): UserResource
    {
        $user = $request->user()->load(['roles', 'permissions', 'hospitals']);

        return new UserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /** Revoke every token (e.g. "sign out everywhere"). */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out of all sessions.']);
    }
}
