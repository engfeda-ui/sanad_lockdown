# 🛡️ Moodle Quiz Access Rule: Sanad Lockdown (`quizaccess_sanad_lockdown`)

[![Moodle Plugin CI](https://github.com/engfeda-ui/sanad_lockdown/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/engfeda-ui/sanad_lockdown/actions/workflows/ci.yml)
[![Moodle Compatibility](https://img.shields.io/badge/Moodle-4.5%20LTS%20%7C%205.0%2B-orange.svg?style=flat-square)](https://moodle.org)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-blue.svg?style=flat-square)](https://php.net)
[![Database](https://img.shields.io/badge/Database-PostgreSQL%20%7C%20MySQL%20%7C%20MariaDB-purple.svg?style=flat-square)](https://docs.moodle.org)
[![Android Compatibility](https://img.shields.io/badge/Android-8.0%20to%2014%2B-green.svg?style=flat-square)](https://developer.android.com)
[![Version](https://img.shields.io/badge/Version-v1.7.2-blue.svg?style=flat-square)](https://github.com/engfeda-ui/sanad_lockdown)
[![CI/CD](https://img.shields.io/badge/CI%2FCD-Moodle%20Plugin%20CI-green.svg)](#)
[![License](https://img.shields.io/badge/License-Proprietary-red.svg?style=flat-square)](#)

An enterprise-grade Moodle 4.5 LTS Quiz Access Rule plugin designed for high-stakes online examinations. It guarantees total exam integrity by requiring students to solve quizzes exclusively through the secured **Sanad Kiosk** Android tablet application.

---

## 🌐 Ecosystem Architecture

The **SANAD Platform** consists of four integrated repositories:

1. **[Sanad Lockdown (`sanad_lockdown`)](https://github.com/engfeda-ui/sanad_lockdown)** *(This Repository)*: Moodle 4.5 LTS Quiz Access Rule plugin.
2. **[Sanad Kiosk (`sanad-kiosk`)](https://github.com/engfeda-ui/sanad-kiosk)**: Android Kiosk Browser application.
3. **[Sanad Licensing Server (`sanad_licensing_server`)](https://github.com/engfeda-ui/sanad_licensing_server)**: Central device registration & activation server (`license.sanad.ws`).
4. **[Sanad Frontend (`sanad-frontend`)](https://github.com/engfeda-ui/sanad-frontend)**: Official landing page and presentation UI (`sanad.ws`).

---

## ✨ Features

### 🔌 Quiz Access Rule (`rule.php`)
* **Per-Quiz Lockdown Toggle:** Enable/disable Sanad Secure Browser requirement with a single checkbox in quiz settings.
* **QR Code & Short Code Launch:** Generates dynamic QR codes and 6-digit numeric short codes (e.g. `123-456`) for quick student exam access.
* **Signed HMAC Session Tokens:** Issues cryptographic session tokens (`token_manager.php`) with configurable expiry times (e.g. 30 minutes).
* **Hardware Device Lock:** Binds student sessions to specific tablet `deviceid` fingerprints to prevent session hijacking or token sharing.
* **Emergency Exit Passwords:** Generates 6-digit emergency exit codes per quiz, allowing invigilators to release tablets from kiosk mode.

### 📊 Supervisor Live Monitor (`monitor.php`) & Device Manager (`manage_devices.php`)
* **Real-Time Live Monitor (`monitor.php`):** Displays active student sessions, heartbeats, token expiry times, and security infractions during live exams.
* **Device Manager (`manage_devices.php`):** Administrative interface for monitoring registered kiosk devices, hardware IDs, and license expiration status.

### 🛡️ Security Violation Logger (`violation_logger.php`)
* Logs all security infractions (wrong browser, invalid token, focus loss, device mismatch) directly to the Moodle database with timestamps and context details.

---

## 📋 Requirements & Compatibility

| Component | Requirement |
| :--- | :--- |
| **Moodle Version** | Moodle 4.5 LTS (Build `2024100700`) to 5.0+ |
| **PHP Version** | PHP 8.2, PHP 8.3 |
| **Database Engine** | PostgreSQL 13+, MariaDB 10.5+, or MySQL 8.0+ |
| **Client Device** | Android 8.0+ Tablet running **Sanad Kiosk App** |

---

## 📁 Repository Structure

```
sanad_lockdown/
├── classes/
│   ├── api_handler.php     # REST API router for app communication
│   ├── token_manager.php   # HMAC session token issuer & short code resolver
│   ├── violation_logger.php# Security violation logger
│   ├── qr_generator.php    # QR Code renderer
│   └── privacy/            # Moodle GDPR privacy provider
├── db/
│   ├── install.xml         # Database tables schema
│   └── upgrade.php         # Database migration handler
├── lang/
│   ├── en/                 # English language strings
│   └── ar/                 # Arabic language strings
├── tests/
│   └── rule_test.php       # PHPUnit automated test suite
├── .github/workflows/
│   └── moodle-ci.yml       # Moodle Plugin CI automated testing workflow
├── api.php                 # App API entry point
├── rule.php                # Main Quiz Access Rule class implementation
├── monitor.php             # Live exam supervisor monitor dashboard
├── manage_devices.php      # Admin kiosk device & license panel
├── settings.php            # Admin navigation settings
├── version.php             # Plugin metadata & version definition
└── README.md
```

---

## 🚀 Installation & Setup

1. Copy or clone the repository into your Moodle installation path:
   ```bash
   mod/quiz/accessrule/sanad_lockdown
   ```
2. Log in to your Moodle site as Administrator.
3. Navigate to **Site administration > Notifications** and complete the database installation wizard.
4. Edit any Quiz settings under **Extra restrictions on attempts** to enable **Require Sanad Secure Browser**.

---

## 🧪 Automated Testing (Moodle CI)

Automated testing is configured via GitHub Actions in [`.github/workflows/moodle-ci.yml`](.github/workflows/moodle-ci.yml).

It executes the official `moodle-plugin-ci` test suite:
- **PHP Lint (`phplint`)**: Checks PHP syntax across all files.
- **CodeChecker (`codechecker`)**: Verifies compliance with Moodle CodeSniffer standards.
- **PHPDoc (`phpdoc`)**: Validates documentation comments.
- **PHPUnit (`phpunit`)**: Executes unit tests in `tests/rule_test.php`.

---

## 🛡️ Zero-Dependency Quality Gates

In accordance with Antigravity AI Engineering Architecture (Pillar 4), this repository includes an autonomous zero-dependency verification harness under `tests/`:

```bash
# Run all quality gates (Syntax, i18n parity, Moodle contracts, and Smoke tests)
npm run test:gates

# Run individual gates
npm run check:syntax     # php -l verification across all PHP source files
npm run check:i18n       # 100% parity verification between English and Arabic strings
npm run check:contract   # Moodle metadata, API return types, and privacy provider verification
npm run smoke            # Plugin integrity, scoped CSS, and security checks
```

---

## 🤖 Multi-Agent AI Advisory Framework

Run consultations through the local AI advisory runner connected to `agency-agents` and OpenCode:

```bash
# Consult Moodle plugin architect
npm run adviser -- -a architect "Audit external service return type contracts for mobile app"

# Consult security auditor
npm run adviser -- -a security "Verify session token HMAC validation and brute-force defenses"
```

---

## 📋 Changelog

### [v1.7.2] - 2026-09-26
* **Security & Cleanliness**: Eliminated direct `$_SESSION` usage across the plugin in favor of standard Moodle global `$SESSION`.
* **Security & Standards**: Refined parameter cleaning in `token_manager.php` and external service return types (`get_launch_token.php`) from `PARAM_RAW` to strictly typed `PARAM_NOTAGS`, `PARAM_RAW_TRIMMED`, and `PARAM_URL`.
* **URL Compliance**: Transitioned URL generation in `build_launch_url()` to standard Moodle `moodle_url` objects.
* **i18n Parity**: Synchronized English and Arabic string keys (`regeneratepassword`, `alloweddomains`) achieving 100% language parity across all 84 keys.
* **Quality Harness**: Integrated Zero-Dependency Quality Gates (`tests/run-all.js`, `tests/syntax-check.js`, `tests/i18n-check.js`, `tests/contract-check.js`, `tests/smoke.js`).
* **Multi-Agent Advisory**: Added `scripts/opencode-adviser.js` connected to `agency-agents` and OpenCode models.

### [v1.7.1] - 2026-09-08
* **CI & Standards Compliance:**
  * **Table Prefix Standardization:** Renamed sub-tables in db/install.xml to strictly comply with Moodle's component prefix requirement (quizaccess_sanad_lockdown_se, quizaccess_sanad_lockdown_vi, quizaccess_sanad_lockdown_de) with automatic migration in db/upgrade.php (@ 2026090800).
  * **PHPDoc Checker Fixes:** Added complete @param and @return documentation to helper functions in monitor.php.
  * **CodeChecker Compliance:** Fixed operator spacing in db/services.php, removed PSR-12 blank line after opening brace in classes/external/get_launch_token.php, and cleaned inline comment formatting in pi.php.

### [v1.7.0] - 2026-08-26
* **Security:** Short codes now carry **8 hex chars** of HMAC (~4 billion combinations) for newly issued sessions; legacy 4-char codes remain valid until they expire naturally — brute-forcing codes is no longer practical.
* **Security (Migration @ 2026082700):** Violations table gained a client `ip` column; `resolve_code` now enforces an additional **IP-based rate limit** (50 failures / 5 min) so rotating spoofed device ids cannot bypass the per-device cap, while generous enough to keep whole classrooms behind NAT unaffected.
* **Added:** Optional **HMAC app-authentication gate** — new admin setting `appsharedsecret`. When configured, every API call must present `X-SANAD-TIMESTAMP` + `X-SANAD-SIGNATURE` (HMAC-SHA256). Empty by default; enable once the kiosk app ships signing support to make header spoofing impossible.

### [v1.6.0] - 2026-08-26
* **Security (High):** Exit passwords are now stored as **bcrypt hashes** instead of plaintext. A migration step (`db/upgrade.php` @ 2026082600) hashes all existing legacy values in place — student exit codes keep working without any action.
* **Added:** "Generate new exit password" button on the teacher monitor page. The plain code is revealed **exactly once** (session flash) with a write-it-down warning; only the hash is persisted afterwards.
* **Changed:** `save_settings()` now always stores a bcrypt hash of freshly generated codes and no longer regenerates when it encounters an old hash.
* **Kept:** `api_handler.php` retains a dual verification path (bcrypt + literal compare) purely as a backward-compatibility bridge during migration windows.

### [v1.5.0] - 2026-08-24
* **Added:** Mobile Web Service API (`quizaccess_sanad_lockdown_get_launch_token`) supporting direct session token generation and Deep Link URL formulation for SANAD Learn mobile integration.
* **Added:** `db/services.php` auto-registering the external service in `MOODLE_OFFICIAL_MOBILE_SERVICE`.
* **Enhanced:** Cross-application token resolution supporting both QR scanning, short code resolution, and direct Intent / Deep Link invocation (`sanad-kiosk://launch-quiz`).

---

## 📄 License & Credits

* **Copyright:** © 2026 Mahmoud Salem
* **License:** Proprietary & Confidential. All rights reserved.
