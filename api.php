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
 * API endpoint for EWA Secure Browser integration.
 * Handles heartbeats, remote violation logging, and supervisor exit verification.
 *
 * @package   quizaccess_ewa_lockdown
 * @copyright 2026 Mahmoud Salem <m.salem@ewa.bh>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use quizaccess_ewa_lockdown\token_manager;
use quizaccess_ewa_lockdown\violation_logger;

// Set API response headers.
header('Content-Type: application/json; charset=utf-8');

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// 1. Verify App Identification.
$appid = $_SERVER['HTTP_X_EWA_APP'] ?? '';
if ($appid !== token_manager::EXPECTED_APP_ID) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Invalid App Client']);
    exit;
}

// Get raw JSON input.
$inputraw = file_get_contents('php://input');
$data = json_decode($inputraw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request: Invalid JSON']);
    exit;
}

$action = $data['action'] ?? '';
$quizid = isset($data['quizid']) ? (int)$data['quizid'] : 0;

if (empty($action) || empty($quizid)) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request: Missing parameters']);
    exit;
}

// 2. Extract Token and Device ID from HTTP Headers.
$token = $_SERVER['HTTP_X_EWA_SECURE_TOKEN'] ?? '';
$deviceid = $_SERVER['HTTP_X_EWA_DEVICE_ID'] ?? '';

if (empty($token) || empty($deviceid)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Missing Security Headers']);
    exit;
}

// Check session token validity.
global $DB, $USER;

// Fetch session record from database.
$session = $DB->get_record('quizaccess_ewa_sessions', [
    'token' => $token,
    'quizid' => $quizid,
]);

if (!$session) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Session not found']);
    exit;
}

// Check if token has expired.
if (time() > $session->timeexpires) {
    $DB->delete_records('quizaccess_ewa_sessions', ['id' => $session->id]);
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Session has expired']);
    exit;
}

// Enforce device lock binding.
if (empty($session->deviceid)) {
    // Bind device ID on first API request.
    $session->deviceid = $deviceid;
    $DB->update_record('quizaccess_ewa_sessions', $session);
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

// Set global USER to the session owner so Moodle logging works correctly.
$USER = $DB->get_record('user', ['id' => $session->userid]);

// 3. Process Actions.
switch ($action) {
    case 'heartbeat':
        // Extend token expiry by 2 minutes on every successful heartbeat.
        $session->timeexpires = time() + 120;
        $DB->update_record('quizaccess_ewa_sessions', $session);

        echo json_encode(['status' => 'acknowledged', 'expires' => $session->timeexpires]);
        break;

    case 'log_violation':
        $violationtype = $data['type'] ?? 'unknown_violation';
        $details = $data['details'] ?? '';

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

        // --- Rate Limiting: max 5 failed attempts per quiz+device within 5 minutes ---
        $ratelimit_window = time() - 300; // 5 minutes ago
        $failed_attempts = $DB->count_records_select(
            'quizaccess_ewa_violations',
            "quizid = :quizid AND deviceid = :deviceid AND violationtype = 'invalid_exit_password_attempt' AND timecreated > :window",
            ['quizid' => $quizid, 'deviceid' => $deviceid, 'window' => $ratelimit_window]
        );
        if ($failed_attempts >= 5) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many attempts. Try again later.']);
            break;
        }
        // -------------------------------------------------------------------------

        // Fetch quiz lockdown settings.
        $settings = $DB->get_record('quizaccess_ewa_lockdown', ['quizid' => $quizid]);

        if (!$settings || empty($settings->exitpassword)) {
            // No exit password configured, allow exit by default.
            echo json_encode(['status' => 'verified', 'info' => 'No exit password set']);
            token_manager::revoke($quizid, $session->userid);
            break;
        }

        if (password_verify($password, $settings->exitpassword)) {
            // Correct password - Revoke session and allow exit.
            token_manager::revoke($quizid, $session->userid);
            echo json_encode(['status' => 'verified']);
        } else {
            // Wrong password — log violation (also counted by rate limiter above).
            violation_logger::log(
                $quizid,
                $session->userid,
                'invalid_exit_password_attempt',
                $deviceid,
                ['remaining_attempts' => max(0, 4 - $failed_attempts)]
            );
            http_response_code(401);
            echo json_encode(['error' => 'Incorrect exit password', 'remaining_attempts' => max(0, 4 - $failed_attempts)]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Bad Request: Unknown action']);
        break;
}
