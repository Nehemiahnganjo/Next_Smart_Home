/*
 * ESP32-WROOM-32 remote control firmware
 * MQTT command/status protocol matching the Laravel bridge.
 *
 * Libraries (Arduino Library Manager):
 *   - PubSubClient      (Nick O'Leary)
 *   - ArduinoJson        (Benoit Blanchon)  v7+
 *   - WiFiClientSecure   (bundled with ESP32 core)
 *
 * Board: ESP32 Dev Module / WROOM-32
 * Requires: esp32:esp32 core v3.x (ESP-IDF v5 based)
 */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <esp_task_wdt.h>

// Secrets live in a separate file that is NEVER committed to git — see
// secrets.example.h in this folder. Copy it to secrets.h, fill in real
// values, and add `secrets.h` to .gitignore. Keeps WiFi/MQTT/broker-cert
// credentials out of a public repo (e.g. github.com/Nehemiahnganjo) even
// if this sketch itself gets shared or open-sourced.
#include "secrets.h"

// Hardware watchdog: reboots the board if loop() ever hangs (e.g. a blocking
// call in a future pin handler, a runaway retry). Cheap insurance for a
// device you can't walk over and power-cycle.
#define WDT_TIMEOUT_S 20

// delay() blocks for its full duration with no watchdog feed in between.
// Any delay approaching or exceeding WDT_TIMEOUT_S will trip the watchdog
// and force a reboot in the middle of what should be a harmless retry wait.
// This chops long delays into WDT-safe chunks and feeds the dog between them.
void wdtSafeDelay(unsigned long ms) {
  const unsigned long CHUNK_MS = (WDT_TIMEOUT_S - 5) * 1000UL;
  while (ms > CHUNK_MS) {
    esp_task_wdt_reset();
    delay(CHUNK_MS);
    ms -= CHUNK_MS;
  }
  esp_task_wdt_reset();
  delay(ms);
}

// ---------- CONFIG ----------
// WIFI_SSID, WIFI_PASSWORD, MQTT_HOST, MQTT_PORT, DEVICE_UID, MQTT_USER,
// MQTT_PASS, and MQTT_CA_CERT are all defined in secrets.h (see note above).

// Static IP skips the DHCP negotiation (typically 1-3s) on every boot and
// reconnect. Set USE_STATIC_IP false to fall back to DHCP if you'd rather
// not manage static leases on your router. Match these to your LAN.
#define USE_STATIC_IP true
IPAddress STATIC_IP(192, 168, 1, 50);
IPAddress STATIC_GATEWAY(192, 168, 1, 1);
IPAddress STATIC_SUBNET(255, 255, 255, 0);
IPAddress STATIC_DNS(192, 168, 1, 1);

// ---------- PIN CAPABILITIES ----------
// Declare what this device controls. Extend as needed.
struct PinDef {
  uint8_t pin;
  const char* type;   // "digital_output" | "digital_input" | "pwm" | "analog_input"
  const char* label;
};

PinDef PINS[] = {
  {26, "digital_output", "relay1"},
  {27, "digital_output", "relay2"},
  {25, "pwm",             "motor_speed"},
  {34, "analog_input",   "sensor1"},   // input-only ADC pin
};
const size_t PIN_COUNT = sizeof(PINS) / sizeof(PINS[0]);

// PWM config (LEDC)
// Note: ESP32 Arduino core v3 no longer requires an explicit channel number —
// ledcAttach(pin, freq, resolution) manages channels internally.
const int PWM_FREQ_HZ    = 5000;
const int PWM_RESOLUTION = 8; // 0-255

char cmdTopic[64];
char statusTopic[64];

// MQTT QoS 1 guarantees at-least-once delivery, which means a command can
// legitimately arrive twice (broker retry after a slow PUBACK, reconnect
// during in-flight delivery, etc). Track the last executed cmd_id so a
// duplicate relay-toggle can't double-fire.
char lastCmdId[40] = "";

// Minimum time between actuations of the same output pin. Protects relay
// contacts (and anything mechanical downstream — gate motors, valves) from
// rapid chatter caused by a buggy client, a flaky connection replaying
// commands, or someone mashing a UI switch.
const unsigned long MIN_ACTUATION_INTERVAL_MS = 250;
unsigned long lastActuationMs[PIN_COUNT] = {0}; // indexed by position in PINS[], not GPIO number

// strtol-based parse that actually reports failure, instead of atoi()
// which silently returns 0 for garbage input — dangerous when 0 means
// "turn the relay off" and the caller sent an empty or malformed value.
bool parseIntStrict(const char* str, long* outValue) {
  if (str == nullptr || str[0] == '\0') return false;
  char* endPtr;
  long val = strtol(str, &endPtr, 10);
  if (endPtr == str || *endPtr != '\0') return false; // no digits consumed, or trailing garbage
  *outValue = val;
  return true;
}

WiFiClientSecure netClient;
PubSubClient mqtt(netClient);

// ---------- SETUP ----------
void setupWifi() {
  WiFi.mode(WIFI_STA);

  // Default WiFi power-save (modem sleep) lets the radio nap between beacon
  // intervals, which adds 100-300ms of jitter to every incoming packet —
  // felt directly as lag between tapping a switch and the relay clicking.
  // This device is mains-powered (relay board, not battery), so there's no
  // reason to trade responsiveness for power savings.
  WiFi.setSleep(false);

#if USE_STATIC_IP
  if (!WiFi.config(STATIC_IP, STATIC_GATEWAY, STATIC_SUBNET, STATIC_DNS)) {
    Serial.println("Static IP config failed, falling back to DHCP.");
  }
#endif

  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("Connecting WiFi");

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED) {
    delay(300);
    Serial.print(".");
    // If a full watchdog window passes without WiFi, feed it here rather
    // than let the board reboot mid-association attempt — but bail and
    // let the outer loop retry (with the watchdog now active) if it drags on.
    if (millis() - start > (WDT_TIMEOUT_S - 2) * 1000UL) {
      esp_task_wdt_reset();
      start = millis();
    }
  }
  Serial.println("\nWiFi connected: " + WiFi.localIP().toString());
}

void setupPins() {
  for (size_t i = 0; i < PIN_COUNT; i++) {
    PinDef &p = PINS[i];

    // Input-only ADC pins (34-39) have no output driver — trying to use them
    // as digital_output/pwm silently does nothing on real hardware, which is
    // a much nastier bug to chase than a boot-time refusal.
    bool isInputOnly = (p.pin >= 34 && p.pin <= 39);
    if (isInputOnly && (strcmp(p.type, "digital_output") == 0 || strcmp(p.type, "pwm") == 0)) {
      Serial.printf("FATAL: pin %d is input-only (ADC1_CH), cannot be used as %s. Halting.\n", p.pin, p.type);
      while (true) { delay(1000); esp_task_wdt_reset(); } // refuse to run with a wiring bug that will silently fail in the field
    }

    // Strapping pins (0, 2, 12, 15) affect boot mode / flash voltage. Using
    // them as outputs is legal but a wrongly-wired external circuit (e.g. a
    // relay board pulling one low at boot) can prevent the board from
    // booting at all. Not fatal — just flagged so it's a deliberate choice.
    if (p.pin == 0 || p.pin == 2 || p.pin == 12 || p.pin == 15) {
      Serial.printf("WARNING: pin %d is a strapping pin — verify nothing external holds it during boot.\n", p.pin);
    }

    if (strcmp(p.type, "digital_output") == 0) {
      pinMode(p.pin, OUTPUT);
      digitalWrite(p.pin, LOW); // fail-safe boot state: everything OFF until an explicit command says otherwise
    } else if (strcmp(p.type, "digital_input") == 0) {
      pinMode(p.pin, INPUT);
    } else if (strcmp(p.type, "pwm") == 0) {
      // ESP32 Arduino core v3 replaced the two-step ledcSetup(channel,...) +
      // ledcAttachPin(pin, channel) with a single ledcAttach(pin, freq, resolution).
      // Channels are now managed internally — no channel number needed.
      ledcAttach(p.pin, PWM_FREQ_HZ, PWM_RESOLUTION);
      ledcWrite(p.pin, 0);
    }
    // analog_input needs no pinMode on ESP32 ADC pins
  }
}

// Build a JSON status payload reflecting current state of all declared pins.
void publishStatus(const char* cmdId = nullptr, const char* result = nullptr) {
  JsonDocument doc;
  doc["device_uid"] = DEVICE_UID;
  doc["online"] = true;
  if (cmdId) doc["cmd_id"] = cmdId;
  if (result) doc["result"] = result;

  JsonArray pins = doc.createNestedArray("pins");
  for (size_t i = 0; i < PIN_COUNT; i++) {
    PinDef &p = PINS[i];
    JsonObject o = pins.createNestedObject();
    o["pin"] = p.pin;
    o["type"] = p.type;
    o["label"] = p.label;

    if (strcmp(p.type, "digital_output") == 0 || strcmp(p.type, "digital_input") == 0) {
      o["value"] = digitalRead(p.pin);
    } else if (strcmp(p.type, "pwm") == 0) {
      // ledcRead() now takes the GPIO pin number, not the channel number (core v3).
      o["value"] = ledcRead(p.pin);
    } else if (strcmp(p.type, "analog_input") == 0) {
      o["value"] = analogRead(p.pin);
    }
  }

  char buf[512];
  size_t n = serializeJson(doc, buf);
  mqtt.publish(statusTopic, buf, n);
}

int findPinIndex(uint8_t pin) {
  for (size_t i = 0; i < PIN_COUNT; i++) {
    if (PINS[i].pin == pin) return (int)i;
  }
  return -1;
}

// ---------- COMMAND HANDLING ----------
void handleCommand(const JsonDocument &doc) {
  const char* cmdId  = doc["cmd_id"] | "";
  const char* action = doc["action"] | "";
  int pin             = doc["pin"] | -1;
  const char* value   = doc["value"] | "";

  // Duplicate delivery guard — re-ack without re-executing.
  if (cmdId[0] != '\0' && strcmp(cmdId, lastCmdId) == 0) {
    publishStatus(cmdId, "ok:duplicate_ignored");
    return;
  }

  if (strcmp(action, "get_status") == 0) {
    publishStatus(cmdId, "ok");
    return;
  }

  int pinIdx = pin >= 0 ? findPinIndex((uint8_t)pin) : -1;
  if (pinIdx < 0) {
    publishStatus(cmdId, "error:unknown_pin");
    return;
  }
  PinDef* p = &PINS[pinIdx];

  if (cmdId[0] != '\0') {
    strncpy(lastCmdId, cmdId, sizeof(lastCmdId) - 1);
    lastCmdId[sizeof(lastCmdId) - 1] = '\0';
  }

  bool isActuation = strcmp(action, "digital_write") == 0 || strcmp(action, "pwm_write") == 0;
  if (isActuation) {
    unsigned long now = millis();
    if (now - lastActuationMs[pinIdx] < MIN_ACTUATION_INTERVAL_MS) {
      publishStatus(cmdId, "error:rate_limited");
      return;
    }
  }

  if (strcmp(action, "digital_write") == 0 && strcmp(p->type, "digital_output") == 0) {
    long parsed;
    if (!parseIntStrict(value, &parsed)) {
      publishStatus(cmdId, "error:bad_value");
      return;
    }
    digitalWrite(p->pin, parsed != 0 ? HIGH : LOW);
    lastActuationMs[pinIdx] = millis();
    publishStatus(cmdId, "ok");

  } else if (strcmp(action, "pwm_write") == 0 && strcmp(p->type, "pwm") == 0) {
    long parsed;
    if (!parseIntStrict(value, &parsed)) {
      publishStatus(cmdId, "error:bad_value");
      return;
    }
    int duty = constrain((int)parsed, 0, 255);
    ledcWrite(p->pin, duty);  // core v3: takes GPIO pin, not channel number
    lastActuationMs[pinIdx] = millis();
    publishStatus(cmdId, "ok");

  } else if (strcmp(action, "digital_read") == 0 && strcmp(p->type, "digital_input") == 0) {
    publishStatus(cmdId, "ok");

  } else if (strcmp(action, "analog_read") == 0 && strcmp(p->type, "analog_input") == 0) {
    publishStatus(cmdId, "ok");

  } else {
    publishStatus(cmdId, "error:action_mismatch");
  }
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  // A buffer size mismatch (setBufferSize is 512, doc is 256) or a
  // corrupted/oversized publish on the command topic shouldn't be handed to
  // the JSON parser — reject up front rather than trust length blindly.
  const unsigned int MAX_CMD_PAYLOAD = 256;
  if (length == 0 || length > MAX_CMD_PAYLOAD) {
    Serial.printf("Rejected command payload: length=%u (max %u)\n", length, MAX_CMD_PAYLOAD);
    return;
  }

  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, payload, length);
  if (err) {
    Serial.printf("JSON parse failed: %s\n", err.c_str());
    return;
  }
  handleCommand(doc);
}

void reconnectMqtt() {
  const unsigned long MAX_BACKOFF_MS = 30000;
  // If we've been failing to connect for this long, something is wrong
  // beyond a transient network blip (bad cert, broker down, bad creds after
  // a rotation) — a full restart clears any corrupted TLS/socket state that
  // a software retry loop alone can't fix, and is safer than looping forever.
  const unsigned long GIVE_UP_AND_REBOOT_MS = 10UL * 60UL * 1000UL; // 10 min
  unsigned long backoff = 1000;
  unsigned long attemptsStart = millis();

  while (!mqtt.connected()) {
    esp_task_wdt_reset(); // don't let retry loop trip the watchdog
    Serial.print("Connecting MQTT...");

    // Last Will: broker auto-publishes this if we drop uncleanly.
    JsonDocument lwt;
    lwt["device_uid"] = DEVICE_UID;
    lwt["online"] = false;
    char lwtBuf[64];
    serializeJson(lwt, lwtBuf);

    // cleanSession=false: broker keeps our subscription to devices/{uid}/cmd
    // even across a reconnect, so there's no resubscribe round-trip and no
    // gap where a command sent right as we reconnect could be missed.
    if (mqtt.connect(DEVICE_UID, MQTT_USER, MQTT_PASS,
                      statusTopic, 1, true, lwtBuf, false)) {
      Serial.println("connected");
      mqtt.subscribe(cmdTopic, 1);
      publishStatus(nullptr, "boot");
    } else {
      Serial.print("failed, rc=");
      Serial.print(mqtt.state());
      Serial.print(" retrying in ");
      Serial.print(backoff);
      Serial.println("ms");

      if (millis() - attemptsStart > GIVE_UP_AND_REBOOT_MS) {
        Serial.println("MQTT unreachable for 10min straight — restarting device.");
        delay(200); // let the serial line flush before reset
        ESP.restart();
      }

      wdtSafeDelay(backoff);
      backoff = min(backoff * 2, MAX_BACKOFF_MS);
    }
  }
}

void setup() {
  Serial.begin(115200);

  // ESP32 Arduino core v3 (ESP-IDF v5) changed esp_task_wdt_init() to take a
  // config struct instead of (timeout_seconds, panic_on_timeout). The old two-
  // argument form is gone — using it causes a hard compile error.
  const esp_task_wdt_config_t wdt_cfg = {
      .timeout_ms = WDT_TIMEOUT_S * 1000,
      .idle_core_mask = 0,   // don't watch idle tasks, only our registered task
      .trigger_panic = true, // reboot on timeout instead of just printing a warning
  };
  esp_task_wdt_init(&wdt_cfg);
  esp_task_wdt_add(NULL);

  snprintf(cmdTopic, sizeof(cmdTopic), "devices/%s/cmd", DEVICE_UID);
  snprintf(statusTopic, sizeof(statusTopic), "devices/%s/status", DEVICE_UID);

  setupPins();
  setupWifi();

  netClient.setCACert(MQTT_CA_CERT);
  // netClient.setInsecure(); // TESTING ONLY — remove once MQTT_CA_CERT is set

  // NOTE on TLS session resumption: the Arduino ESP32 core's WiFiClientSecure
  // doesn't expose a public method to control mbedTLS session resumption —
  // there's no setSessionResumptionEnabled() or equivalent in the stock API.
  // Each WiFiClientSecure::connect() negotiates a fresh handshake. Session
  // resumption at this layer would require dropping to ESP-IDF's mbedtls
  // APIs directly (ssl_session save/load around the client object), which is
  // real surgery, not a config flag — not worth it unless reconnects are
  // frequent enough to profile as an actual bottleneck. The broker-side
  // tls_version setting in SETUP.md still matters for interop even without
  // this optimization.

  mqtt.setServer(MQTT_HOST, MQTT_PORT);
  mqtt.setCallback(mqttCallback);
  mqtt.setBufferSize(512);
  mqtt.setKeepAlive(15);      // default is 15s; explicit so a broker-side override doesn't silently change responsiveness
  mqtt.setSocketTimeout(5);   // fail fast on a stalled TCP op instead of blocking loop() for the library default (15s)
}

unsigned long lastHeartbeat = 0;
const unsigned long HEARTBEAT_MS = 30000; // periodic status even with no commands

void loop() {
  esp_task_wdt_reset();

  if (WiFi.status() != WL_CONNECTED) {
    setupWifi();
  }
  if (!mqtt.connected()) {
    reconnectMqtt();
  }
  mqtt.loop();

  if (millis() - lastHeartbeat > HEARTBEAT_MS) {
    publishStatus();
    lastHeartbeat = millis();
  }
}
