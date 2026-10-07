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

namespace mod_presenterai\local\vision;

/**
 * The judge could not give a verdict: an outage, not a rejection.
 *
 * Design 4.7 keeps the two apart. A rejection is final and is never retried.
 * An outage throws this, so the scoring task can requeue under its transient
 * rule rather than permanently degrading every attempt scored while a provider
 * was having a bad forty minutes. Only when the task has run out of retries
 * does visual_pipeline::finalise() fail closed instead.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class judge_unavailable_exception extends \moodle_exception {
    /** @var string Why the judge was unavailable: not_configured, the ai_exception reason, or bad_response. */
    public readonly string $reason;

    /**
     * Build the exception.
     *
     * @param string $reason A short machine reason, never model output or a key.
     * @param string $debuginfo Developer detail, for the task log only.
     */
    public function __construct(string $reason, string $debuginfo = '') {
        $this->reason = $reason;
        parent::__construct('error:judgeunavailable', 'mod_presenterai', '', null, $debuginfo);
    }
}
