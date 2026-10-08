<?php

use Illuminate\Support\Facades\Route;
use Modules\Correspondence\Http\Controllers\VerifyController;

// Public: the verification address printed on letters and written in e-Yazışma packages.
Route::get('/belge-dogrula', [VerifyController::class, 'show'])->middleware('throttle:30,1')->name('correspondence.verify');
