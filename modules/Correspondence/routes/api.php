<?php

use Illuminate\Support\Facades\Route;
use Modules\Correspondence\Http\Controllers\VerifyController;

Route::get('/documents/verify', [VerifyController::class, 'verify'])->middleware('throttle:site-visitor')->name('documents.verify');
