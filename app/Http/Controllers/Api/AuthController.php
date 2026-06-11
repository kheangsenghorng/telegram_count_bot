<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'nullable|string|max:255',
            'email'      => 'nullable|email|unique:users,email|required_without:phone',
            'phone'      => 'nullable|string|unique:users,phone|required_without:email',
            'password'   => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'] ?? null,
            'email'      => $data['email'] ?? null,
            'phone'      => $data['phone'] ?? null,
            'password'   => $data['password'],
            'role'       => 'user',
            'status'     => 'active',
        ]);

        $token = JWTAuth::fromUser($user);

        return $this->respondWithToken($token, $user, 'Register successful', 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'login'    => 'required|string',
            'password' => 'required|string',
        ]);
    
        $field = filter_var($request->login, FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'phone';
    
        $credentials = [
            $field => $request->login,
            'password' => $request->password,
        ];
    
        try {
            if (!$token = auth('api')->attempt($credentials)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }
    
            $user = auth('api')->user();
    
            if (!$user || $user->status !== 'active') {
                auth('api')->logout();
    
                return response()->json([
                    'success' => false,
                    'message' => 'Account is not active',
                ], 403);
            }
    
            $user->update([
                'last_login_at' => now(),
            ]);
    
            return $this->respondWithToken($token, $user, 'Login successful');
    
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login server error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function me()
    {
        return response()->json([
            'success' => true,
            'user' => auth('api')->user(),
        ]);
    }

    public function refresh()
    {
        return response()->json([
            'success' => true,
            'access_token' => auth('api')->refresh(),
            'token_type' => 'Bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }

    public function logout()
    {
        auth('api')->logout();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful',
        ]);
    }

    private function respondWithToken($token, User $user, $message = 'Success', $status = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $user,
        ], $status);
    }
}