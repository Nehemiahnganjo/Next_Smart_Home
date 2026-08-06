package com.uniquesacco.devicecontrol.dashboard

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.uniquesacco.devicecontrol.api.Device
import com.uniquesacco.devicecontrol.api.DeviceApi
import com.uniquesacco.devicecontrol.api.DeviceCommandRequest
import com.uniquesacco.devicecontrol.api.PinState
import com.uniquesacco.devicecontrol.realtime.DeviceStatusEvent
import com.uniquesacco.devicecontrol.realtime.RealtimeState
import com.uniquesacco.devicecontrol.realtime.ReverbListener
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class DashboardUiState(
    val devices: List<Device> = emptyList(),
    val realtimeState: RealtimeState = RealtimeState.CONNECTING,
    val isLoading: Boolean = true,
    val error: String? = null,
    // pin values that were optimistically flipped locally but not yet
    // confirmed by an ack — kept separate so a failed/timed-out command
    // can be visually reverted instead of leaving stale UI state.
    val pendingCommandPins: Set<Pair<Int, Int>> = emptySet(), // (deviceId, pin)
    // The value shown immediately on tap, before the round-trip confirms it.
    // Keyed the same way; cleared once the confirmed device state matches
    // (success) or the command errors/times out (revert, see below).
    val optimisticValues: Map<Pair<Int, Int>, String> = emptyMap()
)

class DashboardViewModel(
    private val api: DeviceApi,
    private val reverb: ReverbListener,
    private val currentUserId: Int
) : ViewModel() {

    private val _uiState = MutableStateFlow(DashboardUiState())
    val uiState: StateFlow<DashboardUiState> = _uiState

    init {
        reverb.connect()
        loadDevices()
    }

    fun loadDevices() {
        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true, error = null) }
            try {
                val response = api.listDevices()
                if (response.isSuccessful) {
                    _uiState.update { it.copy(devices = response.body().orEmpty(), isLoading = false) }
                } else {
                    _uiState.update { it.copy(isLoading = false, error = "Failed to load devices (${response.code()})") }
                }
            } catch (e: Exception) {
                _uiState.update { it.copy(isLoading = false, error = e.message ?: "Network error") }
            }
        }
    }

    /** Called by the Reverb listener whenever a device.status event arrives. */
    fun onRealtimeEvent(event: DeviceStatusEvent) {
        _uiState.update { state ->
            val updatedDevices = state.devices.map { device ->
                if (device.id != event.device_id) return@map device

                val existingPins = device.pin_states.orEmpty().associateBy { it.pin }.toMutableMap()
                event.pins.forEach { pinMap ->
                    val pin = (pinMap["pin"] as? Double)?.toInt() ?: return@forEach
                    val type = pinMap["type"] as? String ?: "unknown"
                    val value = pinMap["value"]?.toString()
                    val label = pinMap["label"] as? String
                    existingPins[pin] = PinState(pin, type, value, event.at, label)
                }

                device.copy(is_online = event.is_online, pin_states = existingPins.values.toList())
            }

            val isTerminalWithNoPinData = event.result == "timeout" && event.pins.isEmpty()

            fun shouldClear(deviceId: Int, pin: Int): Boolean =
                deviceId == event.device_id &&
                    (isTerminalWithNoPinData || event.pins.any { p -> (p["pin"] as? Double)?.toInt() == pin })

            val clearedPending = state.pendingCommandPins.filterNot { (deviceId, pin) -> shouldClear(deviceId, pin) }.toSet()
            val clearedOptimistic = state.optimisticValues.filterNot { (key, _) -> shouldClear(key.first, key.second) }

            state.copy(devices = updatedDevices, pendingCommandPins = clearedPending, optimisticValues = clearedOptimistic)
        }
    }

    fun onRealtimeStateChange(state: RealtimeState) {
        _uiState.update { it.copy(realtimeState = state) }
        if (state == RealtimeState.AUTH_FAILED) {
            // Token likely expired — caller should refresh the Sanctum token
            // and re-init the ViewModel; surfaced via realtimeState for the UI to react to.
        }
    }

    fun toggleDigitalPin(deviceId: Int, pin: Int, turnOn: Boolean) {
        val value = if (turnOn) "1" else "0"
        markPending(deviceId, pin, value)
        sendCommand(deviceId, pin, DeviceCommandRequest("digital_write", pin, value))
    }

    fun setPwmPin(deviceId: Int, pin: Int, duty0to255: Int) {
        val value = duty0to255.toString()
        markPending(deviceId, pin, value)
        sendCommand(deviceId, pin, DeviceCommandRequest("pwm_write", pin, value))
    }

    fun refreshPin(deviceId: Int, pin: Int) {
        sendCommand(deviceId, pin, DeviceCommandRequest("get_status", null, null))
    }

    private fun markPending(deviceId: Int, pin: Int, optimisticValue: String) {
        val key = deviceId to pin
        _uiState.update {
            it.copy(
                pendingCommandPins = it.pendingCommandPins + key,
                optimisticValues = it.optimisticValues + (key to optimisticValue)
            )
        }
    }

    private fun clearOptimistic(deviceId: Int, pin: Int) {
        val key = deviceId to pin
        _uiState.update {
            it.copy(
                pendingCommandPins = it.pendingCommandPins - key,
                optimisticValues = it.optimisticValues - key
            )
        }
    }

    private fun sendCommand(deviceId: Int, pin: Int?, request: DeviceCommandRequest) {
        viewModelScope.launch {
            try {
                val response = api.sendCommand(deviceId, request)
                if (!response.isSuccessful) {
                    // Rejected outright (validation, rate limit, offline device) — revert
                    // immediately rather than waiting out the 15s server-side timeout.
                    if (pin != null) clearOptimistic(deviceId, pin)
                    _uiState.update { it.copy(error = "Command failed (${response.code()})") }
                }
            } catch (e: Exception) {
                if (pin != null) clearOptimistic(deviceId, pin)
                _uiState.update { it.copy(error = e.message ?: "Command failed") }
            }
        }
    }

    override fun onCleared() {
        reverb.disconnect()
        super.onCleared()
    }
}
