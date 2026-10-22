<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/account', [AccountController::class, 'show']);
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions/credit', [TransactionController::class, 'credit']);
    Route::post('/transactions/debit', [TransactionController::class, 'debit']);
    Route::post('/transactions/{transactionId}/reverse', [TransactionController::class, 'reverse'])
        ->whereNumber('transactionId');
});
