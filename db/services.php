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
 * Web service function definitions for quizaccess_sanad_lockdown.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'quizaccess_sanad_lockdown_get_launch_token' => [
        'classname'   => 'quizaccess_sanad_lockdown\external\get_launch_token',
        'methodname'  => 'execute',
        'description' => 'Generates and returns a secure launch token and deep link for SANAD Kiosk',
        'type'        => 'read',
        'capabilities' => 'mod/quiz:attempt',
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
