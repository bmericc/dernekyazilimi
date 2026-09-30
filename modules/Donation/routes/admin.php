<?php

use Illuminate\Support\Facades\Route;
use Modules\Donation\Http\Controllers\Admin\DonationAdminController;
use Modules\Donation\Http\Controllers\Admin\DonationCauseController;
use Modules\Donation\Http\Controllers\Admin\DonationSettingsController;

Route::middleware('permission:donations.view')->group(function () {
    Route::get('/donations', [DonationAdminController::class, 'index'])->name('donations');
});

Route::middleware('permission:donations.manage')->group(function () {
    Route::post('/donations', [DonationAdminController::class, 'store'])->name('donations.store');
    Route::get('/donations/settings', [DonationSettingsController::class, 'edit'])->name('donations.settings');
    Route::put('/donations/settings', [DonationSettingsController::class, 'update'])->name('donations.settings.update');
    Route::get('/donation-causes', [DonationCauseController::class, 'index'])->name('donation-causes');
    Route::post('/donation-causes', [DonationCauseController::class, 'store'])->name('donation-causes.store');
    Route::put('/donation-causes/{cause}', [DonationCauseController::class, 'update'])->name('donation-causes.update');
    Route::delete('/donation-causes/{cause}', [DonationCauseController::class, 'destroy'])->name('donation-causes.destroy');
});
