<?php

use App\Http\Controllers\API\AbaPayWayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/payments/aba/{payment}/checkout', [AbaPayWayController::class, 'checkoutPage'])
    ->middleware('signed')
    ->name('payway.checkout');
