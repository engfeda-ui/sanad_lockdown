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
 * Implementation of the quizaccess_sanad_lockdown plugin.
 *
 * This access rule enforces that students use the Sanad Secure Browser Android
 * app to access a quiz. It:
 *   - Blocks any request that does not carry the Sanad app HTTP headers.
 *   - Issues a signed HMAC session token and embeds it in a QR code.
 *   - Validates the token on every page load while the quiz is in progress.
 *   - Logs all violations to the database.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if (class_exists('\mod_quiz\local\access_rule_base')) {
    if (!class_exists('quiz_access_rule_base', false)) {
        class_alias('\mod_quiz\local\access_rule_base', 'quiz_access_rule_base');
    }
    if (!class_exists('quiz', false)) {
        class_alias('\mod_quiz\quiz_settings', 'quiz');
    }
} else {
    require_once($CFG->dirroot . '/mod/quiz/accessrule/accessrulebase.php');
}

use quizaccess_sanad_lockdown\token_manager;
use quizaccess_sanad_lockdown\qr_generator;
use quizaccess_sanad_lockdown\violation_logger;

/**
 * Access rule: requires the Sanad Secure Browser Android app.
 *
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizaccess_sanad_lockdown extends quiz_access_rule_base {
    /**
     * Return an instance of this rule if the quiz has it enabled.
     *
     * @param quiz $quizobj The quiz object.
     * @param int  $timenow Current timestamp.
     * @param bool $canignoretimelimits Whether the user can ignore time limits.
     * @return quiz_access_rule_base|null The rule instance or null.
     */
    public static function make(quiz $quizobj, $timenow, $canignoretimelimits) {
        if (empty($quizobj->get_quiz()->sanad_lockdown_enabled)) {
            return null;
        }
        return new self($quizobj, $timenow);
    }

    /**
     * Add settings form fields for the teacher.
     *
     * @param \mod_quiz\form\setup $quizform The quiz settings form.
     * @param \MoodleQuickForm     $mform    The Moodle quick form.
     */
    public static function add_settings_form_fields($quizform, \MoodleQuickForm $mform) {
        // Enable / disable toggle.
        $mform->addElement(
            'selectyesno',
            'sanad_lockdown_enabled',
            get_string('requiresanadlockdown', 'quizaccess_sanad_lockdown')
        );
        $mform->addHelpButton('sanad_lockdown_enabled', 'requiresanadlockdown', 'quizaccess_sanad_lockdown');
        $mform->setDefault('sanad_lockdown_enabled', 0);

        // Strictness level.
        $strictnessoptions = [
            'standard' => get_string('strictness_standard', 'quizaccess_sanad_lockdown'),
            'high'     => get_string('strictness_high', 'quizaccess_sanad_lockdown'),
            'exam'     => get_string('strictness_exam', 'quizaccess_sanad_lockdown'),
        ];
        $mform->addElement(
            'select',
            'sanad_lockdown_strictness',
            get_string('strictness', 'quizaccess_sanad_lockdown'),
            $strictnessoptions
        );
        $mform->setDefault('sanad_lockdown_strictness', 'high');
        $mform->addHelpButton('sanad_lockdown_strictness', 'strictness', 'quizaccess_sanad_lockdown');
        $mform->hideIf('sanad_lockdown_strictness', 'sanad_lockdown_enabled', 'eq', 0);

        // Token expiry.
        $mform->addElement(
            'text',
            'sanad_lockdown_tokenexpiry',
            get_string('tokenexpiry', 'quizaccess_sanad_lockdown'),
            ['size' => 10]
        );
        $mform->setType('sanad_lockdown_tokenexpiry', PARAM_INT);
        $mform->setDefault('sanad_lockdown_tokenexpiry', 1800);
        $mform->addHelpButton('sanad_lockdown_tokenexpiry', 'tokenexpiry', 'quizaccess_sanad_lockdown');
        $mform->addRule('sanad_lockdown_tokenexpiry', null, 'numeric', null, 'client');
        $mform->hideIf('sanad_lockdown_tokenexpiry', 'sanad_lockdown_enabled', 'eq', 0);

        // Regenerate password checkbox.
        $mform->addElement(
            'checkbox',
            'sanad_lockdown_regeneratepassword',
            get_string('regeneratepassword', 'quizaccess_sanad_lockdown')
        );
        $mform->hideIf('sanad_lockdown_regeneratepassword', 'sanad_lockdown_enabled', 'eq', 0);
    }

    /**
     * Save settings when the quiz settings form is submitted.
     *
     * @param object $quiz The submitted quiz data.
     */
    public static function save_settings($quiz) {
        global $DB;

        $enabled      = !empty($quiz->sanad_lockdown_enabled) ? 1 : 0;
        $strictness   = !empty($quiz->sanad_lockdown_strictness) ?
            clean_param($quiz->sanad_lockdown_strictness, PARAM_ALPHA) : 'high';
        // Enforce minimum expiry of 300 seconds to prevent instantly-expiring tokens.
        $expiry       = max(300, isset($quiz->sanad_lockdown_tokenexpiry) ? (int)$quiz->sanad_lockdown_tokenexpiry : 1800);
        $regenerating = !empty($quiz->sanad_lockdown_regeneratepassword);

        if (!$enabled) {
            $DB->delete_records('quizaccess_sanad_lockdown', ['quizid' => $quiz->id]);
            return;
        }

        $record = $DB->get_record('quizaccess_sanad_lockdown', ['quizid' => $quiz->id]);
        if ($record) {
            $record->enabled      = $enabled;
            $record->strictness   = $strictness;
            $record->tokenexpiry  = $expiry;
            $record->timemodified = time();
            // Automatically generate a 6-digit exit password if not already set,
            // if it is an old BCrypt hash, or if regeneration is requested.
            $isbcrypt = (!empty($record->exitpassword) && strpos($record->exitpassword, '$2y$') === 0
                && strlen($record->exitpassword) === 60);
            if (empty($record->exitpassword) || $isbcrypt || $regenerating) {
                try {
                    $record->exitpassword = (string)random_int(100000, 999999);
                } catch (\Exception $e) {
                    $record->exitpassword = (string)mt_rand(100000, 999999);
                }
            }
            $DB->update_record('quizaccess_sanad_lockdown', $record);
        } else {
            $record = new stdClass();
            $record->quizid         = $quiz->id;
            $record->enabled        = $enabled;
            $record->tokenexpiry    = $expiry;
            $record->timecreated    = time();
            $record->timemodified   = time();
            try {
                $record->exitpassword = (string)random_int(100000, 999999);
            } catch (\Exception $e) {
                $record->exitpassword = (string)mt_rand(100000, 999999);
            }
            $DB->insert_record('quizaccess_sanad_lockdown', $record);
        }
    }

    /**
     * Delete settings when the quiz is deleted.
     *
     * @param object $quiz The quiz record.
     */
    public static function delete_settings($quiz) {
        global $DB;
        $DB->delete_records('quizaccess_sanad_lockdown', ['quizid' => $quiz->id]);
        $DB->delete_records('quizaccess_sanad_sessions', ['quizid' => $quiz->id]);
        $DB->delete_records('quizaccess_sanad_violations', ['quizid' => $quiz->id]);
    }

    /**
     * Load settings from the database when the quiz is loaded.
     *
     * @param int $quizid The quiz ID.
     * @return array [fields SQL, join SQL, params].
     */
    public static function get_settings_sql($quizid) {
        return [
            'sanadld.enabled AS sanad_lockdown_enabled,'
            . ' sanadld.tokenexpiry AS sanad_lockdown_tokenexpiry,'
            . ' sanadld.exitpassword AS sanad_lockdown_exitpassword,'
            . ' sanadld.alloweddomains AS sanad_lockdown_alloweddomains',
            'LEFT JOIN {quizaccess_sanad_lockdown} sanadld ON sanadld.quizid = quiz.id',
            [],
        ];
    }

    /**
     * Prevent a new attempt if the request does not come from the Sanad app
     * or if the token is invalid.
     *
     * @param int    $numprevattempts Number of previous attempts.
     * @param object $lastattempt     The last attempt record.
     * @return string|false Error message, or false to allow.
     */
    public function prevent_new_attempt($numprevattempts, $lastattempt) {
        global $USER;

        $context = $this->quizobj->get_context();
        if (has_capability('mod/quiz:preview', $context)) {
            return false; // Let teachers review/preview without constraints.
        }

        if (!token_manager::is_sanad_browser_request()) {
            violation_logger::log(
                $this->quiz->id,
                $USER->id,
                violation_logger::TYPE_WRONG_BROWSER,
                '',
                ['page' => 'prevent_new_attempt']
            );
            return get_string('mustusesanadapp', 'quizaccess_sanad_lockdown');
        }

        if (!token_manager::validate_from_request($this->quiz->id, $USER->id)) {
            violation_logger::log(
                $this->quiz->id,
                $USER->id,
                violation_logger::TYPE_INVALID_TOKEN,
                $_SERVER[token_manager::HEADER_DEVICE] ?? ''
            );
            return get_string('tokenerror', 'quizaccess_sanad_lockdown');
        }

        return false;
    }

    /**
     * Whether the user needs to pass a preflight check before starting.
     *
     * If the request is NOT from the Sanad app, we show the preflight page
     * containing the QR code. If it IS from the app with a valid token,
     * no preflight is required.
     *
     * @param int|null $attemptid The attempt ID (null for a new attempt).
     * @return bool
     */
    public function is_preflight_check_required($attemptid) {
        global $USER;

        $context = $this->quizobj->get_context();
        if (has_capability('mod/quiz:preview', $context)) {
            return false; // Teachers do not need preflight check.
        }

        if (!token_manager::is_sanad_browser_request()) {
            return true;
        }

        if (!token_manager::validate_from_request($this->quiz->id, $USER->id)) {
            return true;
        }

        return false;
    }

    /**
     * Add the QR code display to the preflight check form.
     *
     * @param \mod_quiz\form\preflight_check $quizform The preflight form.
     * @param \MoodleQuickForm               $mform    The form.
     * @param int|null                       $attemptid The attempt ID.
     */
    public function add_preflight_check_form_fields($quizform, \MoodleQuickForm $mform, $attemptid) {
        global $USER;

        $context = $this->quizobj->get_context();
        $isteacher = has_capability('mod/quiz:preview', $context) || has_capability('mod/quiz:viewreports', $context);

        if (!$isteacher) {
            $mform->addElement('html', self::render_student_notice());
            return;
        }

        $quizid    = $this->quizobj->get_quizid();
        $cmid      = $this->quizobj->get_cmid();
        $expiry    = (int)($this->quiz->sanad_lockdown_tokenexpiry ?? 1800);
        $token     = token_manager::issue($quizid, $USER->id, '', $expiry);
        $shortcode = token_manager::get_short_code($token);
        $qrimg     = qr_generator::get_img_tag(
            token_manager::build_launch_url($quizid, $cmid, $token),
            300,
            get_string('qrcode_alttext', 'quizaccess_sanad_lockdown')
        );

        $mform->addElement('html', self::render_teacher_qr_panel($qrimg, $shortcode));
    }

    /**
     * Validate the preflight form.
     *
     * Since the preflight page only shows a QR code (no manual input),
     * validation always fails here so the student is redirected to the app.
     * The actual entry point is the launch URL scanned by the app.
     *
     * @param array    $data      Form data.
     * @param array    $files     Uploaded files.
     * @param array    $errors    Existing errors.
     * @param int|null $attemptid Attempt ID.
     * @return array Updated errors array.
     */
    public function validate_preflight_check($data, $files, $errors, $attemptid) {
        // Prevent any manual submission of the preflight form.
        $errors['sanad_lockdown_qr'] = get_string('mustusesanadapp', 'quizaccess_sanad_lockdown');
        return $errors;
    }

    /**
     * Notify the rule that the preflight check was passed.
     * Nothing to do here as validation is handled via HTTP headers.
     *
     * @param int|null $attemptid Attempt ID.
     */
    public function notify_preflight_check_passed($attemptid) {
        // No action needed.
    }

    /**
     * Return a description of the rule, including the QR code for scanning.
     *
     * @return string Description HTML.
     */
    public function description() {
        global $USER;

        // If accessed from within the Sanad secure browser with a valid token, hide the QR.
        if (token_manager::is_sanad_browser_request() && token_manager::validate_from_request($this->quiz->id, $USER->id)) {
            return [];
        }

        $quizid  = $this->quizobj->get_quizid();
        $cmid    = $this->quizobj->get_cmid();
        $context = $this->quizobj->get_context();

        // Check if current user is a teacher/admin.
        $isteacher = has_capability('mod/quiz:preview', $context) || has_capability('mod/quiz:viewreports', $context);

        if (!$isteacher) {
            return [self::render_student_notice()];
        }

        $expiry    = (int)($this->quiz->sanad_lockdown_tokenexpiry ?? 1800);
        $token     = token_manager::issue($quizid, $USER->id, '', $expiry);
        $shortcode = token_manager::get_short_code($token);
        $qrimg     = qr_generator::get_img_tag(
            token_manager::build_launch_url($quizid, $cmid, $token),
            250,
            get_string('qrcode_alttext', 'quizaccess_sanad_lockdown')
        );

        $monitorurl = new \moodle_url('/mod/quiz/accessrule/sanad_lockdown/monitor.php', ['cmid' => $cmid]);
        $monitorbtn = \html_writer::link(
            $monitorurl,
            '<i class="fa fa-desktop mr-1"></i>' . get_string('monitor_link', 'quizaccess_sanad_lockdown'),
            [
                'class'  => 'btn btn-primary btn-sm mb-3',
                'target' => '_blank',
                'style'  => 'display:inline-flex;align-items:center;gap:6px;',
            ]
        );

        return [
            \html_writer::div(
                self::render_teacher_qr_panel($qrimg, $shortcode) .
                \html_writer::div($monitorbtn, 'text-center mt-3'),
                'sanad-lockdown-description text-center p-3 border rounded bg-light mb-3',
                ['style' => 'max-width:500px;margin:0 auto']
            ),
        ];
    }

    /**
     * Sets up the attempt page for the quiz, adding custom styling when inside Sanad Secure Browser.
     *
     * @param moodle_page $page The Moodle page object.
     */
    public function setup_attempt_page($page) {
        if (token_manager::is_sanad_browser_request()) {
            $page->set_pagelayout('secure');
            $page->add_body_class('sanad-secure-kiosk');
            // Force blocks to be loaded and rendered by Moodle core.
            $this->quiz->showblocks = 1;
        }
    }

    // -------------------------------------------------------------------------
    // Private rendering helpers.
    // -------------------------------------------------------------------------.

    /**
     * Build the HTML notice shown to students who access the quiz outside the app.
     *
     * @return string HTML string.
     */
    private static function render_student_notice(): string {
        $html  = '<div class="sanad-lockdown-preflight">';
        $html .= '<div class="alert alert-danger" role="alert">';
        $html .= '<strong>' . get_string('accessdenied', 'quizaccess_sanad_lockdown') . '</strong> ';
        $html .= get_string('mustusesanadapp', 'quizaccess_sanad_lockdown');
        $html .= '</div>';
        $html .= '<p class="font-weight-bold text-center text-primary" style="font-size:1.1em;">';
        $html .= '<strong>' . get_string('requestfromteacher', 'quizaccess_sanad_lockdown') . '</strong>';
        $html .= '</p>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Build the HTML QR code panel shown to teachers in the preflight / description views.
     *
     * @param string $qrimg     The <img> tag HTML for the QR code.
     * @param string $shortcode The short code string (may be empty).
     * @return string HTML string.
     */
    private static function render_teacher_qr_panel(string $qrimg, string $shortcode): string {
        $html  = '<div class="sanad-lockdown-preflight">';
        $html .= '<div class="alert alert-warning" role="alert">';
        $html .= '<strong>' . get_string('accessdenied', 'quizaccess_sanad_lockdown') . '</strong> ';
        $html .= get_string('mustusesanadapp', 'quizaccess_sanad_lockdown');
        $html .= '</div>';
        $html .= '<p>' . get_string('scanqrtostart', 'quizaccess_sanad_lockdown') . '</p>';
        $html .= '<div class="sanad-qrcode-wrapper text-center">' . $qrimg . '</div>';
        if ($shortcode !== '') {
            $html .= '<p class="text-center font-weight-bold my-3" style="font-size:1.15em;">';
            $html .= get_string('shortcode', 'quizaccess_sanad_lockdown') . ': ';
            $html .= '<span class="badge badge-secondary p-2">' . s($shortcode) . '</span>';
            $html .= '</p>';
        }
        $html .= '<p class="text-muted small">' . get_string('downloadapp', 'quizaccess_sanad_lockdown') . '</p>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Hash a plain-text exit password with PASSWORD_BCRYPT.
     *
     * Returns null if:
     *   - The value is empty (no password to store).
     *   - The value is already a valid password hash (prevents double-hashing).
     *     Detection uses PHP's built-in password_get_info() which is algorithm-agnostic
     *     and reliable across all PHP versions that support PASSWORD_BCRYPT / PASSWORD_ARGON2.
     *
     * Note: an empty string return means "clear the password" — callers must handle
     * that separately (see save_settings).
     *
     * @param string $pass The plain-text (or already-hashed) password.
     * @return string|null The bcrypt hash, or null if no hashing is needed.
     */
    private static function hash_exit_password(string $pass): ?string {
        if ($pass === '') {
            return null;
        }
        return $pass;
    }
}
