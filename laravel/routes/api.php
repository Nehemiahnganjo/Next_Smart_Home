<?php

use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Auth\BroadcastAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices', [DeviceController::class, 'store']);

    // Throttled separately and more tightly: commands hit real hardware,
    // so we cap at 30/min/user to prevent flooding a device (e.g. relay
    // chatter, runaway UI loop) rather than relying on the general API limit.
    Route::post('/devices/{device}/command', [DeviceController::class, 'sendCommand'])
        ->middleware('throttle:30,1');

    Route::get('/devices/{device}/command/{command}', [DeviceController::class, 'commandStatus']);

    // Reverb/Pusher-protocol private channel auth for Sanctum bearer clients.
    Route::post('/broadcasting/auth', [BroadcastAuthController::class, 'auth']);
});
