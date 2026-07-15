<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Throwable;

final class AuthController extends Controller
{
    /**
     * Normal login token lifetime: 60 minutes.
     */
    private const DEFAULT_TOKEN_TTL = 60;

    /**
     * Remember-me token lifetime: 30 days.
     */
    private const REMEMBER_TOKEN_TTL = 43_200;

    /**
     * Register a new user.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => [
                'required',
                'string',
                'max:100',
            ],
            'last_name' => [
                'nullable',
                'string',
                'max:100',
            ],
            'email' => [
                'nullable',
                'email:rfc',
                'max:255',
                'required_without:phone',
                'unique:users,email',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
                'required_without:email',
                'unique:users,phone',
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->numbers(),
            ],
            'remember' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $remember = (bool) ($validated['remember'] ?? false);
        $tokenTtl = $this->resolveTokenTtl($remember);

        try {
            [$user, $token] = DB::transaction(
                function () use ($validated, $tokenTtl): array {
                    $user = User::query()->create([
                        'first_name' => trim($validated['first_name']),
                        'last_name' => $this->nullableString(
                            $validated['last_name'] ?? null
                        ),
                        'email' => isset($validated['email'])
                            ? mb_strtolower(trim($validated['email']))
                            : null,
                        'phone' => $this->normalizePhone(
                            $validated['phone'] ?? null
                        ),
                        'password' => $validated['password'],
                        'role' => 'user',
                        'status' => 'active',
                    ]);

                    $guard = $this->guard();
                    $guard->setTTL($tokenTtl);

                    /** @var JWTSubject $user */
                    $token = $guard->login($user);

                    return [$user, $token];
                }
            );

            return $this->respondWithToken(
                token: $token,
                user: $user,
                message: 'Register successful.',
                tokenTtl: $tokenTtl,
                remember: $remember,
                status: JsonResponse::HTTP_CREATED,
            );
        } catch (Throwable $exception) {
            Log::error('User registration failed', [
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create your account right now.',
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Authenticate using email or phone number.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => [
                'required',
                'string',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
            ],
            'remember' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $login = trim($validated['login']);
        $remember = (bool) ($validated['remember'] ?? false);
        $tokenTtl = $this->resolveTokenTtl($remember);

        $field = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'phone';

        $loginValue = $field === 'email'
            ? mb_strtolower($login)
            : $this->normalizePhone($login);

        $credentials = [
            $field => $loginValue,
            'password' => $validated['password'],
        ];

        try {
            $guard = $this->guard();
            $guard->setTTL($tokenTtl);

            $token = $guard->attempt($credentials);

            if (! $token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid email, phone number, or password.',
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }

            /** @var User|null $user */
            $user = $guard->user();

            if (! $user) {
                $guard->logout();

                return response()->json([
                    'success' => false,
                    'message' => 'User account was not found.',
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }

            if ($user->status !== 'active') {
                $guard->logout();

                return response()->json([
                    'success' => false,
                    'message' => 'Your account is not active.',
                ], JsonResponse::HTTP_FORBIDDEN);
            }

            $user->forceFill([
                'last_login_at' => now(),
            ])->save();

            return $this->respondWithToken(
                token: $token,
                user: $user->fresh(),
                message: 'Login successful.',
                tokenTtl: $tokenTtl,
                remember: $remember,
            );
        } catch (Throwable $exception) {
            Log::error('User login failed', [
                'login_field' => $field,
                'login' => $loginValue,
                'remember' => $remember,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to log in right now. Please try again.',
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Return the authenticated user.
     */
    public function me(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->guard()->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
            ],
        ]);
    }

    /**
     * Refresh the current JWT.
     */
    public function refresh(): JsonResponse
    {
        try {
            $guard = $this->guard();

            /** @var User|null $user */
            $user = $guard->user();

            if (! $user || $user->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is not active.',
                ], JsonResponse::HTTP_FORBIDDEN);
            }

            $token = $guard->refresh();
            $tokenTtl = (int) $guard->factory()->getTTL();

            return $this->respondWithToken(
                token: $token,
                user: $user,
                message: 'Token refreshed successfully.',
                tokenTtl: $tokenTtl,
                remember: $tokenTtl > self::DEFAULT_TOKEN_TTL,
            );
        } catch (Throwable $exception) {
            Log::warning('JWT refresh failed', [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The token could not be refreshed.',
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }
    }

    /**
     * Invalidate the current JWT.
     */
    public function logout(): JsonResponse
    {
        try {
            $this->guard()->logout();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful.',
            ]);
        } catch (Throwable $exception) {
            Log::warning('JWT logout failed', [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to log out with the supplied token.',
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }
    }

    /**
     * Build a consistent authentication response.
     */
    private function respondWithToken(
        string $token,
        User $user,
        string $message,
        int $tokenTtl,
        bool $remember = false,
        int $status = JsonResponse::HTTP_OK,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $tokenTtl * 60,
            'remember' => $remember,
            'user' => $user,
        ], $status);
    }

    /**
     * Return the configured JWT API guard.
     */
    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        return $guard;
    }

    private function resolveTokenTtl(bool $remember): int
    {
        return $remember
            ? self::REMEMBER_TOKEN_TTL
            : self::DEFAULT_TOKEN_TTL;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        return preg_replace('/[\s\-()]/', '', $phone);
    }
}

