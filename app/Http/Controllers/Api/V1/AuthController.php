<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\OrganizationRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** bcrypt hash of a throwaway value, compared when no user matches the email. */
    private const TIMING_EQUALISER_HASH = '$2y$12$xq/NSBcyUBLLtc8AbOp8veOuZyEsMLmkv6vLW4eQZycFUl.RUBHxK';

    /** Sign-up: creates a new organization and its first administrator. */
    public function register(Request $request, OrganizationRegistrar $registrar): JsonResponse
    {
        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone:all'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        return $this->tokenResponse($registrar->register($data), 'registration');
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', $credentials['email'])->first();
        $passwordMatches = Hash::check($credentials['password'], $user->password ?? self::TIMING_EQUALISER_HASH);

        if (! $user || ! $passwordMatches || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        return $this->tokenResponse($user, $credentials['device_name']);
    }

    public function acceptInvitation(Request $request, InvitationService $invitations): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        return $this->tokenResponse($invitations->accept($data['token'], $data['password']), 'invitation');
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('organization'));
    }

    private function tokenResponse(User $user, string $deviceName): JsonResponse
    {
        $token = $user->createToken($deviceName, expiresAt: now()->addMinutes((int) config('sanctum.expiration')));

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at->toIso8601String(),
            'user' => new UserResource($user->load('organization')),
        ], 201);
    }
}
