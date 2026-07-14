<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends BaseApiController
{
    /**
     * Login user and create token.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Email atau password salah', 401);
        }

        if (!$user->isActive()) {
            return $this->error('Akun Anda tidak aktif. Silakan hubungi administrator.', 403);
        }

        // Update last login timestamp
        $user->update(['last_login_at' => now()]);

        // Revoke all existing tokens (optional: for single session)
        // $user->tokens()->delete();

        // Create new token
        $token = $user->createToken('auth-token')->plainTextToken;

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 720));

        return $this->success([
            'user' => $this->formatUser($user),
            'token' => $token,
            'token_type' => 'Bearer',
            'token_expires_at' => $expiresAt->toIso8601String(),
        ], 'Login berhasil');
    }

    /**
     * Get authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $request->user()->currentAccessToken();
        $expiresAt = $currentToken->created_at->addMinutes((int) config('sanctum.expiration', 720));

        return $this->success([
            'user' => $this->formatUser($user),
            'token_expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Logout user (revoke current token).
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logout berhasil');
    }

    /**
     * Logout from all devices (revoke all tokens).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        // Revoke all tokens
        $request->user()->tokens()->delete();

        return $this->success(null, 'Logout dari semua perangkat berhasil');
    }

    /**
     * Refresh token (create new token and revoke old one).
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        // Create new token
        $token = $user->createToken('auth-token')->plainTextToken;

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 720));

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'token_expires_at' => $expiresAt->toIso8601String(),
        ], 'Token berhasil diperbarui');
    }

    /**
     * Get user preferences.
     */
    public function getPreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'preferences' => $user->getPreferencesWithDefaults(),
        ]);
    }

    /**
     * Update user preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preset' => 'sometimes|string|in:Aura,Lara,Nora',
            'primary' => 'sometimes|string|max:20',
            'surface' => 'sometimes|string|max:20',
            'dark_theme' => 'sometimes|boolean',
            'menu_mode' => 'sometimes|string|in:static,overlay',
        ]);

        $user = $request->user();
        $user->updatePreferences($validated);

        return $this->success([
            'preferences' => $user->getPreferencesWithDefaults(),
        ], 'Preferences berhasil disimpan');
    }

    /**
     * Format user data for response.
     */
    private function formatUser(User $user): array
    {
        return [
            'ulid' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'status' => $user->status,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'preferences' => $user->getPreferencesWithDefaults(),
        ];
    }
}
