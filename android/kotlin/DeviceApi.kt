package com.uniquesacco.devicecontrol.api

import retrofit2.Response
import retrofit2.http.*

data class PinState(
    val pin: Int,
    val type: String,
    val value: String?,
    val updated_at: String?,
    val label: String? = null
)

data class Device(
    val id: Int,
    val device_uid: String,
    val name: String,
    val is_online: Boolean,
    val last_seen_at: String?,
    val pin_states: List<PinState>?
)

data class DeviceCommandRequest(
    val action: String,     // digital_write | pwm_write | analog_read | digital_read | get_status
    val pin: Int?,
    val value: String?
)

data class DeviceCommandResponse(
    val id: Int,
    val cmd_uuid: String,
    val device_id: Int,
    val action: String,
    val pin: Int?,
    val value: String?,
    val status: String       // pending | acked | failed | timeout
)

interface DeviceApi {

    @GET("api/devices")
    suspend fun listDevices(): Response<List<Device>>

    @POST("api/devices/{device}/command")
    suspend fun sendCommand(
        @Path("device") deviceId: Int,
        @Body body: DeviceCommandRequest
    ): Response<DeviceCommandResponse>

    @GET("api/devices/{device}/command/{command}")
    suspend fun commandStatus(
        @Path("device") deviceId: Int,
        @Path("command") commandId: Int
    ): Response<DeviceCommandResponse>
}
