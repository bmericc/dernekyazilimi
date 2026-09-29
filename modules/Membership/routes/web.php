<?php

use Illuminate\Support\Facades\Route;
use Modules\Membership\Http\Controllers\ApplicationController;
use Modules\Membership\Http\Controllers\ReferenceResponseController;

Route::middleware('auth')->group(function () {
    Route::get('/membership/apply', [ApplicationController::class, 'create'])->name('membership.apply');
    Route::post('/membership/apply', [ApplicationController::class, 'store'])->middleware('throttle:5,1')->name('membership.apply.store');
    Route::get('/membership/application', [ApplicationController::class, 'show'])->name('membership.application');
    Route::get('/membership/application/pdf', [ApplicationController::class, 'pdf'])->name('membership.application.pdf');
    Route::delete('/membership/application', [ApplicationController::class, 'withdraw'])->name('membership.application.withdraw');
    Route::put('/membership/references/{reference}/replace', [ApplicationController::class, 'replace'])->middleware('throttle:10,1')->name('membership.references.replace');

    // Opened from the invitation email; the member must be signed in.
    Route::get('/membership/references/{reference}/{token}', [ReferenceResponseController::class, 'show'])->name('membership.references.show');
    Route::post('/membership/references/{reference}/{token}', [ReferenceResponseController::class, 'respond'])->middleware('throttle:10,1')->name('membership.references.respond');
});
