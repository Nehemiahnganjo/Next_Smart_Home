# Android Integration

Kotlin + Jetpack Compose. Drop these files into your project.

## Files

| File | What it does |
|---|---|
| `ApiClient.kt` | OkHttp + Retrofit setup. One shared instance with HTTP/2 and a connection pool so command taps don't pay a fresh TLS handshake every time. |
| `DeviceApi.kt` | Retrofit interface: list devices, register, send command, poll command status. |
| `ReverbListener.kt` | WebSocket client using the Pusher protocol. Connects to Laravel Reverb and subscribes to `private-user.{id}.devices`. |
| `DeviceControlExample.kt` | Usage snippets — how to init the API client, send a command, and listen for status events. |
| `dashboard/DashboardViewModel.kt` | Full UI state: device list, live pin values, optimistic updates, and WebSocket events. |
| `dashboard/DashboardScreen.kt` | Compose UI: device cards, relay toggles, PWM sliders, sensor readouts, connection badge. |

## Optimistic UI

When you tap a relay toggle or move a PWM slider, the UI updates immediately — it does not wait for the round-trip confirmation. This is what makes the app feel instant rather than laggy.

The ViewModel tracks pending commands separately. When the WebSocket delivers the real device state back:
- If it matches the optimistic value → nothing visible happens
- If the command failed, timed out, or was rejected → the pin reverts to its previous value

This means a failed command is always visible to the user within 8 seconds, not silently stuck.

## Authentication

After Sanctum login, store the token in `EncryptedSharedPreferences` and set `ApiClient.authToken`. All subsequent requests include `Authorization: Bearer {token}` automatically via the interceptor.

The Reverb WebSocket uses the same token for channel authentication — point the `authEndpoint` at `/api/broadcasting/auth`.

## Requirements

- Kotlin 1.9+
- Jetpack Compose
- Retrofit 2.x
- OkHttp 4.x
- Pusher-Java client (for Reverb WebSocket)
