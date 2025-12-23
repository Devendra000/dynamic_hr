SETUP
- project init: ```composer create-project laravel/laravel dynamic_hr "10.*"```
- php version check: ```php artisan --version``` => 10.50.0
- database setup: POSTGRESql -> for simplicity used: docker-compose.yml with postgres setup
    postgres on port: 8002
    adminer on port: 8003 (to view the database)
- project works on port 8000
- ```php artisan migrate``` to migrate the base tables

JWT Auth setup:
- install package and publish config files
    Run the following command to pull in the latest version:
        ```composer require php-open-source-saver/jwt-auth```
    publish the package config file:
        ```php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"```
    and setup the secret key in .env with:
        ```php artisan jwt:secret```

- guard setup to use jwt
    - need to add jwt in auth guard (config/auth.php): setup guard for api:   
        'guards' => [
                    'web' => [
                        'driver' => 'session',
                        'provider' => 'users',
                    ],
                    'api' => [
                        'driver' => 'jwt',
                        'provider' => 'users',
                        'hash' => false,
                    ]
                ],
    - implement JWTAuth's JWTSubject in User model
    - create controller for authentication ```php artisan make:controller AuthenticationController```
    - Create JWTMiddleware and RateLimitAuth middleawre for authentication
    - create trait for AuthenticatesWithJWT and use it for uniform response
    - create specific exception ```php artisan make:exception AuthenticationException``` for exception handling for JWT
    - Use FormRequest validation for proper form data validation

