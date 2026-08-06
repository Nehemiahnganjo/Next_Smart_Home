<?php

use App\Models\Device;
use Illuminate\Support\Facades\Broadcast;

// User can only join the socket channel for their own device stream.
Broadcast::channel('user.{userId}.devices', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
