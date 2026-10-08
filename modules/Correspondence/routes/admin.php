<?php

use Illuminate\Support\Facades\Route;
use Modules\Correspondence\Http\Controllers\Admin\AttachmentController;
use Modules\Correspondence\Http\Controllers\Admin\LetterController;
use Modules\Correspondence\Http\Controllers\Admin\PackageController;
use Modules\Correspondence\Http\Controllers\Admin\SettingsController;

Route::middleware('permission:correspondence.settings')->group(function () {
    Route::get('/correspondence/settings', [SettingsController::class, 'edit'])->name('correspondence.settings');
    Route::put('/correspondence/settings', [SettingsController::class, 'update'])->name('correspondence.settings.update');
});

Route::middleware('permission:correspondence.manage')->group(function () {
    Route::get('/correspondence/create', [LetterController::class, 'create'])->name('correspondence.create');
    Route::post('/correspondence', [LetterController::class, 'store'])->name('correspondence.store');
    Route::get('/correspondence/{letter}/edit', [LetterController::class, 'edit'])->name('correspondence.edit');
    Route::put('/correspondence/{letter}', [LetterController::class, 'update'])->name('correspondence.update');
    Route::delete('/correspondence/{letter}', [LetterController::class, 'destroy'])->name('correspondence.destroy');
    Route::post('/correspondence/{letter}/submit', [LetterController::class, 'submit'])->name('correspondence.submit');
    Route::post('/correspondence/{letter}/attachments', [AttachmentController::class, 'store'])->name('correspondence.attachments.store');
    Route::delete('/correspondence/{letter}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('correspondence.attachments.destroy');
    Route::post('/correspondence/{letter}/package', [PackageController::class, 'store'])->name('correspondence.package.store');
    Route::delete('/correspondence/{letter}/package', [PackageController::class, 'destroy'])->name('correspondence.package.destroy');
    Route::post('/correspondence/{letter}/package/signature', [PackageController::class, 'sign'])->name('correspondence.package.sign');
    Route::post('/correspondence/{letter}/package/seal', [PackageController::class, 'seal'])->name('correspondence.package.seal');
});

Route::middleware('permission:correspondence.approve')->group(function () {
    Route::post('/correspondence/{letter}/approve', [LetterController::class, 'approve'])->name('correspondence.approve');
    Route::post('/correspondence/{letter}/return', [LetterController::class, 'return'])->name('correspondence.return');
    Route::post('/correspondence/{letter}/cancel', [LetterController::class, 'cancel'])->name('correspondence.cancel');
});

Route::middleware('permission:correspondence.view')->group(function () {
    Route::get('/correspondence', [LetterController::class, 'index'])->name('correspondence');
    Route::get('/correspondence/{letter}', [LetterController::class, 'show'])->name('correspondence.show');
    Route::get('/correspondence/{letter}/pdf', [LetterController::class, 'pdf'])->name('correspondence.pdf');
    Route::get('/correspondence/{letter}/attachments/{attachment}', [AttachmentController::class, 'show'])->name('correspondence.attachments.show');
    Route::get('/correspondence/{letter}/package', [PackageController::class, 'show'])->name('correspondence.package');
    Route::get('/correspondence/{letter}/package/digest', [PackageController::class, 'digest'])->name('correspondence.package.digest');
    Route::get('/correspondence/{letter}/package/final-digest', [PackageController::class, 'finalDigest'])->name('correspondence.package.final-digest');
});
