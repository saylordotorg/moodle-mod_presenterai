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

namespace mod_presenterai\local\migration;

/**
 * Whether a stored object is where a migrated row says it is: the verifier's tier 3 (IMPLEMENTATION-PLAN 7.6).
 *
 * Behind an interface so the verifier's tests use a double and CI never needs a bucket. The real one is
 * s3_object_checker. Reads only: nothing here may write, copy or delete an object.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface object_checker {
    /**
     * Whether the object exists and, when a size is expected and the store reports one, has that size.
     *
     * @param string $key The key read from the destination row.
     * @param int $bytes The size expected; 0 or less means unknown, so existence alone is checked.
     * @return bool True when it's there (at that size), false when it's missing or its size differs.
     * @throws \RuntimeException When the store couldn't say: the code is the HTTP status (0 for no answer), and the
     *                           message names neither the key nor any secret.
     */
    public function exists_with_size(string $key, int $bytes): bool;
}
