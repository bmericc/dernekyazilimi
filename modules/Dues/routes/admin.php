<?php

use Illuminate\Support\Facades\Route;
use Modules\Dues\Http\Controllers\Admin\DuesAdminController;
use Modules\Dues\Http\Controllers\Admin\DuesSettingsController;

Route::middleware('permission:dues.view')->group(function () {
    Route::get('/dues', [DuesAdminController::class, 'index'])->name('dues');
});

Route::middleware('permission:dues.manage')->group(function () {
    Route::get('/dues/charge', [DuesAdminController::class, 'preview'])->name('dues.charge');
    Route::post('/dues/charge', [DuesAdminController::class, 'chargeYear'])->name('dues.charge.store');
    Route::post('/dues/reminders', [DuesAdminController::class, 'remindAll'])->name('dues.reminders');
    Route::post('/dues/contacts/{contact}/reminder', [DuesAdminController::class, 'remind'])->name('dues.remind');
    Route::post('/dues/contacts/{contact}/charges', [DuesAdminController::class, 'storeCharge'])->name('dues.charges.store');
    Route::patch('/dues/charges/{charge}/cancel', [DuesAdminController::class, 'cancelCharge'])->name('dues.charges.cancel');
    Route::patch('/dues/charges/{charge}/restore', [DuesAdminController::class, 'restoreCharge'])->name('dues.charges.restore');
    Route::post('/dues/contacts/{contact}/payments', [DuesAdminController::class, 'storePayment'])->name('dues.payments.store');
    Route::post('/dues/contacts/{contact}/exemptions', [DuesAdminController::class, 'storeExemption'])->name('dues.exemptions.store');
    Route::delete('/dues/exemptions/{exemption}', [DuesAdminController::class, 'destroyExemption'])->name('dues.exemptions.destroy');
});

Route::middleware('permission:dues.settings')->group(function () {
    Route::get('/dues/settings', [DuesSettingsController::class, 'edit'])->name('dues.settings');
    Route::put('/dues/settings', [DuesSettingsController::class, 'update'])->name('dues.settings.update');
});
