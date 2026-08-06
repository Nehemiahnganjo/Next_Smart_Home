// Copy this file to secrets.h (same folder), fill in real values, and make
// sure secrets.h is in .gitignore. esp32_controller.ino #includes "secrets.h".

#pragma once

const char* WIFI_SSID     = "YOUR_WIFI_SSID";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";

const char* MQTT_HOST  = "your-broker.example.com";
const int   MQTT_PORT  = 8883;                  // TLS
const char* DEVICE_UID = "esp32-a1b2c3";        // must match device_uid registered in Laravel
const char* MQTT_USER  = "esp32-a1b2c3";        // per-device MQTT credentials (broker ACL scoped)
const char* MQTT_PASS  = "DEVICE_MQTT_PASSWORD";

// Root CA for your broker (Let's Encrypt ISRG Root X1, or your broker's own cert).
// Leaving this as a placeholder + calling netClient.setInsecure() in the main
// sketch is only acceptable for bring-up/testing — never in a deployed device.
const char* MQTT_CA_CERT = R"EOF(
-----BEGIN CERTIFICATE-----
REPLACE_WITH_YOUR_BROKER_CA_CERT
-----END CERTIFICATE-----
)EOF";
