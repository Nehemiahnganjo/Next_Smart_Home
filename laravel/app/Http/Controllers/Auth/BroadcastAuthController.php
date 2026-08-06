<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Laravel's built-in Broadcast::routes() assumes a web session. The Android
 * app authenticates with a Sanctum bearer token instead, so we expose the
 * same auth logic behind auth:sanctum explicitly.
 *
 * routes/api.php:
 *   Route::middleware('auth:sanctum')->post('/broadcasting/auth', [BroadcastAuthController::class, 'auth']);
 *
 * Point the Reverb/Pusher client's authEndpoint at POST {base}/api/broadcasting/auth
 * with the same Bearer header used for the rest of the API.
 */
class BroadcastAuthController extends Controller
{
    public function auth(Request $request)
    {
        return Broadcast::auth($request);
    }
}
