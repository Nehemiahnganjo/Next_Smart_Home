package com.uniquesacco.devicecontrol

import com.uniquesacco.devicecontrol.api.ApiClient
import com.uniquesacco.devicecontrol.api.DeviceCommandRequest
import com.uniquesacco.devicecontrol.realtime.ReverbListener
import kotlinx.coroutines.launch
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers

class DeviceControlExample(baseUrl: String, currentUserId: Int, bearerToken: String) {

    private val api = ApiClient.apply { authToken = bearerToken }.create(baseUrl)

    private val reverb = ReverbListener(
        reverbHost = "control.uniquesacco.mw",
        reverbPort = 443,
        appKey = "replace_me",
        authEndpoint = "$baseUrl/api/broadcasting/auth",
        bearerToken = bearerToken,
        userId = currentUserId
    ) { event ->
        // Fires the moment the ESP32 acks over MQTT -> Laravel -> Reverb -> here.
        // Update your ViewModel / Compose state with event.pins, event.is_online, etc.
        println("Device ${event.device_uid} online=${event.is_online} pins=${event.pins}")
    }

    fun start() {
        reverb.connect()
    }

    fun toggleRelay(deviceId: Int, pin: Int, turnOn: Boolean) {
        CoroutineScope(Dispatchers.IO).launch {
            val response = api.sendCommand(
                deviceId,
                DeviceCommandRequest(
                    action = "digital_write",
                    pin = pin,
                    value = if (turnOn) "1" else "0"
                )
            )
            // response.body()?.status will be "pending" here — real confirmation
            // comes via the Reverb event above once the ESP32 acks.
        }
    }

    fun setMotorSpeed(deviceId: Int, pin: Int, duty0to255: Int) {
        CoroutineScope(Dispatchers.IO).launch {
            api.sendCommand(
                deviceId,
                DeviceCommandRequest(action = "pwm_write", pin = pin, value = duty0to255.toString())
            )
        }
    }

    fun stop() {
        reverb.disconnect()
    }
}
