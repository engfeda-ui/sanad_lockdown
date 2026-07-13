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
 *
 * This file is a thin router only. All business logic lives in
 * quizaccess_sanad_lockdown\api_handler.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use quizaccess_sanad_lockdown\token_manager;
use quizaccess_sanad_lockdown\api_handler;

// Set API response headers.
header('Content-Type: application/json; charset=utf-8');

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Verify App Identification header.
$appid = $_SERVER[token_manager::HEADER_APP_ID] ?? '';
if ($appid !== token_manager::EXPECTED_APP_ID) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Invalid App Client']);
    exit;
}

// Decode JSON body.
$data = json_decode(file_get_contents('php://input'), true);
if ($data === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request: Invalid JSON']);
    exit;
}

// Require an action field.
$action = $data['action'] ?? '';
if (empty($action)) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request: Missing action parameter']);
    exit;
}

// Require a Device ID header.
$deviceid = $_SERVER[token_manager::HEADER_DEVICE] ?? '';
if (empty($deviceid)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Missing Device ID Header']);
    exit;
}

global $DB, $USER;

// Delegate to the handler.
api_handler::dispatch($action, $data, $deviceid);
