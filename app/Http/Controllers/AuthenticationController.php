<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Traits\AuthenticatesWithJWT;
use App\Services\AuthService;
use App\Exceptions\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Exception;
use Illuminate\Http\Request;

class AuthenticationController extends Controller
{
    use AuthenticatesWithJWT;

    /**
     * Auth service instance.
     *
     * @var AuthService
     */
    protected $authService;

    /**
     * Create a new AuthenticationController instance.
     *
     * @param AuthService $authService
     * @return void
     */
    public function __construct(AuthService $authService)
    {
        $this->middleware('auth:api', ['except' => ['login', 'register']]);
        $this->authService = $authService;
    }

    /**
     * Register a new user.
     *
     * @param RegisterRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $user = $this->authService->register($request->validated());

            // Generate token for the newly registered user
            $token = auth()->login($user);

            return $this->respondWithToken(`$token`, $user)->setStatusCode(201);
        } catch (AuthenticationException $e) {
            return $e->render($request);
        } catch (Exception $e) {
            return $this->errorResponse(
                'Registration failed. Please try again.',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Authenticate user and return JWT token.
     *
     * @param LoginRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $token = $this->authService->login($request->validated());
            return $this->respondWithToken($token);
        } catch (AuthenticationException $e) {
            // ✅ Preserves error_code, context, and proper status code
            return $e->render($request);
        } catch (Exception $e) {
            return $this->errorResponse(
                'Login failed. Please try again.',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get authenticated user profile.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $this->authService->getAuthenticatedUser();
            
            return $this->successResponse([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]
            ], 'User profile retrieved successfully');
        } catch (AuthenticationException $e) {
            return $e->render($request);
        } catch (Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve user profile.',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Logout user and invalidate token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Check if user is authenticated first
            if (!auth()->check()) {
                throw AuthenticationException::tokenMissing();
            }

            $this->authService->logout();
            return $this->successResponse(null, 'Successfully logged out');
        } catch (AuthenticationException $e) {
            return $e->render($request);
        } catch (Exception $e) {
            return $this->errorResponse(
                'Logout failed. Please try again.',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }
    /**
     * Refresh JWT token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            // Check if user is authenticated first
            if (!auth()->check()) {
                throw AuthenticationException::tokenMissing();
            }

            $token = $this->authService->refreshToken();
            return $this->respondWithToken($token);
        } catch (AuthenticationException $e) {
            return $e->render($request);
        } catch (Exception $e) {
            return $this->errorResponse(
                'Token refresh failed. Please try again.',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Validate if the current token is valid.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateToken(Request $request): JsonResponse
    {
        try {
            $isValid = $this->authService->validateToken();
            
            return $this->successResponse([
                'valid' => $isValid
            ], $isValid ? 'Token is valid' : 'Token is invalid');
        } catch (AuthenticationException $e) {
            return $e->render($request);
        } catch (Exception $e) {
            return $this->errorResponse(
                'Token validation failed.',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }
}
