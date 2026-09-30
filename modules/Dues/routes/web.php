<?php

use Illuminate\Support\Facades\Route;
use Modules\Dues\Http\Controllers\DuesController;

Route::middleware('auth')->group(function () {
    Route::get('/my-dues', [DuesController::class, 'mine'])->name('dues.mine');
    Route::post('/my-dues/pay', [DuesController::class, 'pay'])->middleware('throttle:10,1')->name('dues.pay');
});

// Public balance lookup: the balance goes to the member's email with a
// personal, signed link to pay without signing in.
Route::get('/odeme', [DuesController::class, 'lookupForm'])->name('dues.lookup');
Route::post('/odeme', [DuesController::class, 'lookup'])->middleware('throttle:5,1')->name('dues.lookup.send');
Route::middleware('signed')->group(function () {
    Route::get('/odeme/{contact}/{hash}', [DuesController::class, 'publicAccount'])->name('dues.public.account');
    Route::post('/odeme/{contact}/{hash}', [DuesController::class, 'publicPay'])->middleware('throttle:10,1')->name('dues.public.pay');
});

Route::get('/dues/{uuid}', [DuesController::class, 'show'])->whereUuid('uuid')->name('dues.show');
