<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevicePinState extends Model
{
    public $timestamps = false;

    protected $fillable = ['device_id', 'pin', 'type', 'label', 'value', 'updated_at'];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
