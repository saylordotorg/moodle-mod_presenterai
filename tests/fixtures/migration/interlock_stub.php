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

namespace mod_presenterai\tests\fixtures;

/**
 * A stand-in for SOLA's presenterai_interlock helper, whose site_id() a test can set.
 *
 * The reader compares SOLA's site id with its own, so a test needs a helper that answers what it is told, or
 * throws, without touching the real one. Its state is static because the reader names the class by string, and a
 * test sets it before every use.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class interlock_stub {
    /** @var string What site_id() returns. */
    public static $siteid = '';

    /** @var bool Whether site_id() throws instead. */
    public static $throws = false;

    /**
     * Set what the stub answers, and whether it throws.
     *
     * @param string $siteid What site_id() returns.
     * @param bool $throws Whether it throws instead.
     */
    public static function answer(string $siteid, bool $throws = false): void {
        self::$siteid = $siteid;
        self::$throws = $throws;
    }

    /**
     * The site id this stand-in reports.
     *
     * @return string
     * @throws \RuntimeException When the test asked for a failure.
     */
    public static function site_id(): string {
        if (self::$throws) {
            throw new \RuntimeException('The stub was told to fail.');
        }

        return self::$siteid;
    }
}
