<?php

use App\Http\Controllers\SiteApi\AccountController;
use App\Http\Controllers\SiteApi\AgreementController;
use App\Http\Controllers\SiteApi\ConfigController;
use App\Http\Controllers\SiteApi\PhoneVerificationController;
use Illuminate\Support\Facades\Route;

/*
| The API of the association's web site (see App\Support\SiteApi): loaded
| under /api/site with the "site-api" middleware group. Modules add their
| own endpoints in routes/api.php.
*/

Route::get('/config', ConfigController::class)->name('config');
Route::get('/agreements/{key}', AgreementController::class)->where('key', '[a-z0-9-]+')->name('agreements.show');
Route::post('/phone-verifications', [PhoneVerificationController::class, 'store'])->middleware('throttle:site-visitor')->name('phone-verifications.store');
Route::post('/phone-verifications/verify', [PhoneVerificationController::class, 'verify'])->name('phone-verifications.verify');
Route::post('/accounts', [AccountController::class, 'store'])->middleware('throttle:site-visitor')->name('accounts.store');
