"use strict";
/**
 * Master Verification Suite Runner — SANAD Lockdown
 * Part of Antigravity AI Engineering Architecture (Pillar 4: Harness Engineering).
 */

const { execSync } = require("child_process");
const path = require("path");

const ROOT = path.join(__dirname, "..");
const gates = [
  { name: "PHP Syntax Check", script: "tests/syntax-check.js" },
  { name: "i18n Parity Check", script: "tests/i18n-check.js" },
  { name: "Moodle Contract & Security Check", script: "tests/contract-check.js" },
  { name: "Plugin Smoke Suite", script: "tests/smoke.js" }
];

console.log("==================================================");
console.log("🛡️ SANAD Lockdown Plugin — Quality Gates Runner");
console.log("==================================================\n");

let failedGates = 0;

for (const gate of gates) {
  console.log(`▶ Running: node ${gate.script}`);
  try {
    execSync(`node "${path.join(ROOT, gate.script)}"`, {
      cwd: ROOT,
      stdio: "inherit"
    });
    console.log();
  } catch (err) {
    failedGates++;
    console.error(`\n❌ Gate FAILED: ${gate.name}\n`);
    break;
  }
}

if (failedGates > 0) {
  console.error("==================================================");
  console.error("❌ QUALITY GATES FAILED: Halting deployment!");
  console.error("==================================================");
  process.exit(1);
} else {
  console.log("==================================================");
  console.log("✅ ALL-GATES GREEN: All verification checks passed!");
  console.log("==================================================");
  process.exit(0);
}
