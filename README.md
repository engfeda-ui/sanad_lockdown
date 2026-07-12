# 🛡️ Moodle Quiz Access Rule: Sanad Kiosk Lockdown (`quizaccess_sanad_lockdown`)

[![Moodle Compatibility](https://img.shields.io/badge/Moodle-4.5%20to%205.0%2B-orange.svg?style=flat-square)](https://moodle.org)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3-blue.svg?style=flat-square)](https://php.net)
[![Database](https://img.shields.io/badge/Database-PostgreSQL%20%7C%20MySQL%20%7C%20MariaDB-purple.svg?style=flat-square)](https://docs.moodle.org)
[![Android Version](https://img.shields.io/badge/Android-8.0%20to%2014%2B-green.svg?style=flat-square)](https://developer.android.com)
[![License](https://img.shields.io/badge/License-Proprietary-red.svg?style=flat-square)](#license)

A professional, enterprise-grade assessment lockdown solution designed specifically for the **Energy & Water Academy (Sanad)**. The system guarantees absolute exam integrity by forcing students to solve Moodle quizzes exclusively through the secured **Sanad Kiosk** Android tablet application.

The solution consists of two integrated components:
1. **Moodle Access Rule Plugin (`sanad_lockdown`):** Installs on your Moodle server to restrict quiz access, sign session tokens, and log focus violations.
2. **Sanad Kiosk (Android App):** Installs on target tablets to lock down the device into an absolute kiosk mode during the exam.

---

## ✨ Features

### 🔌 Moodle Access Rule Features
*   **Granular Kiosk Enforcement:** Enable lockdown on a per-quiz basis with a simple checkbox in quiz settings.
*   **Hardware-Locked Session Binding:** Binds the student's attempt to a unique device fingerprint (`Device ID`) on the first heartbeat. Any token sharing or access from multiple tablets triggers an immediate security violation.
*   **Real-Time Violation Logger:** Stores and tracks exam infractions (such as app focus loss, overlay detection, or device mismatch) in Moodle's database with timestamps.
*   **Supervisor Bypass Code:** Configure an exam-specific exit password in the quiz settings. This allows on-site supervisors to unlock the tablet and close the kiosk session.
*   **Enterprise Integration:** Fully compatible with Moodle's Privacy Subsystem (GDPR compliance) and Backup & Restore APIs.

### 📱 Sanad Kiosk (Android App) Features
*   **Absolute Device Owner Lock:** Locks the tablet using Android Enterprise `Device Owner` policies. Disables hardware buttons, gestures, recent apps, and the notification drawer.
*   **Secure Custom Keyboard (IME):** Implements a dedicated keyboard layout. Autocomplete, spelling suggestions, and clipboard copy/paste are completely disabled.
*   **Immersive Full-Screen Mode:** Hides navigation bars and system status bars permanently. The student cannot swipe out of the exam.
*   **Anti-Tampering Protections:** Performs runtime checks for device root status, emulator execution, and APK signature validation.
*   **Annual License Activation:** Secure offline challenge-response cryptographic signing (RSA-1024) protecting your intellectual property.
*   **Autoping (Heartbeat):** Constantly pings Moodle every 30 seconds to refresh the session token. If the app is closed or network fails, the session immediately expires on the server.

---

## 📋 Requirements

| Dependency | Required Version / Compatibility |
| :--- | :--- |
| **Moodle Framework** | Moodle 4.5.0 to 5.0+ (Tested against Moodle 4.5 stable) |
| **PHP Runtime** | PHP 8.1, PHP 8.2, PHP 8.3 |
| **Database System** | PostgreSQL 13+, MySQL 8.0+, or MariaDB 10.5+ |
| **Android Tablet OS** | Android 8.0 (Oreo) up to Android 14+ |

---

## 🚀 Installation

### 1. Moodle Plugin Installation (ZIP Upload)
1. Zip the `sanad_lockdown` folder.
2. Log in to your Moodle site as Administrator.
3. Go to **Site administration > Plugins > Install plugins**.
4. Drag and drop the `sanad_lockdown.zip` file into the file uploader.
5. Click **Install plugin from the ZIP file** and follow the database upgrade wizard.

### 2. Sanad Kiosk Android App Installation
1. Obtain the compiled `Sanad_Kiosk.apk` file.
2. Install the app on the tablet via ADB:
   ```bash
   adb install Sanad_Kiosk.apk
   ```
3. Set the app as the **Device Owner** (Kiosk Controller) using the following ADB command:
   ```bash
   adb shell dpm set-device-owner com.sanad.securebrowser/.DeviceAdminReceiver
   ```

---

## 🔑 Challenge-Response RSA Licensing & Activation
To prevent unauthorized installations and enforce annual subscription plans, Sanad Kiosk uses a hardware-locked asymmetric cryptography licensing protocol:
1. Upon first boot, the tablet displays a **16-character Hardware ID** (e.g., `Sanad1-98F2-A5C3-D8E4`).
2. Provide the Hardware ID to the developer/license issuer.
3. The license issuer opens their secure offline tool `sanad_license_generator.html`, inputs the Hardware ID, specifies the **custom subscription duration (days)**, and clicks **Generate Activation Key**. This signs the payload using a secure **RSA Private Key**.
4. Enter the generated activation key (`YYYYMMDD:RSA_SIGNATURE`) into the tablet. The app verifies the signature using an embedded **RSA Public Key** to permanently unlock the exam scanner interface for the chosen duration.

---

## 📋 Changelog

### v1.2.0 — 2026-07-07
*   **New:** Integrated **RSA-1024 Asymmetric Digital Signature** Hardware Activation licensing (annual subscription).
*   **New:** Implemented **Immersive Full-Screen Mode** (hiding status and navigation bars) in `MainActivity.kt`.
*   **New:** Added **Root Detection and Signature Verification** to block modified APK execution.
*   **New:** Created secure offline RSA license generator tool for the developer supporting custom days.
*   **Fix:** Cleared Moodle CodeSniffer standard warnings and PSR12 class brace whitespace errors across all files.

### v1.0.0 — 2026-06-25
*   Initial stable release.
*   Secure Token manager and Device ID binding logic.
*   Heartbeat log integrations.
*   Device Admin policies and basic kiosk controller class.

---

## 💻 Directory Structure

```
sanad_lockdown/
├── classes/
│   ├── privacy/            # GDPR Privacy provider
│   ├── qr_generator.php    # QR Code builder
│   ├── token_manager.php   # Session JWT manager
│   └── violation_logger.php# Violation database logs
├── db/
│   ├── install.xml         # Schema tables
│   └── upgrade.php         # Version migration handler
├── lang/
│   └── en/                 # Language packs
├── tests/                  # PHPUnit test cases
├── api.php                 # App heartbeat REST API endpoint
├── rule.php                # Access rule class
├── version.php             # Plugin version and metadata
└── README.md
```

---

## 🔒 Security & Code Compliance
*   **SQL Injection Prevention:** Utilizes Moodle's `$DB` API with named parameter bindings exclusively.
*   **Cryptographic Signature:** JWT Session tokens signed with HMAC-SHA256 based on site keys.
*   **Anti-Tampering:** APK files protected with custom R8/ProGuard obfuscation rules preventing decompilation.
*   **GDPR Compliance:** Implements all core privacy interfaces exporting and deleting session metadata logs.

---

## 📄 License & Credits
*   **Copyright:** © 2026 Mahmoud Salem
*   **License:** Proprietary. Confidential and proprietary software. Unauthorized copying, distribution, or reverse engineering is strictly prohibited.
