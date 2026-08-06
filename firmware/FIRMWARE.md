# ESP32 Firmware

## Hardware requirements
- ESP32-WROOM-32 (or any ESP32 with WiFi)
- Arduino ESP32 core v3.x (ESP-IDF v5 based)
- Libraries: PubSubClient, ArduinoJson v7+, WiFiClientSecure (bundled)

## First time setup

1. Copy `secrets.example.h` to `secrets.h` in the `esp32_controller/` folder
2. Fill in your WiFi credentials, MQTT broker host/port, device UID, and broker CA certificate
3. Edit the `PINS[]` array at the top of the sketch to match your wiring
4. Flash to your board

`secrets.h` is gitignored — it never gets committed.

## Pin types

| Type | GPIO direction | Use for |
|---|---|---|
| `digital_output` | Output | Relays, LEDs, any on/off load |
| `pwm` | Output (LEDC) | Motor speed, dimmable loads, 0–255 duty |
| `digital_input` | Input | Door sensors, buttons, reed switches |
| `analog_input` | ADC input | Soil moisture, light level, NTC temperature |

**Note:** GPIO 34–39 are input-only ADC pins on the ESP32. The firmware refuses to boot if you try to map them as outputs.

## Command protocol

Commands arrive as JSON on `devices/{device_uid}/cmd`:

```json
{"cmd_id": "uuid", "action": "digital_write", "pin": 26, "value": "1"}
```

Status responses publish to `devices/{device_uid}/status`:

```json
{
  "device_uid": "esp32-a1b2c3",
  "online": true,
  "cmd_id": "uuid",
  "result": "ok",
  "pins": [{"pin": 26, "type": "digital_output", "label": "relay1", "value": 1}]
}
```

## Safety features

- All outputs default LOW at boot — a reset never leaves a relay in an unknown state
- Hardware watchdog (20s) — board reboots if loop() hangs for any reason
- 250ms minimum between actuations of the same pin — protects relay contacts from rapid chatter
- Duplicate command guard — QoS 1 can redeliver; the last `cmd_id` is tracked to avoid double-firing
- 10-minute MQTT reconnect limit — reboots if the broker stays unreachable, clearing any corrupted TLS state
- GPIO sanity checks at boot — refuses to run with a wiring configuration that would silently fail
