<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Device $device,
        public array $payload   // decoded MQTT status JSON: {online, pins, cmd_id, result}
    ) {}

    /**
     * Private channel scoped to the owning user so one account can't
     * eavesdrop on another's devices.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->device->user_id}.devices"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'device.status';
    }

    public function broadcastWith(): array
    {
        return [
            'device_id'  => $this->device->id,
            'device_uid' => $this->device->device_uid,
            'is_online'  => $this->payload['online'] ?? true,
            'pins'       => $this->payload['pins'] ?? [],
            'cmd_id'     => $this->payload['cmd_id'] ?? null,
            'result'     => $this->payload['result'] ?? null,
            'at'         => now()->toIso8601String(),
        ];
    }
}
