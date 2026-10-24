<?php

use App\Http\Controllers\API\BrandController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\InventoryController;
use App\Http\Controllers\API\OtpController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\ProductImageController;
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
