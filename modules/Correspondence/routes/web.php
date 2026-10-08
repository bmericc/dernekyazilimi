<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\VerifyCsrfToken;
use Modules\Correspondence\Http\Controllers\SigningController;
use Modules\Correspondence\Http\Controllers\VerifyController;

// Public: the verification address printed on letters and written in e-Yazışma packages.
Route::get('/belge-dogrula', [VerifyController::class, 'show'])->middleware('throttle:30,1')->name('correspondence.verify');

// The signing application: the single-use link is the credential, so there is no session or CSRF token.
Route::middleware('throttle:30,1')->where(['token' => '[A-Za-z0-9]{64}'])->group(function () {
    Route::get('/imza/{token}', [SigningController::class, 'show'])->name('correspondence.signing');
    Route::post('/imza/{token}', [SigningController::class, 'store'])->withoutMiddleware(VerifyCsrfToken::class)->name('correspondence.signing.store');
});
