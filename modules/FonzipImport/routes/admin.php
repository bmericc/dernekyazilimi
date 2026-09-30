<?php

use Illuminate\Support\Facades\Route;
use Modules\FonzipImport\Http\Controllers\Admin\FonzipImportController;

Route::middleware('permission:fonzip.import')->group(function () {
    Route::get('/fonzip', [FonzipImportController::class, 'index'])->name('fonzip');
    Route::post('/fonzip/fetch', [FonzipImportController::class, 'fetch'])->name('fonzip.fetch');
    Route::post('/fonzip/resume', [FonzipImportController::class, 'resume'])->name('fonzip.resume');
    Route::get('/fonzip/preview', [FonzipImportController::class, 'preview'])->name('fonzip.preview');
    Route::post('/fonzip/apply', [FonzipImportController::class, 'apply'])->name('fonzip.apply');
    Route::delete('/fonzip', [FonzipImportController::class, 'discard'])->name('fonzip.discard');
});
