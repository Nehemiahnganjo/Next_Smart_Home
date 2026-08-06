@extends('layouts.app')
@section('title', 'Home Automation')

@section('content')
<div x-data="dashboard()" x-init="init()" class="max-w-3xl mx-auto px-4 py-6">

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold">Home Automation</h1>
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-1.5 text-xs font-medium">
                <span class="w-2 h-2 rounded-full"
                      :class="{
                          'bg-green-600': realtimeState === 'connected',
                          'bg-yellow-500': realtimeState === 'connecting',
                          'bg-gray-400': realtimeState === 'disconnected',
                          'bg-red-600': realtimeState === 'auth_failed'
                      }"></span>
                <span x-text="realtimeLabel()"></span>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="text-sm text-gray-500 hover:text-gray-800">Sign out</button>
            </form>
        </div>
    </div>

    <div x-show="error" x-text="error" x-cloak
         class="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-2"></div>

    <div x-show="isLoading && devices.length === 0" x-cloak class="text-center text-gray-400 py-16">
        Loading devices...
    </div>

    <div x-show="!isLoading && devices.length === 0" x-cloak class="text-center text-gray-400 py-16">
        No devices registered yet.
    </div>

    <div class="space-y-4">
        <template x-for="device in devices" :key="device.id">
            <div class="bg-white rounded-xl shadow-sm p-5">
                <div class="flex items-center justify-between mb-1">
                    <h2 class="font-medium" x-text="device.name"></h2>
                    <div class="flex items-center gap-1.5 text-xs">
                        <span class="w-2 h-2 rounded-full" :class="device.is_online ? 'bg-green-600' : 'bg-gray-400'"></span>
                        <span x-text="device.is_online ? 'Online' : 'Offline'"></span>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mb-4" x-text="device.device_uid"></p>

                <div class="space-y-3">
                    <template x-for="pin in (device.pin_states || [])" :key="pin.pin">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm" x-text="pinLabel(pin)"></div>
                                <div class="text-xs text-blue-600" x-show="isPending(device.id, pin.pin)" x-cloak>Confirming...</div>
                            </div>

                            <template x-if="pin.type === 'digital_output'">
                                <button
                                    type="button"
                                    :disabled="!device.is_online"
                                    @click="toggleDigital(device, pin)"
                                    class="w-12 h-7 rounded-full relative transition disabled:opacity-40"
                                    :class="displayedValue(device.id, pin) === '1' ? 'bg-blue-600' : 'bg-gray-300'">
                                    <span class="absolute top-1 w-5 h-5 bg-white rounded-full shadow transition-all"
                                          :class="displayedValue(device.id, pin) === '1' ? 'left-6' : 'left-1'"></span>
                                </button>
                            </template>

                            <template x-if="pin.type === 'pwm'">
                                <input type="range" min="0" max="255"
                                       :value="displayedValue(device.id, pin)"
                                       :disabled="!device.is_online"
                                       @change="setPwm(device, pin, $event.target.value)"
                                       class="w-40">
                            </template>

                            <template x-if="pin.type === 'digital_input' || pin.type === 'analog_input'">
                                <span class="text-sm font-medium" x-text="displayedValue(device.id, pin) ?? '--'"></span>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>
</div>

<script>
function dashboard() {
    return {
        devices: [],
        isLoading: true,
        error: null,
        realtimeState: 'connecting',
        pendingPins: {},      // "deviceId:pin" -> true
        optimisticValues: {}, // "deviceId:pin" -> value

        init() {
            this.loadDevices();
            this.connectRealtime();
        },

        async loadDevices() {
            this.isLoading = true;
            try {
                const res = await fetch('/devices', { headers: { 'Accept': 'application/json' } });
                if (!res.ok) throw new Error('Failed to load devices (' + res.status + ')');
                this.devices = await res.json();
            } catch (e) {
                this.error = e.message;
            } finally {
                this.isLoading = false;
            }
        },

        connectRealtime() {
            const pusher = new Pusher('{{ env('REVERB_APP_KEY', 'replace_me') }}', {
                wsHost: '{{ env('REVERB_HOST', 'localhost') }}',
                wsPort: {{ env('REVERB_PORT', 8080) }},
                wssPort: {{ env('REVERB_PORT', 8080) }},
                forceTLS: {{ env('REVERB_SCHEME', 'https') === 'https' ? 'true' : 'false' }},
                enabledTransports: ['ws', 'wss'],
                authEndpoint: '/broadcasting/auth',
                auth: { headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } }
            });

            pusher.connection.bind('state_change', (states) => {
                const map = { connected: 'connected', connecting: 'connecting', unavailable: 'disconnected', failed: 'disconnected' };
                this.realtimeState = map[states.current] || 'connecting';
            });

            const channel = pusher.subscribe('private-user.{{ auth()->id() }}.devices');
            channel.bind('pusher:subscription_error', () => { this.realtimeState = 'auth_failed'; });
            channel.bind('device.status', (event) => this.onRealtimeEvent(event));
        },

        onRealtimeEvent(event) {
            const device = this.devices.find(d => d.id === event.device_id);
            if (!device) return;

            device.is_online = event.is_online;
            const pinStates = device.pin_states ? [...device.pin_states] : [];
            const byPin = Object.fromEntries(pinStates.map(p => [p.pin, p]));

            (event.pins || []).forEach(p => {
                byPin[p.pin] = { ...(byPin[p.pin] || {}), pin: p.pin, type: p.type, label: p.label, value: String(p.value) };
                this.clearOptimistic(device.id, p.pin);
            });

            // Timeout/error results with no pin payload still need to clear
            // pending state so the UI doesn't hang on "Confirming..." forever.
            if (event.result === 'timeout' && (!event.pins || event.pins.length === 0)) {
                Object.keys(this.pendingPins).forEach(key => {
                    if (key.startsWith(device.id + ':')) delete this.pendingPins[key];
                });
                Object.keys(this.optimisticValues).forEach(key => {
                    if (key.startsWith(device.id + ':')) delete this.optimisticValues[key];
                });
            }

            device.pin_states = Object.values(byPin);
        },

        key(deviceId, pin) { return deviceId + ':' + pin; },
        isPending(deviceId, pin) { return !!this.pendingPins[this.key(deviceId, pin)]; },
        displayedValue(deviceId, pin) {
            const k = this.key(deviceId, pin.pin);
            return this.optimisticValues[k] !== undefined ? this.optimisticValues[k] : pin.value;
        },
        markPending(deviceId, pin, value) {
            const k = this.key(deviceId, pin);
            this.pendingPins[k] = true;
            this.optimisticValues[k] = value;
        },
        clearOptimistic(deviceId, pin) {
            const k = this.key(deviceId, pin);
            delete this.pendingPins[k];
            delete this.optimisticValues[k];
        },

        toggleDigital(device, pin) {
            const next = this.displayedValue(device.id, pin) === '1' ? '0' : '1';
            this.markPending(device.id, pin.pin, next);
            this.sendCommand(device.id, pin.pin, 'digital_write', next);
        },

        setPwm(device, pin, value) {
            this.markPending(device.id, pin.pin, value);
            this.sendCommand(device.id, pin.pin, 'pwm_write', value);
        },

        async sendCommand(deviceId, pin, action, value) {
            try {
                const res = await fetch(`/devices/${deviceId}/command`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ action, pin, value })
                });
                if (!res.ok) {
                    this.clearOptimistic(deviceId, pin);
                    this.error = 'Command failed (' + res.status + ')';
                }
            } catch (e) {
                this.clearOptimistic(deviceId, pin);
                this.error = e.message;
            }
        },

        pinLabel(pin) {
            const name = pin.label ? pin.label.replace(/_/g, ' ') : null;
            return name ? name.charAt(0).toUpperCase() + name.slice(1) + ' (pin ' + pin.pin + ')'
                         : 'Pin ' + pin.pin + ' (' + pin.type.replace(/_/g, ' ') + ')';
        },

        realtimeLabel() {
            return { connected: 'Live', connecting: 'Connecting', disconnected: 'Offline', auth_failed: 'Sign in again' }[this.realtimeState] || 'Connecting';
        }
    };
}
</script>
@endsection
