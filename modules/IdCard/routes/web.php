<?php

use Illuminate\Support\Facades\Route;
use Modules\IdCard\Http\Controllers\CardController;
use Modules\IdCard\Http\Controllers\SharedCardController;
use Modules\IdCard\Http\Controllers\VerifyController;

Route::middleware('auth')->group(function () {
    Route::get('/my-cards', [CardController::class, 'index'])->name('id-cards');
    Route::post('/my-cards/{card}/renew', [CardController::class, 'renew'])->name('id-cards.renew');
    Route::post('/my-cards/{card}/send', [CardController::class, 'send'])->middleware('throttle:5,10')->name('id-cards.send');
});

// Public: opened by the QR code on a card.
Route::get('/kart/{token}', [VerifyController::class, 'show'])->middleware('throttle:60,1')->where('token', '[0-9a-f]{40}')->name('id-card.verify');
// Public with the personal signed link sent to the holder's phone.
Route::get('/kartim/{card}/{key}', [SharedCardController::class, 'show'])->middleware(['signed', 'throttle:60,1'])->name('id-card.shared');
Route::get('/id-card-logos/{template}', [CardController::class, 'logo'])->name('id-card.logo');
