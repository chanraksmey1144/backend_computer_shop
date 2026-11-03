<?php

use App\Http\Controllers\API\AddressController;
use App\Http\Controllers\API\BrandController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\GoogleAuthController;
use App\Http\Controllers\API\InventoryController;
use App\Http\Controllers\API\InventoryMovementController;
use App\Http\Controllers\API\OtpController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\ProductImageController;
use App\Http\Controllers\API\ProductSerialNumberController;
use App\Http\Controllers\API\ProductVariantController;
use App\Http\Controllers\API\RoleController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\WarehouseController;
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

// OAuth uses the web session to validate the state value returned by Google.
Route::middleware('web')->prefix('auth')->group(function () {
    Route::get('/google', [GoogleAuthController::class, 'redirect']);
    Route::get('/google/callback', [GoogleAuthController::class, 'callback']);
});

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:6,1');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:6,1');
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
});

Route::prefix('otp')->group(function () {
    Route::post('/send', [OtpController::class, 'send'])
        ->middleware('throttle:otp-send');

    Route::post('/verify', [OtpController::class, 'verify'])
        ->middleware('throttle:otp-verify');

    // Protect this route with authentication and admin authorization
    // before using it in an actual application.
});

Route::apiResource('categories', CategoryController::class);
Route::apiResource('brands', BrandController::class);
Route::apiResource('products', ProductController::class);
Route::apiResource('products/{product}/images', ProductImageController::class);
Route::apiResource('products/{product}/variants', ProductVariantController::class);
Route::apiResource('warehouses', WarehouseController::class);
Route::apiResource('inventories', InventoryController::class);
Route::apiResource('inventory-movements', InventoryMovementController::class);
Route::apiResource('product-serial-numbers', ProductSerialNumberController::class);

Route::apiResource('addresses', AddressController::class);
Route::patch('addresses/{address}/default', [AddressController::class, 'setDefault']);

Route::prefix('user')->group(function () {
    Route::apiResource('addresses', AddressController::class);
    Route::patch('addresses/{address}/default', [AddressController::class, 'setDefault']);
});
