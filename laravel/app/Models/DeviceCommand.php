<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model
{
    use HasFactory;

    protected $fillable = [
        'cmd_uuid', 'device_id', 'issued_by', 'action', 'pin', 'value', 'status', 'result', 'acked_at',
    ];

    protected $casts = [
        'result'   => 'array',
        'acked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
