package com.uniquesacco.devicecontrol.dashboard

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.uniquesacco.devicecontrol.api.Device
import com.uniquesacco.devicecontrol.api.PinState
import com.uniquesacco.devicecontrol.realtime.RealtimeState

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DashboardScreen(
    state: DashboardUiState,
    onRefresh: () -> Unit,
    onToggleDigital: (deviceId: Int, pin: Int, turnOn: Boolean) -> Unit,
    onSetPwm: (deviceId: Int, pin: Int, duty: Int) -> Unit
) {
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Home Automation") },
                actions = {
                    ConnectionBadge(state.realtimeState)
                    IconButton(onClick = onRefresh) {
                        Icon(Icons.Default.Refresh, contentDescription = "Refresh")
                    }
                }
            )
        }
    ) { padding ->
        Box(modifier = Modifier.padding(padding).fillMaxSize()) {
            when {
                state.isLoading && state.devices.isEmpty() -> {
                    CircularProgressIndicator(modifier = Modifier.align(Alignment.Center))
                }
                state.devices.isEmpty() -> {
                    Text(
                        "No devices registered yet.",
                        modifier = Modifier.align(Alignment.Center),
                        style = MaterialTheme.typography.bodyLarge
                    )
                }
                else -> {
                    LazyColumn(
                        contentPadding = PaddingValues(16.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        items(state.devices, key = { it.id }) { device ->
                            DeviceCard(
                                device = device,
                                pendingPins = state.pendingCommandPins,
                                optimisticValues = state.optimisticValues,
                                onToggleDigital = onToggleDigital,
                                onSetPwm = onSetPwm
                            )
                        }
                    }
                }
            }

            state.error?.let { message ->
                Snackbar(
                    modifier = Modifier.align(Alignment.BottomCenter).padding(16.dp)
                ) { Text(message) }
            }
        }
    }
}

@Composable
private fun ConnectionBadge(state: RealtimeState) {
    val (color, label) = when (state) {
        RealtimeState.CONNECTED -> Color(0xFF2E7D32) to "Live"
        RealtimeState.CONNECTING -> Color(0xFFF9A825) to "Connecting"
        RealtimeState.DISCONNECTED -> Color(0xFF9E9E9E) to "Offline"
        RealtimeState.AUTH_FAILED -> Color(0xFFC62828) to "Sign in again"
    }
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(end = 12.dp)) {
        Box(
            modifier = Modifier
                .size(8.dp)
                .background(color, shape = androidx.compose.foundation.shape.CircleShape)
        )
        Spacer(modifier = Modifier.width(6.dp))
        Text(label, style = MaterialTheme.typography.labelSmall)
    }
}

@Composable
private fun DeviceCard(
    device: Device,
    pendingPins: Set<Pair<Int, Int>>,
    optimisticValues: Map<Pair<Int, Int>, String>,
    onToggleDigital: (Int, Int, Boolean) -> Unit,
    onSetPwm: (Int, Int, Int) -> Unit
) {
    ElevatedCard(modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(device.name, style = MaterialTheme.typography.titleMedium)
                OnlineDot(device.is_online)
            }

            Spacer(modifier = Modifier.height(4.dp))
            Text(
                device.device_uid,
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )

            Spacer(modifier = Modifier.height(12.dp))

            device.pin_states.orEmpty().forEach { pinState ->
                val key = device.id to pinState.pin
                // Optimistic value wins the instant a tap happens — the
                // switch/slider moves before the network round-trip
                // completes, which is what actually reads as "fast" to
                // someone tapping it, rather than technically-faster-but-
                // still-waits-for-an-ack.
                val displayedPin = optimisticValues[key]?.let { pinState.copy(value = it) } ?: pinState

                PinRow(
                    deviceId = device.id,
                    pin = displayedPin,
                    isPending = key in pendingPins,
                    deviceOnline = device.is_online,
                    onToggleDigital = onToggleDigital,
                    onSetPwm = onSetPwm
                )
                Spacer(modifier = Modifier.height(8.dp))
            }
        }
    }
}

@Composable
private fun OnlineDot(online: Boolean) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(
            modifier = Modifier
                .size(8.dp)
                .background(
                    if (online) Color(0xFF2E7D32) else Color(0xFF9E9E9E),
                    shape = androidx.compose.foundation.shape.CircleShape
                )
        )
        Spacer(modifier = Modifier.width(4.dp))
        Text(if (online) "Online" else "Offline", style = MaterialTheme.typography.labelSmall)
    }
}

@Composable
private fun PinRow(
    deviceId: Int,
    pin: PinState,
    isPending: Boolean,
    deviceOnline: Boolean,
    onToggleDigital: (Int, Int, Boolean) -> Unit,
    onSetPwm: (Int, Int, Int) -> Unit
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(pinLabel(pin), style = MaterialTheme.typography.bodyMedium)
            if (isPending) {
                Text("Confirming...", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.primary)
            }
        }

        when (pin.type) {
            "digital_output" -> {
                Switch(
                    checked = pin.value == "1",
                    enabled = deviceOnline,
                    onCheckedChange = { onToggleDigital(deviceId, pin.pin, it) }
                )
            }
            "pwm" -> {
                var sliderValue by remember(pin.value) { mutableStateOf((pin.value?.toIntOrNull() ?: 0).toFloat()) }
                Slider(
                    value = sliderValue,
                    onValueChange = { sliderValue = it },
                    onValueChangeFinished = { onSetPwm(deviceId, pin.pin, sliderValue.toInt()) },
                    valueRange = 0f..255f,
                    enabled = deviceOnline,
                    modifier = Modifier.width(160.dp)
                )
            }
            "digital_input", "analog_input" -> {
                Text(pin.value ?: "--", style = MaterialTheme.typography.titleMedium)
            }
        }
    }
}

private fun pinLabel(pin: PinState): String {
    val name = pin.label?.replace('_', ' ')?.replaceFirstChar { it.uppercase() }
    return if (name != null) "$name (pin ${pin.pin})" else "Pin ${pin.pin} (${pin.type.replace('_', ' ')})"
}
