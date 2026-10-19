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
 * Import SOLA Soapbox assignments, topics, attempts and scores into PresenterAI activities.
 *
 * A dry run unless --apply is given. Run with --help for the options, and read docs/MIGRATION-RUNBOOK.md first.
 * The logic is \mod_presenterai\local\migration\command; this file only starts it and exits with its code
 * (0 done or a clean dry run, 1 an error or a refusal, 2 an unmet precondition).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use mod_presenterai\local\migration\command;
use mod_presenterai\local\migration\factory;

exit((new command(new factory()))->execute(array_slice($_SERVER['argv'], 1)));
