# ESP32 ↔ Laravel ↔ Android — Full Control Stack

## Architecture
```
Android App --REST(Sanctum)--> Laravel API --MQTT publish--> Broker --> ESP32
Android App <--WebSocket(Reverb)-- Laravel <--MQTT subscribe-- Broker <-- ESP32
```

ESP32 sits behind mobile-network NAT, so it can never accept inbound
connections — it always dials out to the MQTT broker. Laravel is the only
thing the Android app talks to directly; Laravel bridges to MQTT.

## Contents
- `laravel/` — migrations, models, API controller, MQTT bridge (`mqtt:listen`), Reverb event, and now a session-authenticated browser dashboard (`resources/views/dashboard.blade.php`) alongside the Android API. See `laravel/SETUP.md`.
- `firmware/esp32_controller.ino` — Arduino sketch: multi-pin digital/PWM/analog control over MQTT+TLS, LWT for online/offline detection, JSON command protocol.
- `android/kotlin/` — Retrofit API client, Reverb (Pusher-protocol) live listener, example usage.
- `android/kotlin/dashboard/` — Compose home-automation dashboard: `DashboardViewModel` (device list, live pin state, optimistic-pending tracking) + `DashboardScreen` (device cards, relay switches, PWM sliders, sensor readouts, live connection badge).

## Command protocol (MQTT JSON)
**Laravel → ESP32** on `devices/{device_uid}/cmd`:
```json
{"cmd_id": "uuid", "action": "digital_write", "pin": 26, "value": "1"}
```
Actions: `digital_write`, `pwm_write`, `analog_read`, `digital_read`, `get_status`

**ESP32 → Laravel** on `devices/{device_uid}/status`:
```json
{
  "device_uid": "esp32-a1b2c3",
  "online": true,
  "cmd_id": "uuid",
  "result": "ok",
  "pins": [{"pin": 26, "type": "digital_output", "label": "relay1", "value": 1}]
}
```

## Setup order
1. Stand up an MQTT broker with TLS (Mosquitto self-hosted or HiveMQ Cloud free tier). Create per-device ACL users so device A can't touch device B's topics.
2. `laravel/SETUP.md` — install packages, migrate, run `mqtt:listen` + `reverb:start`.
3. `firmware/secrets.example.h` → copy to `firmware/secrets.h`, fill in WiFi/broker/device credentials (gitignored, never committed). Flash `firmware/esp32_controller.ino`.
4. Register the device via `POST /api/devices` (returns the device secret used for MQTT ACL provisioning).
5. Wire the Android snippets into your app: Retrofit for commands, Reverb listener for live acks, `dashboard/` for the actual control UI.

## Firmware hardening (esp32_controller.ino)
- **Fail-safe boot**: every output pin defaults LOW on startup — a reboot never leaves a relay in an unknown state.
- **GPIO sanity checks at boot**: refuses to run if a `digital_output`/`pwm` pin is one of the input-only ADC pins (34–39); warns on strapping pins (0, 2, 12, 15) that can affect boot if something external holds them.
- **Strict value parsing**: commands use `strtol` with error checking instead of `atoi`, which silently returns 0 on garbage — previously a malformed `value` could quietly turn a relay off instead of erroring.
- **Actuation rate limiting**: 250ms minimum between writes to the same output pin, so a buggy client or a replayed command can't chatter a relay or gate motor.
- **Duplicate-command guard**: MQTT QoS 1 can redeliver a message; the last executed `cmd_id` is tracked so a duplicate doesn't re-fire.
- **Payload size guard**: command payloads over 256 bytes are rejected before parsing.
- **Watchdog-safe delays**: all blocking waits (WiFi/MQTT backoff) are chunked so they can't outrun the hardware watchdog and cause a mid-retry reboot.
- **Self-healing restart**: if MQTT stays unreachable for 10 minutes straight, the device restarts outright rather than looping in potentially corrupted TLS/socket state forever.
- **Secrets out of source**: WiFi/MQTT/CA cert live in a gitignored `secrets.h`, not the sketch itself.

Not yet covered, worth adding for a more permanent deployment: OTA updates (so reflashing doesn't need physical access), NVS flash encryption for stored credentials (currently cleartext on the flash chip — fine unless someone has physical access to desolder/read it), and captive-portal WiFi provisioning instead of a hardcoded SSID/password.

## Laravel hardening
- **Fixed a real leak**: `Device` had no `$hidden`, so every device list/register response was serializing `secret_hash` (the bcrypt hash of the MQTT provisioning secret) straight into the JSON the Android app received. Hidden now, along with the redundant `user_id`.
- **MQTT publish failures no longer 500**: if the broker is unreachable when a command is sent, the command is marked `failed` and the API returns a clean 503 instead of an unhandled exception — previously a broker blip would crash the request and leave the command stuck `pending` forever.
- **Per-action value validation**: `digital_write` now requires literally `"0"`/`"1"`, `pwm_write` requires 0–255, checked server-side before anything reaches MQTT — catches a bad request instantly with a 422 instead of burning a rate-limited command slot on something the firmware would've rejected anyway.
- **`mqtt:listen` survives a broker drop**: previously a lost connection killed the whole persistent process and depended entirely on the process supervisor noticing and restarting it. Now it reconnects in-process with backoff. A bad/malformed message from one device is also isolated per-message so it can't take down status processing for every other device.

## Making it feel fast
Three separate latency sources, fixed independently:
- **ESP32 → broker**: WiFi power-save disabled (`WiFi.setSleep(false)`) — default modem sleep adds 100-300ms of jitter to every packet, felt as lag between tapping a switch and the relay clicking. Static IP option skips DHCP negotiation (1-3s) on boot/reconnect. Persistent MQTT session (`cleanSession=false`) avoids a resubscribe round-trip after reconnecting.
- **Laravel → API**: `SETUP.md` §9 covers Laravel Octane, which keeps the framework booted in memory instead of re-bootstrapping it on every request. §10 covers optionally putting Cloudflare in front of the domain if the VPS is far from Rumphi — terminates TLS at a nearby edge PoP instead of the origin, which is most of the actual win; skip it if the VPS is already regionally close.
- **Android UI**: switches and sliders now update optimistically the instant you tap them (`DashboardViewModel.optimisticValues`), reconciling with the real device state once the ack arrives over Reverb, and reverting if the command errors or times out (now 8s instead of 15s, since a revert needs to feel prompt too). OkHttp reuses a warm HTTP/2 connection instead of renegotiating TLS on every request.

TLS session resumption (§11 in SETUP.md) turned out to be a dead end on the ESP32 side — the Arduino core's `WiFiClientSecure` doesn't expose an API for it, so that one stays broker-config-only rather than an actual round-trip savings on the device.

## Extending pin control
Add entries to the `PINS[]` array in the firmware and to a device's `pin_config`
in Laravel — no protocol changes needed, the JSON schema is generic across
digital/PWM/analog pins.

## Security checklist
- [ ] MQTT over TLS (8883), broker CA pinned in firmware
- [ ] Per-device MQTT credentials + ACLs (device can only pub/sub its own topics)
- [ ] Sanctum tokens for Android API auth
- [ ] Private Reverb channel scoped per user (`user.{id}.devices`)
- [ ] Rate-limit `/api/devices/{id}/command` to prevent command flooding
