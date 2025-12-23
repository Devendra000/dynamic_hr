<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $key = 'auth'): Response
    {
        $identifier = $key . ':' . $request->ip();

        // Allow 5 attempts per minute for authentication endpoints
        if (RateLimiter::tooManyAttempts($identifier, 5)) {
            $seconds = RateLimiter::availableIn($identifier);
            
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Please try again in ' . $seconds . ' seconds.',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'retry_after' => $seconds
            ], 429);
        }

        RateLimiter::hit($identifier, 60);

        return $next($request);
    }
}
