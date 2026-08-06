<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CheckCommandTimeout;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpMqtt\Client\Facades\MQTT;

class DeviceController extends Controller
{
    /** List the authenticated user's devices with last known pin states. */
    public function index(Request $request)
    {
        return $request->user()->devices()
            ->with('pinStates')
            ->orderBy('name')
            ->get();
    }

    /** Register a new ESP32 device. Returns a one-time secret to flash into firmware. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'device_uid' => 'required|string|max:64|unique:devices,device_uid',
            'pin_config' => 'nullable|array', // [{pin, type, label}]
        ]);

        $secret = Str::random(40);

        $device = $request->user()->devices()->create([
            'device_uid'  => $data['device_uid'],
            'name'        => $data['name'],
            'secret_hash' => Hash::make($secret),
            'pin_config'  => $data['pin_config'] ?? [],
        ]);

        return response()->json([
            'device' => $device,
            // Shown once. Flash this + device_uid into the ESP32 firmware for MQTT auth.
            'device_secret' => $secret,
        ], 201);
    }

    /** Queue a command and publish it over MQTT to the device. */
    public function sendCommand(Request $request, Device $device)
    {
        $this->authorize('command', $device);

        $data = $request->validate([
            'action' => 'required|in:digital_write,pwm_write,analog_read,digital_read,get_status',
            'pin'    => 'required_unless:action,get_status|integer|min:0|max:39',
            'value'  => 'nullable|string|max:16',
        ]);

        // The generic 'value' field above is just a string — validate its
        // meaning per-action here so a bad value gets a 422 with a clear
        // message instead of reaching the ESP32 as an opaque MQTT payload
        // (the firmware's own strict parsing would reject it anyway, but
        // failing here is instant instead of costing a round-trip and,
        // worse, silently eating one of the rate-limited command slots).
        if ($data['action'] === 'digital_write' && ! in_array($data['value'] ?? null, ['0', '1'], true)) {
            throw ValidationException::withMessages([
                'value' => 'digital_write requires value "0" or "1".',
            ]);
        }
        if ($data['action'] === 'pwm_write') {
            $v = $data['value'] ?? null;
            if (! is_numeric($v) || (int) $v < 0 || (int) $v > 255) {
                throw ValidationException::withMessages([
                    'value' => 'pwm_write requires a numeric value between 0 and 255.',
                ]);
            }
        }

        $cmd = DeviceCommand::create([
            'cmd_uuid'  => (string) Str::uuid(),
            'device_id' => $device->id,
            'issued_by' => $request->user()->id,
            'action'    => $data['action'],
            'pin'       => $data['pin'] ?? null,
            'value'     => isset($data['value']) ? (string) $data['value'] : null,
            'status'    => 'pending',
        ]);

        try {
            MQTT::publish($device->cmdTopic(), json_encode([
                'cmd_id' => $cmd->cmd_uuid,
                'action' => $cmd->action,
                'pin'    => $cmd->pin,
                'value'  => $cmd->value,
            ]), qos: 1);
        } catch (\Throwable $e) {
            // Broker unreachable, TLS handshake failed, etc. Without this,
            // the exception bubbles up as a raw 500 and the command is left
            // 'pending' forever with nothing to ever resolve it — the
            // Android app would sit on an optimistic UI update indefinitely.
            Log::error('MQTT publish failed for command', [
                'cmd_uuid' => $cmd->cmd_uuid, 'device_uid' => $device->device_uid, 'error' => $e->getMessage(),
            ]);
            $cmd->update(['status' => 'failed', 'result' => ['error' => 'broker_unreachable']]);

            return response()->json([
                'message' => 'Could not reach the command broker. The device was not contacted.',
                'command' => $cmd,
            ], 503);
        }

        // If the device never acks (offline, dead, lost packet), flip this
        // to 'timeout' after 8s instead of leaving the app waiting forever.
        // Kept short because a timeout now visibly reverts an optimistic UI
        // update — a 15s wait before that revert would feel broken, not safe.
        CheckCommandTimeout::dispatch($cmd->id)->delay(now()->addSeconds(8));

        return response()->json($cmd, 202);
    }

    /** Poll a command's ack status (fallback for clients not using the WebSocket). */
    public function commandStatus(Request $request, Device $device, DeviceCommand $command)
    {
        $this->authorize('view', $device);
        abort_unless($command->device_id === $device->id, 404);

        return $command;
    }
}
