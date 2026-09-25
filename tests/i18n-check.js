"use strict";
/**
 * SANAD Lockdown (Moodle Plugin) — i18n Parity Check Gate (Zero Dependencies)
 * Part of Antigravity AI Engineering Architecture (Pillar 4: Harness Engineering).
 */

const fs = require("fs");
const path = require("path");

const ROOT = path.join(__dirname, "..");
const enFile = path.join(ROOT, "lang", "en", "quizaccess_sanad_lockdown.php");
const arFile = path.join(ROOT, "lang", "ar", "quizaccess_sanad_lockdown.php");

function extractKeys(filePath) {
  const content = fs.readFileSync(filePath, "utf-8");
  const regex = /\$string\['([^']+)'\]/g;
  const keys = new Set();
  let match;
  while ((match = regex.exec(content)) !== null) {
    keys.add(match[1]);
  }
  return keys;
}

console.log("=== Checking i18n String Parity (EN vs AR) ===");

const enKeys = extractKeys(enFile);
const arKeys = extractKeys(arFile);

let missingInAr = [];
let missingInEn = [];

for (const k of enKeys) {
  if (!arKeys.has(k)) missingInAr.push(k);
}
for (const k of arKeys) {
  if (!enKeys.has(k)) missingInEn.push(k);
}

if (missingInAr.length > 0) {
  console.error("  FAIL Missing keys in Arabic:", missingInAr);
} else {
  console.log(`  PASS All English keys (${enKeys.size}) present in Arabic`);
}

if (missingInEn.length > 0) {
  console.error("  FAIL Missing keys in English:", missingInEn);
} else {
  console.log(`  PASS All Arabic keys (${arKeys.size}) present in English`);
}

const totalFailures = missingInAr.length + missingInEn.length;
console.log(totalFailures ? `\ni18n CHECK FAILED (${totalFailures} discrepancies)` : `\ni18n CHECK PASSED (100% parity across ${enKeys.size} strings)`);
process.exit(totalFailures ? 1 : 0);
