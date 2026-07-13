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
 * Kiosk layout helper for quizaccess_sanad_lockdown.
 *
 * When the body carries the .sanad-secure-kiosk class this module:
 *   1. Moves .block_quiz_navigation to document.body so that
 *      position:fixed is never clipped by a hidden ancestor drawer.
 *   2. Adds the kiosk-nav-panel class so CSS can style it independently.
 *
 * @module     quizaccess_sanad_lockdown/kiosk_layout
 * @copyright  2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * Move the quiz navigation block out of any hidden ancestor
     * so CSS position:fixed works unconditionally.
     */
    var moveNavBlock = function() {
        var body = document.body;

        // Only act inside the kiosk browser session.
        if (!body.classList.contains('sanad-secure-kiosk')) {
            return;
        }

        var block = document.querySelector('.block_quiz_navigation');
        if (!block) {
            return;
        }

        // Already at body level — nothing to do.
        if (block.parentNode === body) {
            return;
        }

        // Detach from current parent (drawer / side-post / blocks-column)
        // and re-attach directly to body so no ancestor can clip it.
        body.appendChild(block);
        block.classList.add('sanad-kiosk-nav-panel');
    };

    return {
        /**
         * Initialise the kiosk layout helper.
         * Called from rule.php :: setup_attempt_page().
         */
        init: function() {
            // Run immediately if DOM is already ready.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', moveNavBlock);
            } else {
                moveNavBlock();
            }

            // Also run after a short delay in case Moodle renders the block
            // asynchronously (e.g., via AMD lazy-loading or the drawer system).
            setTimeout(moveNavBlock, 600);
            setTimeout(moveNavBlock, 1500);
        }
    };
});
