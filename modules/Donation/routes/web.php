<?php

use Illuminate\Support\Facades\Route;
use Modules\Donation\Http\Controllers\DonationController;

Route::get('/donate', [DonationController::class, 'create'])->name('donations.create');
Route::post('/donate', [DonationController::class, 'store'])->middleware('throttle:10,1')->name('donations.store');
Route::get('/donate/{uuid}', [DonationController::class, 'show'])->whereUuid('uuid')->name('donations.show');
Route::get('/my-donations', [DonationController::class, 'mine'])->middleware('auth')->name('donations.mine');
