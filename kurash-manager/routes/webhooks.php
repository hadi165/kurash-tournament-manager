<?php

use App\Http\Controllers\Webhooks\ScoreboardResultController;
use App\Http\Middleware\VerifyScoreboardSecret;
use Illuminate\Support\Facades\Route;

/*
 | Called by scoreboard hardware on the mats, not by a browser. No session, no
 | CSRF token — authentication is the shared secret header checked by
 | VerifyScoreboardSecret, plus a rate limit so a device stuck in a retry loop
 | cannot flood the database mid-event.
 */
Route::middleware([VerifyScoreboardSecret::class, 'throttle:120,1'])
    ->prefix('webhooks')
    ->group(function () {
        Route::post('scoreboard', ScoreboardResultController::class)->name('webhooks.scoreboard');
    });
