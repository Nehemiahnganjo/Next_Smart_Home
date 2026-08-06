# Laravel setup

## 1. Packages
```bash
composer require php-mqtt/laravel-client laravel/reverb laravel/sanctum
php artisan reverb:install
php artisan vendor:publish --tag=mqtt-config
```

## 2. .env
```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=motocontrol
REVERB_APP_KEY=replace_me
REVERB_APP_SECRET=replace_me
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=https

MQTT_HOST=your-broker.example.com
MQTT_PORT=8883
MQTT_USERNAME=laravel_bridge
MQTT_PASSWORD=replace_me
MQTT_CLIENT_ID=laravel-bridge
MQTT_USE_TLS=true
```
Use a broker with TLS (Mosquitto self-hosted with certbot cert, or HiveMQ Cloud free tier).
Create a dedicated MQTT user for Laravel with pub/sub rights on `devices/#`.
Give each ESP32 its own MQTT username/password scoped only to its own
`devices/{device_uid}/cmd` (sub) and `devices/{device_uid}/status` (pub) — set this
up as broker ACLs so one device can never touch another's topics.

## 3. Channel auth
`routes/channels.php` is included as-is — just make sure it's loaded (default
in a fresh Laravel install via `bootstrap/app.php` / `RouteServiceProvider`).

Mobile clients use a bearer token, not a browser session, so Laravel's default
`Broadcast::routes()` (session-based) won't authenticate them. Use the
included `/api/broadcasting/auth` route instead — already wired in
`routes/api.php` behind `auth:sanctum`. Point the Android Reverb client's
`authEndpoint` at that URL with the same `Authorization: Bearer` header used
for the rest of the API.

## 4. Register the DevicePolicy
In `app/Providers/AuthServiceProvider.php`:
```php
protected $policies = [
    \App\Models\Device::class => \App\Policies\DevicePolicy::class,
];
```

## 5. Migrate
```bash
php artisan migrate
```

## 6. Run the MQTT bridge (persistent process — use supervisor/systemd, not cron)
```bash
php artisan mqtt:listen
```
Example supervisor config:
```ini
[program:mqtt-listen]
command=php /var/www/app/artisan mqtt:listen
autostart=true
autorestart=true
user=www-data
```

## 7. Run Reverb
```bash
php artisan reverb:start
```

## 8. Schedule the stale-device check
LWT only fires on a clean broker-detected disconnect — a device that loses
power mid-transmission can go silent without ever triggering it. In
`app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('devices:check-stale')->everyMinute();
}
```
Requires the queue worker running too, since both this and command-timeout
detection go through the queue:
```bash
php artisan queue:work
```

## 9. Performance: skip the framework-boot tax on every command
Plain PHP-FPM re-bootstraps the entire Laravel framework on every request —
autoloading, service providers, routing, the works. For most APIs that's a
few tens of milliseconds and nobody notices. For a "tap a switch, watch the
relay click" interaction, that overhead is felt directly as lag between tap
and response.

**Laravel Octane** (Swoole or RoadRunner) keeps the app booted in memory
between requests and only re-runs your actual route logic:
```bash
composer require laravel/octane
php artisan octane:install --server=swoole   # or roadrunner
php artisan octane:start
```
This alone typically cuts API response time by 2-5x for a simple endpoint
like `/api/devices/{id}/command`. No code changes needed for this project —
just watch for singleton/static state that would leak between requests if
you add anything stateful later (Octane's docs cover this).

**Web server**: if you're behind nginx, enable HTTP/2 and keep connections
alive so the Android app isn't paying a fresh TLS handshake on every request:
```nginx
listen 443 ssl http2;
keepalive_timeout 65;
```

**Reverb**: already low-latency by design (persistent WebSocket, no
per-message HTTP overhead) — no tuning needed there beyond making sure it's
not sitting behind a proxy that buffers WebSocket frames.

## 10. CDN/edge in front of the domain
If the Laravel domain is served from far from Rumphi (most VPS regions are —
Europe/US), a CDN/edge proxy cuts the physical round-trip for the initial
connection. Cloudflare's free tier works for this:

- Proxy the API domain through Cloudflare (orange-cloud DNS record).
- **Reverb needs WebSockets explicitly allowed** — Cloudflare supports WS by
  default on proxied domains, but double-check under Network settings if
  connections seem to drop after ~100s (that's Cloudflare's default WS idle
  timeout, not a Reverb problem).
- Add a Page Rule / Cache Rule to bypass cache for `/api/*` — command
  responses are per-user and time-sensitive; caching them would serve stale
  device state. Only static assets (if any) should be cached at the edge.
- Cloudflare terminates TLS at the edge, so the handshake the Android app
  pays is against Cloudflare's nearby PoP, not your origin server directly —
  this is most of the actual latency win.

Skip this if the VPS is already regionally close (e.g. hosted in South
Africa) — the edge hop stops being worth the added complexity.

## 11. TLS session resumption on the MQTT broker
Without session resumption, every reconnect pays a full TLS handshake (2+
round-trips, more on a slow cellular link). Whether this is worth chasing
depends on which side you can actually control:

**Broker side** (Mosquitto): its OpenSSL backend handles session
caching/tickets at the library level automatically — nothing to toggle in
`mosquitto.conf`. What you do control is protocol version:
```conf
tls_version tlsv1.2
```
Keep it at 1.2 rather than forcing 1.3 for now — see the ESP32 caveat below.

**ESP32 side** — this is the honest caveat: the Arduino core's
`WiFiClientSecure` doesn't expose a public API to control mbedTLS session
resumption. Every `connect()` call negotiates a fresh handshake regardless
of what the broker offers. Actually resuming sessions would mean dropping
to ESP-IDF's mbedTLS APIs directly to save/restore the session state around
the client object — real work, not a config flag, and only worth it if
profiling shows reconnect frequency is actually a bottleneck (it usually
isn't, once WiFi power-save is off and the connection is otherwise stable).
`tls_version 1.2` on the broker still matters regardless, for straightforward
interop with the ESP32's mbedTLS stack.

If you're on HiveMQ Cloud or another managed broker instead of self-hosted
Mosquitto, none of the broker-side config applies — it's handled on their end.

## 12. Browser GUI (resources/views/dashboard.blade.php)
A session-authenticated web dashboard alongside the Android app — same
`DeviceController`, different auth boundary (session cookie via `routes/web.php`
instead of Sanctum bearer token via `routes/api.php`).

**No npm/Vite build required** — Tailwind, Alpine.js, and Pusher-js are all
loaded from CDN directly in `layouts/app.blade.php`. Fine for an internal
control panel; if this grows into something public-facing, switch to a
proper asset pipeline.

**Register the session-based broadcasting auth route** — separate from the
`/api/broadcasting/auth` route Android uses (that one's Sanctum/bearer-token
specific). Laravel 11+ doesn't auto-register the classic session-based
`/broadcasting/auth` route anymore. Add to `routes/channels.php`:
```php
Broadcast::routes(); // registers GET/POST /broadcasting/auth using the web session guard
```

**Create a user to log in with** (no registration page is built — this is
an internal tool, not a public app):
```bash
php artisan tinker
>>> \App\Models\User::create(['name' => 'Ghost', 'email' => 'you@example.com', 'password' => bcrypt('changeme')]);
```

**Env vars the Blade view reads directly** (`REVERB_APP_KEY` is meant to be
public — it's how Pusher-protocol clients identify which app to connect to,
not a secret):
```
REVERB_APP_KEY=...
REVERB_HOST=control.uniquesacco.mw
REVERB_PORT=443
REVERB_SCHEME=https
```

Visit `/login`, then `/dashboard`.

## Flow recap
Android → POST /api/devices/{id}/command → Laravel writes DeviceCommand (pending) →
publishes MQTT devices/{uid}/cmd → ESP32 executes, publishes devices/{uid}/status →
`mqtt:listen` updates DB, marks command acked, broadcasts DeviceStatusUpdated → Reverb →
Android WebSocket listener updates UI instantly.
