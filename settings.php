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
 * Settings configuration for the quizaccess_sanad_lockdown plugin.
 * Adds a secure external link to the admin monitor panel.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Add external link to the admin dashboard.
    $url = new moodle_url('/mod/quiz/accessrule/sanad_lockdown/manage_devices.php');

    $desc = get_string('settings_desc', 'quizaccess_sanad_lockdown');
    $linktext = get_string('settings_link', 'quizaccess_sanad_lockdown');

    $html = html_writer::tag('p', $desc, ['style' => 'font-size:14px; margin-bottom: 12px;']);
    $html .= html_writer::link($url, $linktext, [
        'class' => 'btn btn-primary',
        'target' => '_blank',
        'style' => 'font-weight:bold;',
    ]);

    $settings->add(new admin_setting_heading(
        'quizaccess_sanad_lockdown/devices_link',
        '',
        html_writer::tag('div', $html)
    ));

    // Optional HMAC shared secret for kiosk-app API authentication.
    // Empty (default) = gate disabled. Once the kiosk app ships
    // X-SANAD-TIMESTAMP / X-SANAD-SIGNATURE support, configure a strong
    // random value here to make header spoofing impossible.
    $settings->add(new admin_setting_configtext(
        'quizaccess_sanad_lockdown/appsharedsecret',
        get_string('appsharedsecret', 'quizaccess_sanad_lockdown'),
        get_string('appsharedsecret_desc', 'quizaccess_sanad_lockdown'),
        '',
        PARAM_RAW_TRIMMED
    ));
}
