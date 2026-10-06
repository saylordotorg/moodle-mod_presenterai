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
 * Change the deletion date of recordings that already exist.
 *
 * The retentiondays setting only ever affects recordings made after it
 * changes. This is the deliberate, explicit way to change the ones that exist,
 * specified in docs/DESIGN-visual-feedback-and-retention.md section 8.3. The
 * logic is \mod_presenterai\local\retention_tool; this file parses options and
 * prints. --notify (send the deletion notice as part of the run) arrives with
 * the message provider in phase 2.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use mod_presenterai\local\retention_tool;

[$options, $unrecognised] = cli_get_params(
    [
        'from' => null,
        'execute' => false,
        'course' => 0,
        'instance' => 0,
        'olderthan' => 0,
        'no-grace' => false,
        'force' => false,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    cli_writeln(get_string('cli_help', 'mod_presenterai'));
    exit(0);
}

if (!in_array((string) $options['from'], retention_tool::FROMS, true)) {
    cli_error(get_string('cli_badfrom', 'mod_presenterai'));
}
if (!empty($options['no-grace']) && empty($options['force'])) {
    cli_error(get_string('cli_nogracewithoutforce', 'mod_presenterai'));
}

$plan = retention_tool::plan([
    'from' => (string) $options['from'],
    'course' => (int) $options['course'],
    'instance' => (int) $options['instance'],
    'olderthan' => (int) $options['olderthan'],
    'grace' => empty($options['no-grace']),
    'force' => !empty($options['force']),
]);

cli_writeln(get_string('cli_summary', 'mod_presenterai', (object) [
    'from' => $plan['from'],
    'inscope' => $plan['inscope'],
    'changes' => count($plan['changes']),
    'eligiblesoon' => $plan['eligiblesoon'],
    'currentlyzero' => $plan['currentlyzero'],
    'skippednever' => $plan['skippednever'],
]));
if ($plan['gracedays'] > 0) {
    cli_writeln(get_string('cli_grace', 'mod_presenterai', $plan['gracedays']));
}
cli_writeln(get_string('cli_whatitmeans', 'mod_presenterai'));

if (empty($options['execute'])) {
    cli_writeln(get_string('cli_dryrun', 'mod_presenterai'));
    exit(0);
}
if ($plan['refused']) {
    cli_error(get_string('cli_toomanyeligible', 'mod_presenterai', retention_tool::FORCE_THRESHOLD));
}

$changed = retention_tool::apply($plan);
cli_writeln(get_string('cli_applied', 'mod_presenterai', $changed));
exit(0);
