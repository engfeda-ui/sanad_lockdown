"use strict";
/**
 * SANAD Lockdown (Moodle Plugin) — Syntax Check Gate (Zero Dependencies)
 * Part of Antigravity AI Engineering Architecture (Pillar 4: Harness Engineering).
 */

const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

const ROOT = path.join(__dirname, "..");
let failures = 0;

function getAllPhpFiles(dir) {
  let results = [];
  const list = fs.readdirSync(dir);
  for (const file of list) {
    if (file === "node_modules" || file === ".git") continue;
    const fullPath = path.join(dir, file);
    const stat = fs.statSync(fullPath);
    if (stat && stat.isDirectory()) {
      results = results.concat(getAllPhpFiles(fullPath));
    } else if (file.endsWith(".php")) {
      results.push(fullPath);
    }
  }
  return results;
}

console.log("=== Checking PHP Syntax across all plugin files ===");

const phpFiles = getAllPhpFiles(ROOT);

for (const file of phpFiles) {
  const relPath = path.relative(ROOT, file);
  try {
    execSync(`php -l "${file}"`, { stdio: "pipe" });
    console.log(`  PASS ${relPath}`);
  } catch (err) {
    failures++;
    console.error(`  FAIL ${relPath}: Syntax Error!`);
  }
}

console.log(failures ? `\nPHP SYNTAX CHECK FAILED (${failures} errors)` : `\nPHP SYNTAX CHECK PASSED (0 errors in ${phpFiles.length} files)`);
process.exit(failures ? 1 : 0);
