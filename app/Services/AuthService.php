<?php

namespace App\Services;

use App\Models\User;
use App\Exceptions\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class AuthService
{
    /**
     * Register a new user.
     */
    public function register(array $data): User
    {
        try {
            DB::beginTransaction();

            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'password' => Hash::make($data['password']),
            ]);

            Log::info('User registered successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip_address' => request()->ip()
            ]);

            DB::commit();

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User registration failed', [
                'error' => $e->getMessage(),
                'email' => $data['email'] ?? null
            ]);
            throw $e;
        }
    }

    /**
     * Authenticate user and return token.
     */
    public function login(array $credentials)
    {
        try {
            $credentials['email'] = strtolower(trim($credentials['email']));

            /** @var JWTGuard $guard */
            $guard = auth();
            
            if (!$token = $guard->attempt($credentials)) {
                Log::warning('Failed login attempt', [
                    'email' => $credentials['email'],
                    'ip_address' => request()->ip()
                ]);
                
                throw AuthenticationException::invalidCredentials();
            }

            Log::info('User logged in successfully', [
                'user_id' => auth()->user()->id,
                'email' => auth()->user()->email,
                'ip_address' => request()->ip()
            ]);

            return $token;
        } catch (JWTException $e) {
            Log::error('JWT Token creation failed', [
                'error' => $e->getMessage(),
                'email' => $credentials['email']
            ]);
            throw AuthenticationException::tokenInvalid();
        }
    }

    /**
     * Refresh the JWT token.
     */
    public function refreshToken(): string
    {
        try {
            /** @var JWTGuard $guard */
            $guard = auth();
            $token = $guard->refresh();

            Log::info('Token refreshed successfully', [
                'user_id' => auth()->user()->id,
                'ip_address' => request()->ip()
            ]);

            return $token;
        } catch (JWTException $e) {
            Log::error('Token refresh failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->user()->id ?? null
            ]);
            throw AuthenticationException::tokenExpired();
        }
    }

    /**
     * Logout user and invalidate token.
     */
    public function logout(): void
    {
        try {
            $userId = auth()->user()->id;
            
            auth()->logout();

            Log::info('User logged out successfully', [
                'user_id' => $userId,
                'ip_address' => request()->ip()
            ]);
        } catch (JWTException $e) {
            Log::error('Logout failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->user()->id ?? null
            ]);
            throw AuthenticationException::tokenInvalid();
        }
    }

    /**
     * Get authenticated user profile.
     */
    public function getAuthenticatedUser(): User
    {
        return auth()->user();
    }

    /**
     * Validate if token is still valid.
     */
    public function validateToken(): bool
    {
        try {
            return (bool) JWTAuth::parseToken()->authenticate();
        } catch (JWTException $e) {
            return false;
        }
    }
}