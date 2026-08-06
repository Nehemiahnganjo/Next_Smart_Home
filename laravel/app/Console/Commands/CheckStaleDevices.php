<?php

namespace App\Console\Commands;

use App\Events\DeviceStatusUpdated;
use App\Models\Device;
use Illuminate\Console\Command;

/**
 * MQTT's Last-Will only fires on a clean broker-detected disconnect (keepalive
 * timeout). A device that loses power mid-transmission or sits behind a flaky
 * mobile signal can go silent without ever triggering LWT. Run this every
 * minute via the scheduler to catch those cases from our own last_seen_at.
 *
 * app/Console/Kernel.php:
 *   $schedule->command('devices:check-stale')->everyMinute();
 */
class CheckStaleDevices extends Command
{
    protected $signature = 'devices:check-stale {--minutes=2 : Mark offline if no status received in this window}';
    protected $description = 'Mark devices offline if no MQTT status received within the timeout window';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $stale = Device::where('is_online', true)
            ->where(function ($q) use ($cutoff) {
                $q->where('last_seen_at', '<', $cutoff)
                  ->orWhereNull('last_seen_at');
            })
            ->get();

        foreach ($stale as $device) {
            $device->update(['is_online' => false]);

            broadcast(new DeviceStatusUpdated($device, [
                'online' => false,
                'pins'   => [],
            ]));

            $this->info("Marked {$device->device_uid} offline (stale).");
        }

        return self::SUCCESS;
    }
}
