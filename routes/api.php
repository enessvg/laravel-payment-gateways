<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('payment', [PaymentController::class, 'pay'])->name('payment.pay');

Route::post('payment/callback', [PaymentController::class, 'callback'])->name('payment.callback');
Route::post('tds/callback', [PaymentController::class, 'callback'])->name('payment.tds.callback');

Route::match(['get', 'post'], 'payment/return/success', [PaymentController::class, 'returnSuccess'])
    ->name('payment.return.success');
Route::match(['get', 'post'], 'payment/return/fail', [PaymentController::class, 'returnFailure'])
    ->name('payment.return.fail');
