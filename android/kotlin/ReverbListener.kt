package com.uniquesacco.devicecontrol.realtime

/*
 * Reverb speaks the Pusher protocol, so the official Pusher Android client works
 * unmodified. Add to app/build.gradle:
 *   implementation("com.pusher:pusher-java-client:2.4.4")
 *
 * Private channels need an auth endpoint on Laravel — Sanctum + broadcasting
 * auth route (routes/api.php): Broadcast::routes() equivalent for API guards,
 * or a custom /api/broadcasting/auth route protected by auth:sanctum.
 */

import com.google.gson.Gson
import com.pusher.client.Pusher
import com.pusher.client.PusherOptions
import com.pusher.client.channel.PrivateChannel
import com.pusher.client.channel.PrivateChannelEventListener
import com.pusher.client.connection.ConnectionEventListener
import com.pusher.client.connection.ConnectionStateChange
import com.pusher.client.util.HttpAuthorizer

data class DeviceStatusEvent(
    val device_id: Int,
    val device_uid: String,
    val is_online: Boolean,
    val pins: List<Map<String, Any>>,
    val cmd_id: String?,
    val result: Any?,
    val at: String
)

/** High-level connection state surfaced to the UI so it can show a banner/dot rather than silently going stale. */
enum class RealtimeState { CONNECTING, CONNECTED, DISCONNECTED, AUTH_FAILED }

class ReverbListener(
    private val reverbHost: String,     // e.g. "control.uniquesacco.mw"
    private val reverbPort: Int,        // 8080 or your TLS port
    private val appKey: String,
    private val authEndpoint: String,   // "https://control.uniquesacco.mw/api/broadcasting/auth"
    private val bearerToken: String,
    private val userId: Int,
    private val onStatusUpdate: (DeviceStatusEvent) -> Unit,
    private val onStateChange: (RealtimeState) -> Unit = {}
) {
    private var pusher: Pusher? = null
    private var channel: PrivateChannel? = null
    private val gson = Gson()

    // The pusher-java-client library already auto-reconnects on transient
    // drops, but if it lands in DISCONNECTED without recovering (e.g. after
    // an auth failure once the token rotates) we force a clean reconnect
    // rather than leaving the app silently offline.
    private var manuallyDisconnected = false

    fun connect() {
        manuallyDisconnected = false
        onStateChange(RealtimeState.CONNECTING)

        val authorizer = HttpAuthorizer(authEndpoint).apply {
            setHeaders(mapOf("Authorization" to "Bearer $bearerToken", "Accept" to "application/json"))
        }

        val options = PusherOptions()
            .setCluster("mt1") // unused by Reverb but required by the client
            .setHost(reverbHost)
            .setWsPort(reverbPort)
            .setWssPort(reverbPort)
            .setUseTLS(true)
            .setAuthorizer(authorizer)

        pusher = Pusher(appKey, options)

        pusher?.connect(object : ConnectionEventListener {
            override fun onConnectionStateChange(change: ConnectionStateChange) {
                when (change.currentState.toString()) {
                    "CONNECTED" -> onStateChange(RealtimeState.CONNECTED)
                    "DISCONNECTED" -> {
                        onStateChange(RealtimeState.DISCONNECTED)
                        if (!manuallyDisconnected) retryConnect()
                    }
                    else -> onStateChange(RealtimeState.CONNECTING)
                }
            }

            override fun onError(message: String?, code: String?, e: Exception?) {
                onStateChange(RealtimeState.DISCONNECTED)
                if (!manuallyDisconnected) retryConnect()
            }
        })

        channel = pusher?.subscribePrivate(
            "private-user.$userId.devices",
            object : PrivateChannelEventListener {
                override fun onEvent(event: com.pusher.client.channel.PusherEvent) {
                    if (event.eventName == "device.status") {
                        val parsed = gson.fromJson(event.data, DeviceStatusEvent::class.java)
                        onStatusUpdate(parsed)
                    }
                }
                override fun onSubscriptionSucceeded(channelName: String?) {}
                override fun onAuthenticationFailure(message: String?, e: Exception?) {
                    // Usually a stale/expired bearer token — surface this distinctly
                    // so the app can refresh the token and reconnect, rather than
                    // treating it the same as a plain network drop.
                    onStateChange(RealtimeState.AUTH_FAILED)
                }
            },
            "device.status"
        )
    }

    private fun retryConnect() {
        android.os.Handler(android.os.Looper.getMainLooper()).postDelayed({
            if (!manuallyDisconnected) connect()
        }, 3000)
    }

    fun disconnect() {
        manuallyDisconnected = true
        pusher?.disconnect()
        onStateChange(RealtimeState.DISCONNECTED)
    }
}
