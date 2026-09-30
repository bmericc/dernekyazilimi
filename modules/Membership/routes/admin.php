<?php

use Illuminate\Support\Facades\Route;
use Modules\Membership\Http\Controllers\Admin\ApplicationAdminController;
use Modules\Membership\Http\Controllers\Admin\DerbisImportController;
use Modules\Membership\Http\Controllers\Admin\MembershipController;
use Modules\Membership\Http\Controllers\Admin\MembershipFeeController;
use Modules\Membership\Http\Controllers\Admin\MembershipSettingsController;

Route::middleware('permission:memberships.view')->group(function () {
    Route::get('/memberships', [MembershipController::class, 'index'])->name('memberships');
    Route::get('/membership-applications', [ApplicationAdminController::class, 'index'])->name('membership-applications');
    Route::get('/membership-applications/{application}', [ApplicationAdminController::class, 'show'])->name('membership-applications.show');
    Route::get('/membership-applications/{application}/pdf', [ApplicationAdminController::class, 'pdf'])->name('membership-applications.pdf');
});

Route::middleware('permission:memberships.manage')->group(function () {
    Route::post('/contacts/{contact}/membership', [MembershipController::class, 'store'])->name('memberships.store');
    Route::put('/memberships/{membership}', [MembershipController::class, 'update'])->whereNumber('membership')->name('memberships.update');
    Route::patch('/memberships/{membership}/status', [MembershipController::class, 'status'])->whereNumber('membership')->name('memberships.status');
    Route::post('/memberships/{membership}/events', [MembershipController::class, 'event'])->whereNumber('membership')->name('memberships.events.store');
    Route::patch('/membership-applications/{application}/signed-form', [ApplicationAdminController::class, 'signedForm'])->name('membership-applications.signed-form');
    Route::patch('/membership-applications/{application}/approve', [ApplicationAdminController::class, 'approve'])->name('membership-applications.approve');
    Route::patch('/membership-applications/{application}/reject', [ApplicationAdminController::class, 'reject'])->name('membership-applications.reject');
    Route::post('/membership-references/{reference}/resend', [ApplicationAdminController::class, 'resend'])->middleware('throttle:10,1')->name('membership-references.resend');
    Route::get('/memberships/settings', [MembershipSettingsController::class, 'edit'])->name('memberships.settings');
    Route::put('/memberships/settings', [MembershipSettingsController::class, 'update'])->name('memberships.settings.update');
    Route::get('/memberships/import', [DerbisImportController::class, 'create'])->name('memberships.import');
    Route::post('/memberships/import', [DerbisImportController::class, 'store'])->name('memberships.import.store');
    Route::get('/memberships/import/preview', [DerbisImportController::class, 'preview'])->name('memberships.import.preview');
    Route::post('/memberships/import/apply', [DerbisImportController::class, 'apply'])->name('memberships.import.apply');
    Route::delete('/memberships/import', [DerbisImportController::class, 'cancel'])->name('memberships.import.cancel');
    Route::get('/membership-fees', [MembershipFeeController::class, 'index'])->name('membership-fees');
    Route::post('/membership-fees', [MembershipFeeController::class, 'store'])->name('membership-fees.store');
    Route::put('/membership-fees/{fee}', [MembershipFeeController::class, 'update'])->name('membership-fees.update');
    Route::delete('/membership-fees/{fee}', [MembershipFeeController::class, 'destroy'])->name('membership-fees.destroy');
});
