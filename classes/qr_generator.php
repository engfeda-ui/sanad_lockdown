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
 * QR Code generator for quizaccess_sanad_lockdown.
 *
 * Generates a QR code as an inline base64-encoded PNG. Uses a pure-PHP
 * QR generator that ships with Moodle core (or falls back to Google Charts
 * API as a secondary option when unit-testing outside a full Moodle install).
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <m.salem@sanad.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_sanad_lockdown;

/**
 * Builds QR codes for the Sanad Secure Browser launch URL.
 */
class qr_generator {
    /**
     * Return an HTML <img> tag containing the QR code for the given URL.
     *
     * The QR code is encoded as an inline data-URI so no external HTTP request
     * is made during the exam session.
     *
     * @param string $url   The URL to encode in the QR.
     * @param int    $size  Pixel size of the QR image (width = height). Default 300.
     * @param string $alt   Alt text for the image element.
     * @return string HTML <img> element.
     */
    public static function get_img_tag(string $url, int $size = 300, string $alt = ''): string {
        $datauri = self::get_data_uri($url, $size);
        $altesc  = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
        return '<img src="' . $datauri . '" width="' . $size . '" height="' . $size
            . '" alt="' . $altesc . '" class="sanad-qrcode" />';
    }

    /**
     * Return a data-URI (data:image/png;base64,...) for the QR code PNG.
     *
     * @param string $url  The URL to encode.
     * @param int    $size Pixel dimensions of the output image.
     * @return string Data URI string.
     */
    public static function get_data_uri(string $url, int $size = 300): string {
        $png = self::generate_png($url, $size);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * Generate a QR PNG using Moodle's bundled QR library.
     *
     * Moodle 4.x ships with a third-party QR library under
     * lib/phpqrcode/. If that is not available (e.g. unit-test
     * environment) we fall back to generating a minimal placeholder.
     *
     * @param string $url  Data to encode.
     * @param int    $size Pixel dimensions of the returned PNG.
     * @return string Raw PNG bytes.
     */
    private static function generate_png(string $url, int $size): string {
        global $CFG;

        $qrlib = $CFG->dirroot . '/lib/phpqrcode/qrlib.php';

        if (file_exists($qrlib)) {
            require_once($qrlib);

            // QRcode::png() writes directly to output; capture it.
            ob_start();
            // QR_ECLEVEL_M = medium error correction, $size / 10 as module size.
            \QRcode::png($url, false, 'M', max(4, (int)($size / 30)), 2);
            return ob_get_clean();
        }

        // Try Moodle's built-in core_qrcode if available (Moodle 4.x wrapper around TCPDF).
        $coreqrfile = $CFG->dirroot . '/lib/classes/qrcode.php';
        if (file_exists($coreqrfile)) {
            require_once($coreqrfile);
        }
        if (class_exists('core_qrcode')) {
            $qrcode = new \core_qrcode($url);
            // Calculate scale based on requested size. Default size is 300, scale 3 works well.
            $scale = max(2, (int)($size / 75));
            return $qrcode->getBarcodePngData($scale, $scale);
        }

        // Fallback: return a tiny 1×1 transparent PNG so the img tag stays valid.
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/w8AAwAB/epTWHoAAAAASUVORK5CYII='
        );
    }
}
