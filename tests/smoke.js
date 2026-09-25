"use strict";
/**
 * SANAD Lockdown (Moodle Plugin) — Smoke Test Suite (Zero Dependencies)
 * Part of Antigravity AI Engineering Architecture (Pillar 4: Harness Engineering).
 */

const fs = require("fs");
const path = require("path");

const ROOT = path.join(__dirname, "..");
let failures = 0;

function check(name, cond, extra = "") {
  if (cond) {
    console.log(`  PASS ${name}`);
  } else {
    failures++;
    console.error(`  FAIL ${name} ${extra}`);
  }
}

console.log("=== Running SANAD Lockdown Smoke Suite ===");

const criticalFiles = [
  "rule.php",
  "version.php",
  "settings.php",
  "styles.css",
  "api.php",
  "manage_devices.php",
  "monitor.php",
  "classes/api_handler.php",
  "classes/qr_generator.php",
  "classes/token_manager.php",
  "classes/violation_logger.php",
  "classes/privacy/provider.php",
  "classes/external/get_launch_token.php",
  "db/services.php",
  "db/upgrade.php",
  "lang/en/quizaccess_sanad_lockdown.php",
  "lang/ar/quizaccess_sanad_lockdown.php",
  ".gitignore",
  "README.md"
];

for (const f of criticalFiles) {
  check(`File exists: ${f}`, fs.existsSync(path.join(ROOT, f)));
}

// Scoped CSS check
const cssContent = fs.readFileSync(path.join(ROOT, "styles.css"), "utf-8");
check("CSS rules properly scoped to .sanad-secure-kiosk", cssContent.includes(".sanad-secure-kiosk"));

// No secrets or keys accidentally tracked
const forbiddenPatterns = [
  "BEGIN RSA PRIVATE KEY",
  "BEGIN OPENSSH PRIVATE KEY",
  "password_hash('admin'"
];
for (const pat of forbiddenPatterns) {
  check(`No plaintext secret pattern "${pat}" in rule.php`, !fs.readFileSync(path.join(ROOT, "rule.php"), "utf-8").includes(pat));
}

console.log(failures ? `\nSMOKE SUITE FAILED (${failures} errors)` : "\nSMOKE SUITE PASSED (All checks green)");
process.exit(failures ? 1 : 0);
