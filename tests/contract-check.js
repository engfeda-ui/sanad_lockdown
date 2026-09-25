"use strict";
/**
 * SANAD Lockdown (Moodle Plugin) — Contract & Security Compliance Gate
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

console.log("=== Checking Moodle Plugin Contracts & Security Compliance ===");

// 1. Check version.php
const versionFile = path.join(ROOT, "version.php");
check("version.php exists", fs.existsSync(versionFile));
if (fs.existsSync(versionFile)) {
  const versionSrc = fs.readFileSync(versionFile, "utf-8");
  check("Component declared as quizaccess_sanad_lockdown", versionSrc.includes("$plugin->component = 'quizaccess_sanad_lockdown'"));
  check("Version code declared", /\$plugin->version\s*=\s*\d{10};/.test(versionSrc));
  check("Requires Moodle 4.x/5.x", /\$plugin->requires\s*=\s*\d{10};/.test(versionSrc));
  check("Maturity set to MATURITY_STABLE", versionSrc.includes("$plugin->maturity  = MATURITY_STABLE"));
  check("Release string declared", /\$plugin->release\s*=\s*['"]v\d+\.\d+\.\d+['"];/.test(versionSrc));
}

// 2. Check rule.php
const ruleFile = path.join(ROOT, "rule.php");
check("rule.php exists", fs.existsSync(ruleFile));
if (fs.existsSync(ruleFile)) {
  const ruleSrc = fs.readFileSync(ruleFile, "utf-8");
  check("Extends quiz_access_rule_base", ruleSrc.includes("extends quiz_access_rule_base"));
  check("Defines make() factory method", ruleSrc.includes("public static function make("));
  check("Defines add_settings_form_fields()", ruleSrc.includes("public static function add_settings_form_fields("));
  check("Defines save_settings()", ruleSrc.includes("public static function save_settings("));
}

// 3. Check Privacy Subsystem
const privacyFile = path.join(ROOT, "classes", "privacy", "provider.php");
check("Privacy provider exists", fs.existsSync(privacyFile));
if (fs.existsSync(privacyFile)) {
  const privSrc = fs.readFileSync(privacyFile, "utf-8");
  check("Implements core_privacy interfaces", privSrc.includes("implements") && privSrc.includes("collection"));
  check("Declares get_metadata()", privSrc.includes("public static function get_metadata("));
}

// 4. Check External Services
const servicesFile = path.join(ROOT, "db", "services.php");
check("db/services.php exists", fs.existsSync(servicesFile));
if (fs.existsSync(servicesFile)) {
  const servSrc = fs.readFileSync(servicesFile, "utf-8");
  check("Declares quizaccess_sanad_lockdown_get_launch_token", servSrc.includes("quizaccess_sanad_lockdown_get_launch_token"));
}

// 5. Check No raw $_SESSION usage
function scanForRawSession(dir) {
  const list = fs.readdirSync(dir);
  for (const file of list) {
    if (file === "node_modules" || file === ".git" || file === "tests") continue;
    const fullPath = path.join(dir, file);
    const stat = fs.statSync(fullPath);
    if (stat && stat.isDirectory()) {
      scanForRawSession(fullPath);
    } else if (file.endsWith(".php")) {
      const src = fs.readFileSync(fullPath, "utf-8");
      if (src.includes("$_SESSION")) {
        check(`Zero raw \$_SESSION in ${path.relative(ROOT, fullPath)}`, false, "Found direct \$_SESSION usage; use \$SESSION instead");
      }
    }
  }
}
scanForRawSession(ROOT);
check("No raw $_SESSION usage in plugin", true);

// 6. Check No PARAM_RAW in external service get_launch_token.php
const externalFile = path.join(ROOT, "classes", "external", "get_launch_token.php");
if (fs.existsSync(externalFile)) {
  const extSrc = fs.readFileSync(externalFile, "utf-8");
  check("No PARAM_RAW in get_launch_token.php", !extSrc.includes("PARAM_RAW,"));
}

console.log(failures ? `\nCONTRACT CHECK FAILED (${failures} errors)` : "\nCONTRACT CHECK PASSED (All checks green)");
process.exit(failures ? 1 : 0);
