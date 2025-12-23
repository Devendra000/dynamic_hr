<?php

use App\Http\Controllers\AuthenticationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/status', function () {
    return response()->json(['status' => 'API is running']);
});

// Public authentication routes
Route::group([
    'middleware' => ['api', 'rate.limit.auth'],
    'prefix' => 'auth'
], function () {
    Route::post('/register', [AuthenticationController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthenticationController::class, 'login'])->name('auth.login');
});

// Protected authentication routes
Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'auth'
], function () {
    Route::post('/logout', [AuthenticationController::class, 'logout'])->name('auth.logout');
    Route::post('/refresh', [AuthenticationController::class, 'refresh'])->name('auth.refresh');
    Route::get('/me', [AuthenticationController::class, 'me'])->name('auth.me');
    Route::get('/validate', [AuthenticationController::class, 'validateToken'])->name('auth.validate');
});
