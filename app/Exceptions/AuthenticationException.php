<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class AuthenticationException extends Exception
{
    protected string $errorCode;
    protected array $context;

    /**
     * Create a new authentication exception instance.
     *
     * @param string $message
     * @param string $errorCode
     * @param array $context
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = 'Authentication failed',
        string $errorCode = 'AUTHENTICATION_FAILED',
        array $context = [],
        int $code = 401,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
        $this->context = $context;
    }

    /**
     * Get the error code.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get the context data.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render($request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'data' => $this->context
        ], $this->getCode());
    }

    /**
     * Static factory methods for common authentication errors
     */
    public static function invalidCredentials(): self
    {
        return new self(
            'Invalid email or password',
            'INVALID_CREDENTIALS',
            [],
            401
        );
    }

    public static function tokenExpired(): self
    {
        return new self(
            'Token has expired',
            'TOKEN_EXPIRED',
            [],
            401
        );
    }

    public static function tokenInvalid(): self
    {
        return new self(
            'Token is invalid',
            'TOKEN_INVALID',
            [],
            401
        );
    }

    public static function tokenMissing(): self
    {
        return new self(
            'Token not provided',
            'TOKEN_MISSING',
            [],
            401
        );
    }

    public static function userNotFound(): self
    {
        return new self(
            'User not found',
            'USER_NOT_FOUND',
            [],
            404
        );
    }

    public static function accountLocked(int $retryAfter = 900): self
    {
        return new self(
            'Account temporarily locked due to too many failed attempts',
            'ACCOUNT_LOCKED',
            ['retry_after' => $retryAfter],
            429
        );
    }

    public static function emailNotVerified(): self
    {
        return new self(
            'Email address not verified',
            'EMAIL_NOT_VERIFIED',
            [],
            403
        );
    }
}
