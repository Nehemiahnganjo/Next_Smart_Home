# Laravel API Reference

## Authentication

All `/api/` routes require a Sanctum bearer token:
```
Authorization: Bearer {token}
```

## Endpoints

### Devices

| Method | URL | Description |
|---|---|---|
| `GET` | `/api/devices` | List your devices with latest pin states |
| `POST` | `/api/devices` | Register a new device — returns a one-time `device_secret` to flash into firmware |
| `POST` | `/api/devices/{id}/command` | Send a command to a device |
| `GET` | `/api/devices/{id}/commands/{cmd}` | Poll a command's ack status (fallback if not using WebSocket) |

### Commands

**Request body for `POST /api/devices/{id}/command`:**

```json
{
  "action": "digital_write",
  "pin": 26,
  "value": "1"
}
```

| Action | Pin required | Value |
|---|---|---|
| `digital_write` | Yes | `"0"` or `"1"` only |
| `pwm_write` | Yes | `"0"` – `"255"` |
| `digital_read` | Yes | — |
| `analog_read` | Yes | — |
| `get_status` | No | — |

**Response:** `202 Accepted` with the command record while pending.

If the MQTT broker is unreachable: `503 Service Unavailable` — command marked `failed`, never left `pending`.

### WebSocket (Reverb)

Connect to the private channel `private-user.{id}.devices` using the Pusher protocol. Authenticate via `POST /api/broadcasting/auth` with your bearer token.

Event: `DeviceStatusUpdated`

```json
{
  "device_id": 3,
  "is_online": true,
  "at": "2026-08-06T21:00:00Z",
  "pins": [
    {"pin": 26, "type": "digital_output", "label": "relay1", "value": 1}
  ]
}
```

## Error responses

| Code | Meaning |
|---|---|
| `401` | Missing or expired Sanctum token |
| `403` | Device belongs to another user |
| `422` | Validation failed — bad action, pin out of range, or value not valid for the action |
| `503` | MQTT broker unreachable at publish time |
