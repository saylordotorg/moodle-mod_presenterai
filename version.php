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
 * Plugin version and other meta-data.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_presenterai';
$plugin->version   = 2026092000;
$plugin->release   = '0.1.0-dev';
$plugin->maturity  = MATURITY_ALPHA;

// Moodle 4.5. Both Saylor production sites run 4.5.13+, verified 2026-09-20, so
// a higher floor would put the plugin out of reach of the only sites that run
// it. The core AI subsystem exists on 4.5 with a static API and changes to an
// instance API on 5.x; the adapter carries that branch rather than the floor
// being raised to avoid it. See docs/IMPLEMENTATION-PLAN.md section 5.
$plugin->requires  = 2024100700;
$plugin->supported = [405, 502];
