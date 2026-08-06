<?php

use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Reuses the same DeviceController as the Android API (routes/api.php).
    // $request->user() and the DevicePolicy checks work identically under
    // the session guard — no duplicated business logic between the two
    // clients, just a different auth boundary. Broadcasting auth for the
    // browser goes through Laravel's own default /broadcasting/auth route
    // (session-based), registered separately from the Sanctum one Android uses.
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::post('/devices/{device}/command', [DeviceController::class, 'sendCommand'])
        ->middleware('throttle:30,1');
    Route::get('/devices/{device}/command/{command}', [DeviceController::class, 'commandStatus']);
});
