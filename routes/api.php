<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\API\OtpController;
use App\Http\Controllers\API\RoleController;
use App\Http\Controllers\API\UserController;
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

Route::apiResource('roles', RoleController::class);
Route::apiResource('users', UserController::class);

Route::prefix('otp')->group(function () {
    Route::post('/send', [OtpController::class, 'send'])
        ->middleware('throttle:otp-send');

    Route::post('/verify', [OtpController::class, 'verify'])
        ->middleware('throttle:otp-verify');

    // Protect this route with authentication and admin authorization
    // before using it in an actual application.
});

Route::apiResource('categories',CategoryController::class);

