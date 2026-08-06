# Android Integration

Kotlin + Jetpack Compose. Drop these files into your project.

## Files

| File | What it does |
|---|---|
| `ApiClient.kt` | OkHttp + Retrofit setup. One shared instance with HTTP/2 and a connection pool so command taps don't pay a fresh TLS handshake every time. |
| `DeviceApi.kt` | Retrofit interface: list devices, register, send command, poll command status. |
| `ReverbListener.kt` | WebSocket client using the Pusher protocol. Connects to Laravel Reverb, subscribes to `private-user.{id}.devices`. |
| `DeviceControlExample.kt` | Usage snippets — init the API client, send a command, listen for status events. |
| `dashboard/DashboardViewModel.kt` | UI state: device list, live pin values, optimistic updates, realtime event handling. |
| `dashboard/DashboardScreen.kt` | Compose UI: device cards, relay toggles, PWM sliders, sensor readouts, connection badge. |

## Optimistic UI

When you tap a toggle or move a slider the UI updates immediately — it does not wait for the round-trip. The ViewModel tracks each pending command separately. When the WebSocket delivers the real device state back:

- Matches the optimistic value → nothing visible happens, it was already right
- Command failed, timed out, or was rejected → the pin reverts to its previous value

A failed command is always visible to the user within 8 seconds, never silently stuck.

## Authentication

After Sanctum login store the token in `EncryptedSharedPreferences` and set `ApiClient.authToken`. All requests include `Authorization: Bearer {token}` automatically via the OkHttp interceptor. The Reverb WebSocket uses the same token — point `authEndpoint` at `/api/broadcasting/auth`.

## Requirements

- Kotlin 1.9+, Jetpack Compose
- Retrofit 2.x, OkHttp 4.x
- Pusher-Java client (for Reverb WebSocket)
