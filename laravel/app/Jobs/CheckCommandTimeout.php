<?php

namespace App\Jobs;

use App\Events\DeviceStatusUpdated;
use App\Models\DeviceCommand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched with a delay (e.g. 15s) right after a command is published.
 * If the ESP32 never acks (offline, dropped packet, dead device), this
 * flips the command to 'timeout' instead of leaving it 'pending' forever,
 * and pushes that to the app over Reverb so the UI doesn't spin indefinitely.
 */
class CheckCommandTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $commandId) {}

    public function handle(): void
    {
        $command = DeviceCommand::with('device')->find($this->commandId);
        if (! $command || $command->status !== 'pending') {
            return; // already acked/failed, nothing to do
        }

        $command->update(['status' => 'timeout']);

        broadcast(new DeviceStatusUpdated($command->device, [
            'online' => $command->device->is_online,
            'cmd_id' => $command->cmd_uuid,
            'result' => 'timeout',
            'pins'   => [],
        ]));
    }
}
