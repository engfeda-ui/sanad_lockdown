<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * API endpoint for Sanad Secure Browser integration.
 * Handles heartbeats, remote violation logging, and supervisor exit verification.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use quizaccess_sanad_lockdown\token_manager;
use quizaccess_sanad_lockdown\violation_logger;

// Set API response headers.
header('Content-Type: application/json; charset=utf-8');

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// 1. Verify App Identification.
$appid = $_SERVER['HTTP_X_Sanad_APP'] ?? '';
if ($appid !== token_manager::EXPECTED_APP_ID) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Invalid App Client']);
    exit;
}

// Get raw JSON input.
$inputraw = file_get_contents('php://input');
$data = json_decode($inputraw, true);

// FIX: Use strict null check — json_decode returns null on error, not false.
// Previously `if (!$data)` would reject valid JSON values like `0` or `false`.
if ($data === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request: Invalid JSON']);
    exit;
}

$action = $data['action'] ?? '';

if (empty($action)) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request: Missing action parameter']);
    exit;
}

// 2. Extract Device ID from HTTP Headers.
$deviceid = $_SERVER['HTTP_X_Sanad_DEVICE_ID'] ?? '';
if (empty($deviceid)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Missing Device ID Header']);
    exit;
}

global $DB, $USER;

// Fallback action resolve_code does not require quizid or secure token headers.
if ($action === 'resolve_code') {
    $code = trim($data['code'] ?? '');
    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad Request: Missing short code parameter']);
        exit;
    }

    // Rate limiting: max 5 failed attempts per device within 5 minutes.
    $ratelimitwindow = time() - 300;
    $failedattempts  = $DB->count_records_select(
        'quizaccess_sanad_violations',
        "deviceid = :deviceid AND violationtype = 'failed_code_resolution' AND timecreated > :window",
        ['deviceid' => $deviceid, 'window' => $ratelimitwindow]
    );
    if ($failedattempts >= 5) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many failed code resolution attempts. Try again later.']);
        exit;
    }

    $session = token_manager::verify_short_code($code);
    if (!$session) {
        violation_logger::log(0, 0, 'failed_code_resolution', $deviceid, ['code' => $code]);
        http_response_code(404);
        echo json_encode(['error' => 'Invalid or expired access code']);
        exit;
    }

    if (time() > $session->timeexpires) {
        $DB->delete_records('quizaccess_sanad_sessions', ['id' => $session->id]);
        http_response_code(410);
        echo json_encode(['error' => 'Access code has expired']);
        exit;
    }

    // Bind device ID on resolution.
    if (empty($session->deviceid)) {
        $session->deviceid = $deviceid;
        $DB->set_field('quizaccess_sanad_sessions', 'deviceid', $deviceid, ['id' => $session->id]);
    } else if ($session->deviceid !== $deviceid) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: Device Mismatch']);
        exit;
    }

    $cm = get_coursemodule_from_instance('quiz', $session->quizid);
    $cmid = $cm ? (int)$cm->id : 0;
    $launchurl = token_manager::build_launch_url($session->quizid, $cmid, $session->token);

    echo json_encode([
        'status'    => 'resolved',
        'token'     => $session->token,
        'quizid'    => (string)$session->quizid,
        'start_url' => $launchurl,
    ]);
    exit;
}

if ($action === 'check_license') {
    $hardwareid = trim($data['hardware_id'] ?? '');
    $devicemodel = trim($data['device_model'] ?? '');
    $devicebrand = trim($data['device_brand'] ?? '');

    if (empty($hardwareid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad Request: Missing hardware_id parameter']);
        exit;
    }

    // Standardize hardware ID to uppercase and remove spaces/dashes.
    $hardwareid = strtoupper(str_replace([' ', '-'], '', $hardwareid));

    // Try to find the device.
    $device = $DB->get_record('quizaccess_sanad_devices', ['hardwareid' => $hardwareid]);

    if (!$device) {
        // Register the device automatically in a pending state.
        $device = new stdClass();
        $device->hardwareid = $hardwareid;
        $device->devicemodel = $devicemodel;
        $device->devicebrand = $devicebrand;
        $device->status = 0; // 0 = Pending
        $device->expirydate = 0;
        $device->timecreated = time();
        $device->timemodified = time();
        $device->id = $DB->insert_record('quizaccess_sanad_devices', $device);
    }

    $statusstr = 'pending';
    if ($device->status == 1) {
        if ($device->expirydate > time()) {
            $statusstr = 'active';
        } else {
            $statusstr = 'expired';
        }
    } else if ($device->status == 2) {
        $statusstr = 'suspended';
    }

    echo json_encode([
        'status' => $statusstr,
        'hardware_id' => $hardwareid,
        'expiry_date' => (int)$device->expirydate,
    ]);
    exit;
}

// For all other actions (heartbeat, log_violation, verify_exit).
$quizid = isset($data['quizid']) ? (int)$data['quizid'] : 0;
$token  = $_SERVER['HTTP_X_Sanad_SECURE_TOKEN'] ?? '';

if (empty($quizid) || empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request: Missing parameters or headers']);
    exit;
}

// Fetch session + lockdown settings in one JOIN query.
$session = $DB->get_record_sql(
    'SELECT s.*, l.tokenexpiry AS quiz_tokenexpiry,
            l.exitpassword AS quiz_exitpassword,
            l.alloweddomains AS quiz_alloweddomains
       FROM {quizaccess_sanad_sessions} s
       LEFT JOIN {quizaccess_sanad_lockdown} l ON l.quizid = s.quizid
      WHERE s.token = :token AND s.quizid = :quizid',
    ['token' => $token, 'quizid' => $quizid]
);

if (!$session) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Session not found']);
    exit;
}

// If the token belongs to a teacher (someone with preview/viewreports capability),
// we redirect to the student's actual session using the device ID.
$cm = get_coursemodule_from_instance('quiz', $quizid);
if ($cm) {
    $context = \context_module::instance($cm->id);
    if (
        has_capability('mod/quiz:preview', $context, $session->userid) ||
        has_capability('mod/quiz:viewreports', $context, $session->userid)
    ) {
        $studentsession = $DB->get_record_sql(
            'SELECT s.*, l.tokenexpiry AS quiz_tokenexpiry,
                    l.exitpassword AS quiz_exitpassword,
                    l.alloweddomains AS quiz_alloweddomains
               FROM {quizaccess_sanad_sessions} s
               LEFT JOIN {quizaccess_sanad_lockdown} l ON l.quizid = s.quizid
              WHERE s.deviceid = :deviceid AND s.quizid = :quizid',
            ['deviceid' => $deviceid, 'quizid' => $quizid]
        );
        if ($studentsession) {
            $session = $studentsession;
        }
    }
}

// Check if token has expired.
if (time() > $session->timeexpires) {
    $DB->delete_records('quizaccess_sanad_sessions', ['id' => $session->id]);
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Session has expired']);
    exit;
}

// Enforce device lock binding.
if (empty($session->deviceid)) {
    // Bind device ID on first API request.
    $session->deviceid = $deviceid;
    $DB->set_field('quizaccess_sanad_sessions', 'deviceid', $deviceid, ['id' => $session->id]);
} else if ($session->deviceid !== $deviceid) {
    violation_logger::log(
        $quizid,
        $session->userid,
        violation_logger::TYPE_DEVICE_MISMATCH,
        $deviceid,
        ['details' => 'API request device mismatch', 'bound_device' => $session->deviceid]
    );
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Device Mismatch']);
    exit;
}

// Validate that the session user actually exists and is not deleted.
$sessionuser = $DB->get_record('user', ['id' => $session->userid, 'deleted' => 0]);
if (!$sessionuser) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Session user not found']);
    exit;
}

// Set global USER to the session owner so Moodle logging works correctly.
$USER = $sessionuser;

// 3. Process Actions.
switch ($action) {
    case 'heartbeat':
        // Extend token expiry using the quiz-configured tokenexpiry value.
        // Minimum floor of 300 seconds for safety.
        $extendby = max(300, (int)($session->quiz_tokenexpiry ?? 300));
        $session->timeexpires = time() + $extendby;
        $DB->set_field('quizaccess_sanad_sessions', 'timeexpires', $session->timeexpires, ['id' => $session->id]);

        // Parse allowed domains — already fetched via JOIN, no extra query needed.
        $alloweddomains = [];
        if (!empty($session->quiz_alloweddomains)) {
            $lines = explode("\n", str_replace("\r", "", $session->quiz_alloweddomains));
            foreach ($lines as $line) {
                $cleaned = trim($line);
                if ($cleaned !== '') {
                    $alloweddomains[] = $cleaned;
                }
            }
        }

        echo json_encode([
            'status'          => 'acknowledged',
            'expires'         => $session->timeexpires,
            'allowed_domains' => $alloweddomains,
        ]);
        break;

    case 'log_violation':
        $violationtype = $data['type'] ?? 'unknown_violation';
        $details       = $data['details'] ?? '';

        violation_logger::log(
            $quizid,
            $session->userid,
            $violationtype,
            $deviceid,
            ['api_log' => true, 'raw_details' => $details]
        );

        echo json_encode(['status' => 'logged']);
        break;

    case 'verify_exit':
        $password = $data['password'] ?? '';

        // Rate limiting: max 5 failed attempts per quiz and device within 5 minutes.
        $ratelimitwindow = time() - 300;
        $failedattempts  = $DB->count_records_select(
            'quizaccess_sanad_violations',
            "quizid = :quizid AND deviceid = :deviceid "
            . "AND violationtype = 'invalid_exit_password_attempt' AND timecreated > :window",
            ['quizid' => $quizid, 'deviceid' => $deviceid, 'window' => $ratelimitwindow]
        );
        if ($failedattempts >= 5) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many attempts. Try again later.']);
            break;
        }

        // Use settings already fetched by the JOIN — no second DB query needed.
        $exitpasswordhash = $session->quiz_exitpassword ?? null;

        if (empty($exitpasswordhash)) {
            // No exit password configured — allow exit by default.
            token_manager::revoke($quizid, $session->userid);
            echo json_encode(['status' => 'verified', 'info' => 'No exit password set']);
            break;
        }

        if (password_verify($password, $exitpasswordhash)) {
            // Correct password — revoke session and allow exit.
            token_manager::revoke($quizid, $session->userid);
            echo json_encode(['status' => 'verified']);
        } else {
            // Wrong password — log violation (also counted by rate limiter above).
            violation_logger::log(
                $quizid,
                $session->userid,
                'invalid_exit_password_attempt',
                $deviceid,
                ['remaining_attempts' => max(0, 4 - $failedattempts)]
            );
            http_response_code(401);
            echo json_encode([
                'error'              => 'Incorrect exit password',
                'remaining_attempts' => max(0, 4 - $failedattempts),
            ]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Bad Request: Unknown action']);
        break;
}
