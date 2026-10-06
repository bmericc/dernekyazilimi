<?php

use Illuminate\Support\Facades\Route;
use Modules\Donation\Http\Controllers\Api\DonationController;

Route::post('/donations', [DonationController::class, 'store'])->middleware('throttle:site-visitor')->name('donations.store');
