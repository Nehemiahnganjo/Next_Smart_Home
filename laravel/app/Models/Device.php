<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'device_uid', 'name', 'secret_hash', 'is_online', 'last_seen_at', 'pin_config',
    ];

    // secret_hash is a bcrypt hash of the device's MQTT provisioning secret —
    // never meant to leave the server. It was previously absent from $hidden,
    // meaning every index()/store() response serialized it straight into the
    // JSON the Android app received. user_id is redundant once a device is
    // scoped to the authenticated user's own list, so hidden too.
    protected $hidden = ['secret_hash', 'user_id'];

    protected $casts = [
        'pin_config'   => 'array',
        'is_online'    => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function pinStates(): HasMany
    {
        return $this->hasMany(DevicePinState::class);
    }

    // MQTT topic helpers
    public function cmdTopic(): string
    {
        return "devices/{$this->device_uid}/cmd";
    }

    public function statusTopic(): string
    {
        return "devices/{$this->device_uid}/status";
    }
}
