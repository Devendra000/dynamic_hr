<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

trait AuthenticatesWithJWT
{
    /**
     * Get the token array structure.
     */
    protected function respondWithToken(string $token, $user = null): JsonResponse
    {
        /** @var JWTGuard $guard */
        $guard = auth();
        
        $response = [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $guard->factory()->getTTL() * 60
        ];

        if ($user) {
            $response['user'] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ];
        }

        return $this->successResponse($response, 'Authentication successful');
    }

    /**
     * Standard success response format.
     */
    protected function successResponse($data, string $message = 'Success'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * Standard error response format.
     */
    protected function errorResponse(string $message, int $code = 400, $data = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data
        ], $code);
    }
}
