<?php

namespace App\Console\Commands;

use App\Events\DeviceStatusUpdated;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DevicePinState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

/**
 * Run as a persistent worker (supervisor/systemd), NOT via the scheduler:
 *   php artisan mqtt:listen
 *
 * Subscribes to devices/+/status (wildcard across all devices).
 * Expected payload from firmware:
 *   {"device_uid":"esp32-a1b2c3","online":true,"cmd_id":"...","result":"ok",
 *    "pins":[{"pin":26,"type":"digital_output","value":"1"}, ...]}
 *
 * LWT (last will) messages arrive as {"device_uid":"...","online":false} — broker
 * publishes this automatically if the ESP32 drops connection uncleanly.
 */
class MqttListen extends Command
{
    protected $signature = 'mqtt:listen';
    protected $description = 'Persistent MQTT subscriber bridging ESP32 device status to Reverb broadcasts';

    public function handle(): int
    {
        $backoffSeconds = 1;
        $maxBackoffSeconds = 30;

        // Outer loop: if the broker connection drops (network blip, broker
        // restart), reconnect with backoff instead of exiting and relying
        // entirely on the process supervisor to notice and restart — that
        // works, but each supervisor restart cycle is slower and noisier
        // than reconnecting in-process.
        while (true) {
            try {
                $mqtt = MQTT::connection();

                $mqtt->subscribe('devices/+/status', function (string $topic, string $message) {
                    $this->handleStatusMessage($topic, $message);
                }, qos: 1);

                $this->info('Listening on devices/+/status ...');
                $backoffSeconds = 1; // reset after a successful (re)connect

                $mqtt->loop(true);
            } catch (\Throwable $e) {
                Log::error('MQTT listener dropped, reconnecting', ['error' => $e->getMessage()]);
                $this->error("Connection lost: {$e->getMessage()} — retrying in {$backoffSeconds}s");
                sleep($backoffSeconds);
                $backoffSeconds = min($backoffSeconds * 2, $maxBackoffSeconds);
            }
        }
    }

    /**
     * Isolated per-message handler. A malformed payload, an unexpected DB
     * error, or a broadcast failure on one device's status update should
     * never be able to take down the subscriber for every other device.
     */
    private function handleStatusMessage(string $topic, string $message): void
    {
        try {
            $payload = json_decode($message, true);
            if (! is_array($payload) || empty($payload['device_uid'])) {
                $this->warn("Malformed payload on {$topic}: {$message}");
                return;
            }

            $device = Device::where('device_uid', $payload['device_uid'])->first();
            if (! $device) {
                $this->warn("Unknown device_uid: {$payload['device_uid']}");
                return;
            }

            $device->update([
                'is_online'    => $payload['online'] ?? true,
                'last_seen_at' => now(),
            ]);

            foreach ($payload['pins'] ?? [] as $pinState) {
                if (! isset($pinState['pin'])) {
                    continue; // malformed entry — skip it, don't abort the rest of the payload
                }
                DevicePinState::updateOrCreate(
                    ['device_id' => $device->id, 'pin' => $pinState['pin']],
                    [
                        'type'       => $pinState['type'] ?? 'unknown',
                        'label'      => $pinState['label'] ?? null,
                        'value'      => (string) ($pinState['value'] ?? ''),
                        'updated_at' => now(),
                    ]
                );
            }

            if (! empty($payload['cmd_id'])) {
                DeviceCommand::where('cmd_uuid', $payload['cmd_id'])->update([
                    'status'   => 'acked',
                    'result'   => $payload,
                    'acked_at' => now(),
                ]);
            }

            broadcast(new DeviceStatusUpdated($device, $payload));

            $this->info("Status from {$device->device_uid} processed.");
        } catch (\Throwable $e) {
            Log::error('Failed to process device status message', [
                'topic' => $topic, 'message' => $message, 'error' => $e->getMessage(),
            ]);
            $this->error("Error processing message on {$topic}: {$e->getMessage()}");
        }
    }
}
