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
 * Implementation of the quizaccess_ewa_lockdown plugin.
 *
 * This access rule enforces that students use the EWA Secure Browser Android
 * app to access a quiz. It:
 *   - Blocks any request that does not carry the EWA app HTTP headers.
 *   - Issues a signed HMAC session token and embeds it in a QR code.
 *   - Validates the token on every page load while the quiz is in progress.
 *   - Logs all violations to the database.
 *
 * @package   quizaccess_ewa_lockdown
 * @copyright 2026 Mahmoud Salem <m.salem@ewa.bh>
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

use quizaccess_ewa_lockdown\token_manager;
use quizaccess_ewa_lockdown\qr_generator;
use quizaccess_ewa_lockdown\violation_logger;

/**
 * Access rule: requires the EWA Secure Browser Android app.
 *
 * @copyright 2026 Mahmoud Salem <m.salem@ewa.bh>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizaccess_ewa_lockdown extends quiz_access_rule_base {
    /**
     * Return an instance of this rule if the quiz has it enabled.
     *
     * @param quiz $quizobj The quiz object.
     * @param int  $timenow Current timestamp.
     * @param bool $canignoretimelimits Whether the user can ignore time limits.
     * @return quiz_access_rule_base|null The rule instance or null.
     */
    public static function make(quiz $quizobj, $timenow, $canignoretimelimits) {
        if (empty($quizobj->get_quiz()->ewa_lockdown_enabled)) {
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
            'ewa_lockdown_enabled',
            get_string('requireewalockdown', 'quizaccess_ewa_lockdown')
        );
        $mform->addHelpButton('ewa_lockdown_enabled', 'requireewalockdown', 'quizaccess_ewa_lockdown');
        $mform->setDefault('ewa_lockdown_enabled', 0);

        // Token expiry.
        $mform->addElement(
            'text',
            'ewa_lockdown_tokenexpiry',
            get_string('tokenexpiry', 'quizaccess_ewa_lockdown'),
            ['size' => 10]
        );
        $mform->setType('ewa_lockdown_tokenexpiry', PARAM_INT);
        $mform->setDefault('ewa_lockdown_tokenexpiry', 1800);
        $mform->addHelpButton('ewa_lockdown_tokenexpiry', 'tokenexpiry', 'quizaccess_ewa_lockdown');
        $mform->addRule('ewa_lockdown_tokenexpiry', null, 'numeric', null, 'client');
        $mform->hideIf('ewa_lockdown_tokenexpiry', 'ewa_lockdown_enabled', 'eq', 0);

        // Emergency exit password.
        $mform->addElement(
            'passwordunmask',
            'ewa_lockdown_exitpassword',
            get_string('exitpassword', 'quizaccess_ewa_lockdown')
        );
        $mform->setType('ewa_lockdown_exitpassword', PARAM_RAW);
        $mform->addHelpButton('ewa_lockdown_exitpassword', 'exitpassword', 'quizaccess_ewa_lockdown');
        $mform->hideIf('ewa_lockdown_exitpassword', 'ewa_lockdown_enabled', 'eq', 0);

        // Allowed Whitelisted Domains / URLs.
        $mform->addElement(
            'textarea',
            'ewa_lockdown_alloweddomains',
            get_string('alloweddomains', 'quizaccess_ewa_lockdown'),
            ['rows' => 4, 'cols' => 60, 'placeholder' => "backup-lms.ewa.edu.sa\ncdn.ewa.edu.sa"]
        );
        $mform->setType('ewa_lockdown_alloweddomains', PARAM_RAW);
        $mform->addHelpButton('ewa_lockdown_alloweddomains', 'alloweddomains', 'quizaccess_ewa_lockdown');
        $mform->hideIf('ewa_lockdown_alloweddomains', 'ewa_lockdown_enabled', 'eq', 0);
    }

    /**
     * Save settings when the quiz settings form is submitted.
     *
     * @param object $quiz The submitted quiz data.
     */
    public static function save_settings($quiz) {
        global $DB;

        $enabled        = !empty($quiz->ewa_lockdown_enabled) ? 1 : 0;
        // FIX: Enforce minimum expiry of 300 seconds to prevent instantly-expiring tokens.
        $expiry         = max(300, isset($quiz->ewa_lockdown_tokenexpiry) ? (int)$quiz->ewa_lockdown_tokenexpiry : 1800);
        $exitpass       = isset($quiz->ewa_lockdown_exitpassword) ? trim($quiz->ewa_lockdown_exitpassword) : '';
        $alloweddomains = isset($quiz->ewa_lockdown_alloweddomains) ? trim($quiz->ewa_lockdown_alloweddomains) : '';

        if (!$enabled) {
            $DB->delete_records('quizaccess_ewa_lockdown', ['quizid' => $quiz->id]);
            return;
        }

        $record = $DB->get_record('quizaccess_ewa_lockdown', ['quizid' => $quiz->id]);
        if ($record) {
            $record->enabled        = $enabled;
            $record->tokenexpiry    = $expiry;
            $record->alloweddomains = $alloweddomains;
            $record->timemodified   = time();
            // FIX: Only update the exit password hash when the teacher actually enters a new one.
            // Previously, leaving the field blank would erase the existing password (set it to null).
            if ($exitpass !== '') {
                $record->exitpassword = password_hash($exitpass, PASSWORD_BCRYPT);
            }
            $DB->update_record('quizaccess_ewa_lockdown', $record);
        } else {
            $record = new stdClass();
            $record->quizid         = $quiz->id;
            $record->enabled        = $enabled;
            $record->tokenexpiry    = $expiry;
            $record->alloweddomains = $alloweddomains;
            $record->timecreated    = time();
            $record->timemodified   = time();
            // Only set password if one was provided during initial creation.
            $record->exitpassword   = $exitpass !== '' ? password_hash($exitpass, PASSWORD_BCRYPT) : null;
            $DB->insert_record('quizaccess_ewa_lockdown', $record);
        }
    }

    /**
     * Delete settings when the quiz is deleted.
     *
     * @param object $quiz The quiz record.
     */
    public static function delete_settings($quiz) {
        global $DB;
        $DB->delete_records('quizaccess_ewa_lockdown', ['quizid' => $quiz->id]);
        $DB->delete_records('quizaccess_ewa_sessions', ['quizid' => $quiz->id]);
        $DB->delete_records('quizaccess_ewa_violations', ['quizid' => $quiz->id]);
    }

    /**
     * Load settings from the database when the quiz is loaded.
     *
     * @param int $quizid The quiz ID.
     * @return array [fields SQL, join SQL, params].
     */
    public static function get_settings_sql($quizid) {
        return [
            'ewald.enabled AS ewa_lockdown_enabled,'
            . ' ewald.tokenexpiry AS ewa_lockdown_tokenexpiry,'
            . ' ewald.exitpassword AS ewa_lockdown_exitpassword,'
            . ' ewald.alloweddomains AS ewa_lockdown_alloweddomains',
            'LEFT JOIN {quizaccess_ewa_lockdown} ewald ON ewald.quizid = quiz.id',
            [],
        ];
    }

    /**
     * Prevent a new attempt if the request does not come from the EWA app
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

        if (!token_manager::is_ewa_browser_request()) {
            violation_logger::log(
                $this->quiz->id,
                $USER->id,
                violation_logger::TYPE_WRONG_BROWSER,
                '',
                ['page' => 'prevent_new_attempt']
            );
            return get_string('mustuseewaapp', 'quizaccess_ewa_lockdown');
        }

        if (!token_manager::validate_from_request($this->quiz->id, $USER->id)) {
            violation_logger::log(
                $this->quiz->id,
                $USER->id,
                violation_logger::TYPE_INVALID_TOKEN,
                $_SERVER[token_manager::HEADER_DEVICE] ?? ''
            );
            return get_string('tokenerror', 'quizaccess_ewa_lockdown');
        }

        return false;
    }

    /**
     * Whether the user needs to pass a preflight check before starting.
     *
     * If the request is NOT from the EWA app, we show the preflight page
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

        if (!token_manager::is_ewa_browser_request()) {
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
            // Students see a text instruction indicating they need the QR from the teacher
            $html  = '<div class="ewa-lockdown-preflight">';
            $html .= '<div class="alert alert-danger" role="alert">';
            $html .= '<strong>' . get_string('accessdenied', 'quizaccess_ewa_lockdown') . '</strong> ';
            $html .= get_string('mustuseewaapp', 'quizaccess_ewa_lockdown');
            $html .= '</div>';
            $html .= '<p class="font-weight-bold text-center text-primary" style="font-size:1.1em; margin: 15px 0;"><strong>' 
                . get_string('requestfromteacher', 'quizaccess_ewa_lockdown') . '</strong></p>';
            $html .= '</div>';

            $mform->addElement('html', $html);
            return;
        }

        $quizid  = $this->quizobj->get_quizid();
        $cmid    = $this->quizobj->get_cmid();
        $expiry  = (int)($this->quiz->ewa_lockdown_tokenexpiry ?? 1800);

        // Issue a token (this replaces any previous one for this user+quiz).
        $token   = token_manager::issue($quizid, $USER->id, '', $expiry);
        $url     = token_manager::build_launch_url($quizid, $cmid, $token);
        $qrimg   = qr_generator::get_img_tag(
            $url,
            300,
            get_string('qrcode_alttext', 'quizaccess_ewa_lockdown')
        );

        $shortcode = token_manager::get_short_code($token);

        // Build the HTML to show in the preflight form (visible for teachers).
        $html  = '<div class="ewa-lockdown-preflight">';
        $html .= '<div class="alert alert-warning" role="alert">';
        $html .= '<strong>' . get_string('accessdenied', 'quizaccess_ewa_lockdown') . '</strong> ';
        $html .= get_string('mustuseewaapp', 'quizaccess_ewa_lockdown');
        $html .= '</div>';
        $html .= '<p>' . get_string('scanqrtostart', 'quizaccess_ewa_lockdown') . '</p>';
        $html .= '<div class="ewa-qrcode-wrapper text-center">' . $qrimg . '</div>';
        if ($shortcode !== '') {
            $html .= '<p class="text-center font-weight-bold my-3" style="font-size:1.15em;">';
            $html .= get_string('shortcode', 'quizaccess_ewa_lockdown') . ': <span class="badge badge-secondary p-2">' . s($shortcode) . '</span>';
            $html .= '</p>';
        }
        $html .= '<p class="text-muted small">' . get_string('downloadapp', 'quizaccess_ewa_lockdown') . '</p>';
        $html .= '</div>';

        $mform->addElement('html', $html);
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
        $errors['ewa_lockdown_qr'] = get_string('mustuseewaapp', 'quizaccess_ewa_lockdown');
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

        // If accessed from within the EWA secure browser with a valid token, hide the QR.
        if (token_manager::is_ewa_browser_request() && token_manager::validate_from_request($this->quiz->id, $USER->id)) {
            return [];
        }

        $quizid  = $this->quizobj->get_quizid();
        $cmid    = $this->quizobj->get_cmid();
        $context = $this->quizobj->get_context();

        // Check if current user is a teacher/admin
        $isteacher = has_capability('mod/quiz:preview', $context) || has_capability('mod/quiz:viewreports', $context);

        if (!$isteacher) {
            // Students see a text instruction indicating they need the QR from the teacher
            $inner  = \html_writer::tag('p', \html_writer::tag('strong', get_string('accessdenied', 'quizaccess_ewa_lockdown')), ['class' => 'text-danger text-center']);
            $inner .= \html_writer::tag('p', get_string('mustuseewaapp', 'quizaccess_ewa_lockdown'), ['class' => 'text-center']);
            $inner .= \html_writer::tag('p', get_string('requestfromteacher', 'quizaccess_ewa_lockdown'), ['class' => 'font-weight-bold text-center text-primary', 'style' => 'font-size: 1.1em;']);
            
            return [\html_writer::div($inner, 'ewa-lockdown-description p-3 border rounded bg-light mb-3',
                ['style' => 'max-width:500px;margin:0 auto'])];
        }

        $expiry  = (int)($this->quiz->ewa_lockdown_tokenexpiry ?? 1800);

        // Issue a token (replaces any previous one for this user+quiz).
        $token   = token_manager::issue($quizid, $USER->id, '', $expiry);
        $url     = token_manager::build_launch_url($quizid, $cmid, $token);
        $qrimg   = qr_generator::get_img_tag(
            $url,
            250,
            get_string('qrcode_alttext', 'quizaccess_ewa_lockdown')
        );

        // Monitor dashboard button for teachers.
        $monitorurl = new \moodle_url('/mod/quiz/accessrule/ewa_lockdown/monitor.php', ['cmid' => $cmid]);
        $monitorbtn = \html_writer::link(
            $monitorurl,
            '<i class="fa fa-desktop mr-1"></i>' . get_string('monitor_link', 'quizaccess_ewa_lockdown'),
            [
                'class'  => 'btn btn-primary btn-sm mb-3',
                'target' => '_blank',
                'style'  => 'display:inline-flex;align-items:center;gap:6px;',
            ]
        );

        $shortcode = token_manager::get_short_code($token);

        // Use html_writer::div so the content is treated as raw HTML (not escaped).
        // The quiz renderer wraps each description() item in <p> tags with html_writer
        // which escapes strings — returning an html_writer output bypasses that.
        $inner  = \html_writer::tag('p', \html_writer::tag('strong', get_string('scanqrtostart', 'quizaccess_ewa_lockdown')));
        $inner .= \html_writer::div($qrimg, 'ewa-qrcode-wrapper text-center mb-2');
        if ($shortcode !== '') {
            $inner .= \html_writer::tag('p', get_string('shortcode', 'quizaccess_ewa_lockdown') . ': ' . \html_writer::span(s($shortcode), 'badge badge-secondary p-2'), ['class' => 'text-center font-weight-bold my-2', 'style' => 'font-size:1.15em;']);
        }
        $inner .= \html_writer::tag('p', get_string('downloadapp', 'quizaccess_ewa_lockdown'), ['class' => 'text-muted small text-center']);
        $inner .= \html_writer::div($monitorbtn, 'text-center mt-3');

        return [\html_writer::div($inner, 'ewa-lockdown-description text-center p-3 border rounded bg-light mb-3',
            ['style' => 'max-width:500px;margin:0 auto'])];
    }
}
