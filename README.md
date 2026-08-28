# Next Smart Home

Control your home remotely from your phone. Flip a relay, dim a light, read a sensor — the tap on your screen reaches the ESP32 in under a second from anywhere in the world.

Built by **Nehemiah Ng'anjo** for **Nextlinkmw**, Mzuzu, Malawi.

---

## What it is

Three things working together:

- An **ESP32** sits in your home, wired to whatever you want to control — relay boards, PWM motors, analog sensors. It dials out to an MQTT broker over TLS and waits for commands.
- A **Laravel server** sits in the middle. Your phone talks to it over REST and WebSockets. It bridges commands to the ESP32 via MQTT and pushes real-time status updates back.
- An **Android app** is what you actually use. Tap a switch, slide a PWM bar, watch the sensor readings update live.

The architecture solves a real problem: ESP32s behind home NAT can't accept inbound connections. The server is the only thing that's publicly reachable — everything else connects out to it.

```
Android  ──REST (Sanctum)──►  Laravel  ──MQTT publish──►  Broker  ──►  ESP32
Android  ◄──WebSocket (Reverb)──  Laravel  ◄──MQTT subscribe──  Broker  ◄──  ESP32
```

---

## What you can control

Each ESP32 declares its own pin layout in the firmware — the protocol is generic, so the same code handles all of these:

| Type | Example use | Command |
|---|---|---|
| `digital_output` | Relay, LED, gate | `digital_write` with `"0"` or `"1"` |
| `pwm` | Motor speed, dimmable light | `pwm_write` with `0`–`255` |
| `analog_input` | Soil moisture, temperature | `analog_read` |
| `digital_input` | Door sensor, button | `digital_read` |

Adding a pin is one line in the firmware's `PINS[]` array and one entry in Laravel's `pin_config` — no protocol changes, no re-flashing other devices.

---

## Repository layout

```
Next_Smart_Home/
├── firmware/
│   ├── esp32_controller/
│   │   └── esp32_controller.ino    Main Arduino sketch
│   ├── secrets.example.h           Copy → secrets.h, fill in credentials, never commit
│   └── .gitignore                  Keeps secrets.h out of git
│
├── laravel/
│   ├── app/
│   │   ├── Http/Controllers/Api/   DeviceController — list, register, command
│   │   ├── Http/Controllers/Web/   Browser dashboard (login + control panel)
│   │   ├── Models/                 Device, DeviceCommand, DevicePinState
│   │   ├── Events/                 DeviceStatusUpdated (Reverb broadcast)
│   │   ├── Jobs/                   CheckCommandTimeout, CheckStaleDevices
│   │   ├── Console/Commands/       mqtt:listen (persistent MQTT bridge)
│   │   └── Policies/               DevicePolicy (users can only control their own)
│   ├── database/migrations/        devices, device_commands, device_pin_states
│   ├── resources/views/            Blade dashboard + auth (no npm/Vite needed)
│   ├── routes/
│   │   ├── api.php                 Sanctum-guarded REST + broadcasting auth
│   │   └── web.php                 Session-guarded browser dashboard
│   └── SETUP.md                    Full step-by-step deployment guide
│
└── android/kotlin/
    ├── ApiClient.kt                Retrofit + OkHttp setup (HTTP/2, connection pool)
    ├── DeviceApi.kt                Retrofit interface for all endpoints
    ├── ReverbListener.kt           WebSocket client for live device status
    ├── DeviceControlExample.kt     Usage snippets
    └── dashboard/
        ├── DashboardViewModel.kt   State, commands, optimistic UI, realtime events
        └── DashboardScreen.kt      Compose UI — device cards, toggles, PWM sliders
```

---

## How a command works end to end

1. You tap a relay switch in the Android app.
2. The switch flips immediately in the UI — this is optimistic. The app doesn't wait for confirmation.
3. A `POST /api/devices/{id}/command` goes to Laravel.
4. Laravel validates the action and value (`digital_write` must be `"0"` or `"1"`), creates a `DeviceCommand` record, and publishes a JSON message to `devices/{uid}/cmd` on the MQTT broker.
5. The ESP32 receives the message, executes it, and publishes a response to `devices/{uid}/status`.
6. Laravel's `mqtt:listen` process picks up the status, updates the database, and broadcasts a `DeviceStatusUpdated` event over Reverb.
7. The Android app receives the WebSocket event and reconciles the live state. If it matches the optimistic value — nothing visible happens, it was already right. If the command failed or timed out — the switch reverts.

If the broker is unreachable at step 4, Laravel marks the command `failed` and returns a 503 immediately instead of hanging. The optimistic UI reverts on the first error response.

---

## Firmware

The sketch lives in `firmware/esp32_controller/esp32_controller.ino`. A few things worth knowing before you flash it:

**Credentials never go in the sketch.** Copy `secrets.example.h` to `secrets.h` in the same folder, fill in your WiFi SSID, MQTT host, device UID, and broker CA certificate. `secrets.h` is gitignored — it never leaves your machine.

**Pins are declared at the top of the sketch** in the `PINS[]` array. Add or remove entries there. The firmware checks at boot that you haven't accidentally mapped a command to an ADC-only pin (GPIO 34–39 on the ESP32 can't be outputs — the code refuses to run rather than silently doing nothing) and warns about strapping pins that can affect boot behaviour.

**Every output defaults LOW at boot.** Before any command arrives, all relays are off. A board reset or power cut never leaves something in an unknown state.

**The hardware watchdog is always running** (20-second timeout). If something in the loop hangs — a bad TLS state, a blocking call in future code — the board reboots rather than freezing indefinitely. Long delays are chunked so they don't accidentally trip it.

**If the MQTT broker stays unreachable for 10 minutes straight**, the ESP32 restarts completely. A retry loop alone can't recover from certain corrupted TLS or socket states. A clean restart usually fixes it.

**WiFi power-save is disabled.** Modem sleep adds 100–300 ms of jitter to every packet, which is felt directly as lag between a tap and a relay click. This device is mains-powered, so there's no reason to trade responsiveness for battery savings.

---

## Laravel

The Laravel side is intentionally minimal — just the pieces this project needs, not a full application skeleton. See `laravel/SETUP.md` for the full deployment walk-through including packages, MQTT config, Reverb, Sanctum, a browser dashboard, and performance notes (Octane, HTTP/2, optional CDN).

The important bits to know:

**`mqtt:listen`** is a long-running artisan command, not a cron job. It maintains a persistent MQTT connection and processes status messages as they arrive. Run it under supervisor or systemd — if it exits, the Laravel→ESP32 link goes dark. It reconnects automatically on a broker drop rather than dying.

**Commands that fail reach the client as a 503**, not a 500. If the broker is unreachable when a command is sent, Laravel marks it `failed` immediately and tells the Android app. The command isn't left `pending` forever.

**Device policy** means users can only see and control their own devices. Accessing another user's device returns 403.

**`secret_hash` is never serialised into API responses.** It used to be — it was missing from `$hidden` in the Device model. It's a bcrypt hash of the MQTT provisioning secret, meaningless to the app but something that shouldn't be sitting in every API response.

**The browser dashboard** (`/dashboard`) is a Blade page that talks to the same `DeviceController` as the Android app. No npm or build step needed — Tailwind and Alpine.js load from CDN. Useful for quick access from a desktop without the Android app.

---

## Android

The Android side is Kotlin + Jetpack Compose + Retrofit. The files in `android/kotlin/` are the integration layer — drop them into your project and wire them up.

**`ApiClient`** sets up OkHttp with an HTTP/2 connection pool and a `Bearer` token interceptor. One warm connection handles bursts of commands without paying a TLS handshake on every tap.

**`ReverbListener`** connects to the Reverb WebSocket using the Pusher protocol. It subscribes to `private-user.{id}.devices` and calls back your code whenever a device status event arrives.

**`DashboardViewModel`** manages the full UI state — device list, live online/offline status, per-pin values, and the optimistic layer. When you send a command, the UI updates immediately and independently tracks whether the confirmation has come back. If the command errors or times out, the affected pin reverts visually instead of staying in the wrong state.

**`DashboardScreen`** renders device cards with relay toggles, PWM sliders, sensor readouts, and a live connection badge. It reads entirely from the ViewModel state — no API calls from the UI layer.

---

## Security checklist

Before deploying:

- [ ] MQTT over TLS (port 8883), broker CA certificate pinned in firmware
- [ ] Per-device MQTT credentials — each ESP32 has its own username/password, scoped by broker ACL to only its own `devices/{uid}/` topics
- [ ] Sanctum token authentication on all `/api/` routes
- [ ] Private Reverb channel scoped per user (`private-user.{id}.devices`)
- [ ] Rate-limit `/api/devices/{id}/command` at the nginx/middleware level
- [ ] `secrets.h` never committed to git

---

## Quick start

### 1. MQTT broker
Set up a broker with TLS. Mosquitto on a VPS with a Let's Encrypt cert works fine. Create one user per device with ACL restricting it to `devices/{uid}/#`. Create a separate `laravel_bridge` user with access to `devices/#`.

### 2. Laravel
```bash
cd laravel
composer install
cp .env.example .env   # fill in DB, MQTT, Reverb settings
php artisan migrate
php artisan mqtt:listen &   # persistent — use supervisor in production
php artisan reverb:start &
```

### 3. Firmware
```bash
cp firmware/secrets.example.h firmware/esp32_controller/secrets.h
# edit secrets.h — WiFi, MQTT host, device UID, CA cert
# open esp32_controller.ino in Arduino IDE
# edit PINS[] for your hardware
# flash to your ESP32-WROOM-32
```

### 4. Register the device
```bash
curl -X POST https://your-server/api/devices \
  -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Living Room","device_uid":"esp32-a1b2c3","pin_config":[{"pin":26,"type":"digital_output","label":"relay1"}]}'
```

The response includes a one-time `device_secret` — use it as the MQTT password for that device.

### 5. Android
Point `ApiClient.create()` at your Laravel URL, set `authToken` after Sanctum login, and wire `ReverbListener` to your Reverb host. See `android/kotlin/DeviceControlExample.kt` for usage snippets.

---

## Requirements

| Component | Requirement |
|---|---|
| ESP32 | WROOM-32 or any ESP32 with WiFi, Arduino core v3.x |
| Laravel | PHP 8.2+, Laravel 11+, `php-mqtt/laravel-client`, `laravel/reverb`, `laravel/sanctum` |
| Android | Kotlin, Jetpack Compose, Retrofit, OkHttp, Pusher-Java client |
| MQTT broker | Any broker with TLS support (Mosquitto, HiveMQ, etc.) |

---

## License

Proprietary. See [LICENSE](LICENSE).

© 2026 Nextlinkmw. Built by Nehemiah Ng'anjo.

---

## Support

This is free and open-source software. Use it, fork it, ship it — no strings attached.

If it saved you time, made you money, or you just think it was a solid piece of work — a coffee goes a long way.

[![Support via PayPal](https://img.shields.io/badge/Support-PayPal-0070ba?style=for-the-badge&logo=paypal&logoColor=white)](https://paypal.me/Nextlinkmw)

No pressure. But appreciated.

---

## License

MIT — free to use, modify, and distribute. See [LICENSE](LICENSE).

