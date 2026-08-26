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
 * API handler for Sanad Secure Browser integration.
 *
 * Routes and processes all actions received by api.php:
 * resolve_code, check_license, heartbeat, log_violation, verify_exit.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_sanad_lockdown;

/**
 * Central dispatcher for all API actions from the Sanad Secure Browser app.
 */
class api_handler {
    /**
     * Dispatch an incoming action to the appropriate handler method.
     *
     * @param string $action   The action name from the JSON payload.
     * @param array  $data     Decoded JSON payload.
     * @param string $deviceid Device fingerprint from request headers.
     */
    public static function dispatch(string $action, array $data, string $deviceid): void {
        switch ($action) {
            case 'resolve_code':
                self::handle_resolve_code($data, $deviceid);
                break;
            case 'check_license':
                self::handle_check_license($data);
                break;
            case 'check_update':
                self::handle_check_update();
                break;
            case 'heartbeat':
            case 'log_violation':
            case 'verify_exit':
                self::handle_authenticated_action($action, $data, $deviceid);
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Bad Request: Unknown action']);
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Unauthenticated actions (no session token required).
    // -------------------------------------------------------------------------.

    /**
     * Resolve a human-readable short code into a session launch URL.
     *
     * @param array  $data     Decoded JSON payload.
     * @param string $deviceid Device fingerprint.
     */
    private static function handle_resolve_code(array $data, string $deviceid): void {
        global $DB;

        $code = trim($data['code'] ?? '');
        if (empty($code)) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request: Missing short code parameter']);
            return;
        }

        // Rate limiting: max 5 failed attempts per device within 5 minutes,
        // plus an IP-based abuse floor so rotating device ids does not bypass it
        // (a generous cap keeps whole classrooms behind NAT unaffected).
        $ratelimitwindow = time() - 300;
        $clientip = getremoteaddr();

        $failedattempts  = $DB->count_records_select(
            'quizaccess_sanad_violations',
            "deviceid = :deviceid AND violationtype = 'failed_code_resolution' AND timecreated > :window",
            ['deviceid' => $deviceid, 'window' => $ratelimitwindow]
        );
        if ($failedattempts >= 5) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many failed code resolution attempts. Try again later.']);
            return;
        }

        $ipattempts = $DB->count_records_select(
            'quizaccess_sanad_violations',
            "ip = :ip AND violationtype = 'failed_code_resolution' AND timecreated > :window",
            ['ip' => $clientip, 'window' => $ratelimitwindow]
        );
        if ($ipattempts >= 50) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many requests from this network. Try again later.']);
            return;
        }

        $session = token_manager::verify_short_code($code);
        if (!$session) {
            violation_logger::log(0, 0, 'failed_code_resolution', $deviceid, ['code' => $code]);
            http_response_code(404);
            echo json_encode(['error' => 'Invalid or expired access code']);
            return;
        }

        if (time() > $session->timeexpires) {
            $DB->delete_records('quizaccess_sanad_sessions', ['id' => $session->id]);
            http_response_code(410);
            echo json_encode(['error' => 'Access code has expired']);
            return;
        }

        // Bind device ID on resolution.
        if (empty($session->deviceid)) {
            $session->deviceid = $deviceid;
            $DB->set_field('quizaccess_sanad_sessions', 'deviceid', $deviceid, ['id' => $session->id]);
        } else if ($session->deviceid !== $deviceid) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: Device Mismatch']);
            return;
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
    }

    /**
     * Check (and auto-register) a device license by hardware ID.
     *
     * @param array $data Decoded JSON payload.
     */
    private static function handle_check_license(array $data): void {
        global $DB;

        $hardwareid  = trim($data['hardware_id'] ?? '');
        $devicemodel = trim($data['device_model'] ?? '');
        $devicebrand = trim($data['device_brand'] ?? '');

        if (empty($hardwareid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request: Missing hardware_id parameter']);
            return;
        }

        // Standardize hardware ID: uppercase, no spaces or dashes.
        $hardwareid = strtoupper(str_replace([' ', '-'], '', $hardwareid));

        $device = $DB->get_record('quizaccess_sanad_devices', ['hardwareid' => $hardwareid]);

        if (!$device) {
            // Auto-register in a pending state.
            $device               = new \stdClass();
            $device->hardwareid   = $hardwareid;
            $device->devicemodel  = $devicemodel;
            $device->devicebrand  = $devicebrand;
            $device->status       = 0; // 0 = Pending.
            $device->expirydate   = 0;
            $device->timecreated  = time();
            $device->timemodified = time();
            $device->id           = $DB->insert_record('quizaccess_sanad_devices', $device);
        }

        $statusstr = self::resolve_device_status($device);

        echo json_encode([
            'status'      => $statusstr,
            'hardware_id' => $hardwareid,
            'expiry_date' => (int)$device->expirydate,
        ]);
    }

    // -------------------------------------------------------------------------
    // Authenticated actions (require a valid session token).
    // -------------------------------------------------------------------------.

    /**
     * Load and verify the session, then dispatch to the correct action handler.
     *
     * @param string $action   The action name.
     * @param array  $data     Decoded JSON payload.
     * @param string $deviceid Device fingerprint.
     */
    private static function handle_authenticated_action(string $action, array $data, string $deviceid): void {
        global $DB, $USER;

        $quizid = isset($data['quizid']) ? (int)$data['quizid'] : 0;
        $token  = $_SERVER[token_manager::HEADER_TOKEN] ?? '';

        if (empty($quizid) || empty($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request: Missing parameters or headers']);
            return;
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
            // Session not found — allow exit only if the password still matches.
            if ($action === 'verify_exit') {
                self::exit_with_fallback_check($quizid, $data['password'] ?? '', $deviceid);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized: Session not found']);
            }
            return;
        }

        // Redirect teacher tokens to the student session using device ID.
        $session = self::maybe_redirect_teacher_session($session, $quizid, $deviceid);

        if (time() > $session->timeexpires) {
            $DB->delete_records('quizaccess_sanad_sessions', ['id' => $session->id]);
            if ($action === 'verify_exit') {
                self::exit_with_fallback_check($quizid, $data['password'] ?? '', $deviceid);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized: Session has expired']);
            }
            return;
        }

        // Enforce device lock binding.
        if (empty($session->deviceid)) {
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
            return;
        }

        // Validate session user.
        $sessionuser = $DB->get_record('user', ['id' => $session->userid, 'deleted' => 0]);
        if (!$sessionuser) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized: Session user not found']);
            return;
        }

        // Set global USER so Moodle logging works correctly.
        $USER = $sessionuser;

        switch ($action) {
            case 'heartbeat':
                self::handle_heartbeat($session);
                break;
            case 'log_violation':
                self::handle_log_violation($data, $session, $quizid, $deviceid);
                break;
            case 'verify_exit':
                self::handle_verify_exit($data, $session, $quizid, $deviceid);
                break;
        }
    }

    /**
     * Handle a heartbeat ping: extend the session expiry and return allowed domains.
     *
     * @param \stdClass $session The session record.
     */
    private static function handle_heartbeat(\stdClass $session): void {
        global $DB;

        $extendby = max(300, (int)($session->quiz_tokenexpiry ?? 300));
        $session->timeexpires = time() + $extendby;
        $DB->set_field('quizaccess_sanad_sessions', 'timeexpires', $session->timeexpires, ['id' => $session->id]);

        $alloweddomains = self::parse_allowed_domains($session->quiz_alloweddomains ?? '');

        echo json_encode([
            'status'          => 'acknowledged',
            'expires'         => $session->timeexpires,
            'allowed_domains' => $alloweddomains,
        ]);
    }

    /**
     * Handle a violation log request from the app.
     *
     * @param array     $data     Decoded JSON payload.
     * @param \stdClass $session  The session record.
     * @param int       $quizid   The quiz ID.
     * @param string    $deviceid Device fingerprint.
     */
    private static function handle_log_violation(array $data, \stdClass $session, int $quizid, string $deviceid): void {
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
    }

    /**
     * Handle an exit password verification request.
     *
     * @param array     $data     Decoded JSON payload.
     * @param \stdClass $session  The session record.
     * @param int       $quizid   The quiz ID.
     * @param string    $deviceid Device fingerprint.
     */
    private static function handle_verify_exit(array $data, \stdClass $session, int $quizid, string $deviceid): void {
        global $DB;

        // SECURITY: Reject empty password immediately — do not allow bypass via omitted field.
        $password = trim($data['password'] ?? '');
        if ($password === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request: Exit password is required']);
            return;
        }

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
            return;
        }

        $exitpasswordhash = $session->quiz_exitpassword ?? null;

        // SECURITY: If no exit password is configured, DENY exit — do not silently approve.
        // A quiz without a configured exit password remains locked; only an admin can
        // force-terminate the session server-side via the monitor page.
        if (empty($exitpasswordhash)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: No exit password configured for this exam']);
            return;
        }

        $verified = password_verify($password, $exitpasswordhash) || ($password === $exitpasswordhash);
        if ($verified) {
            token_manager::revoke($quizid, $session->userid);
            echo json_encode(['status' => 'verified']);
        } else {
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
    }

    // -------------------------------------------------------------------------
    // Private helper methods.
    // -------------------------------------------------------------------------.

    /**
     * Verify exit password directly from the lockdown settings (session-independent fallback).
     * Used when the session has already been deleted or expired.
     *
     * Applies the same guards as handle_verify_exit():
     * - Rejects empty passwords immediately.
     * - Denies exit when no password is configured (no silent bypass).
     * - Enforces rate limiting via the violations table.
     *
     * @param int    $quizid   The quiz ID.
     * @param string $password The plain-text password to verify.
     * @param string $deviceid Device fingerprint for rate limiting.
     */
    private static function exit_with_fallback_check(int $quizid, string $password, string $deviceid = ''): void {
        global $DB;

        // SECURITY: Reject empty password immediately.
        $password = trim($password);
        if ($password === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request: Exit password is required']);
            return;
        }

        // Rate limiting: max 5 failed attempts per quiz and device within 5 minutes.
        if ($deviceid !== '') {
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
                return;
            }
        }

        $lockdown         = $DB->get_record('quizaccess_sanad_lockdown', ['quizid' => $quizid]);
        $exitpasswordhash = $lockdown ? $lockdown->exitpassword : null;

        // SECURITY: If no exit password is configured, DENY exit — do not silently approve.
        if (empty($exitpasswordhash)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: No exit password configured for this exam']);
            return;
        }

        $verified = password_verify($password, $exitpasswordhash) || ($password === $exitpasswordhash);
        if ($verified) {
            echo json_encode(['status' => 'verified']);
        } else {
            if ($deviceid !== '') {
                violation_logger::log($quizid, 0, 'invalid_exit_password_attempt', $deviceid);
            }
            http_response_code(401);
            echo json_encode(['error' => 'Incorrect exit password']);
        }
    }

    /**
     * If the session belongs to a teacher, swap it for the student's session on this device.
     *
     * @param \stdClass $session  Current session record.
     * @param int       $quizid   The quiz ID.
     * @param string    $deviceid Device fingerprint.
     * @return \stdClass The original or swapped session record.
     */
    private static function maybe_redirect_teacher_session(\stdClass $session, int $quizid, string $deviceid): \stdClass {
        global $DB;

        $cm = get_coursemodule_from_instance('quiz', $quizid);
        if (!$cm) {
            return $session;
        }

        $context = \context_module::instance($cm->id);
        $isteacher = has_capability('mod/quiz:preview', $context, $session->userid)
            || has_capability('mod/quiz:viewreports', $context, $session->userid);

        if (!$isteacher) {
            return $session;
        }

        $studentsession = $DB->get_record_sql(
            'SELECT s.*, l.tokenexpiry AS quiz_tokenexpiry,
                    l.exitpassword AS quiz_exitpassword,
                    l.alloweddomains AS quiz_alloweddomains
               FROM {quizaccess_sanad_sessions} s
               LEFT JOIN {quizaccess_sanad_lockdown} l ON l.quizid = s.quizid
              WHERE s.deviceid = :deviceid AND s.quizid = :quizid',
            ['deviceid' => $deviceid, 'quizid' => $quizid]
        );

        return $studentsession ?: $session;
    }

    /**
     * Parse newline-separated allowed domains into a clean array.
     *
     * @param string $raw Raw string from the database.
     * @return string[] Cleaned array of domain strings.
     */
    private static function parse_allowed_domains(string $raw): array {
        if (empty($raw)) {
            return [];
        }
        $domains = [];
        foreach (explode("\n", str_replace("\r", '', $raw)) as $line) {
            $cleaned = trim($line);
            if ($cleaned !== '') {
                $domains[] = $cleaned;
            }
        }
        return $domains;
    }

    /**
     * Resolve a device record into a human-readable status string.
     *
     * @param \stdClass $device The device record.
     * @return string One of: 'active', 'expired', 'suspended', 'pending'.
     */
    private static function resolve_device_status(\stdClass $device): string {
        if ($device->status == 1) {
            return ($device->expirydate > time()) ? 'active' : 'expired';
        }
        if ($device->status == 2) {
            return 'suspended';
        }
        return 'pending';
    }

    /**
     * Check for application updates from version.json.
     */
    private static function handle_check_update(): void {
        $versionfile = dirname(__DIR__) . '/version.json';
        if (file_exists($versionfile)) {
            $data = json_decode(file_get_contents($versionfile), true);
            echo json_encode([
                'status' => 'success',
                'version_code' => (int)($data['version_code'] ?? 1),
                'version_name' => $data['version_name'] ?? '1.0.0',
                'download_url' => $data['download_url'] ?? '',
                'apk_sha256' => $data['apk_sha256'] ?? '',
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'لم يتم العثور على معلومات التحديث على السيرفر']);
        }
        exit;
    }
}
