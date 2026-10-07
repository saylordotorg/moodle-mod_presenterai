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

namespace mod_presenterai\task;

use mod_presenterai\local\vision\visual_pipeline;

/**
 * Expire the AI's raw body language notes and the gate's rejection log on their own clocks.
 *
 * D5 gives the raw note its own clock because SOLA scrubbed it only when the
 * video went, so a site that never deletes video kept model prose about a
 * learner's body forever. Here it expires visualdatadays after it was
 * written, whatever happens to the media, and there is no forever: unset is
 * 30 days, and zero or less is 1 (design 7.4). The learner facing summary is
 * feedback and lives with the score; it is not touched.
 *
 * The gate log (design 5.3) is kept seven days, independently of everything.
 *
 * mtrace prints counts only, never text: task logs are read by more people
 * than the staff only log is.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class expire_visual_data extends \core\task\scheduled_task {
    /** @var int Days a gatelog row is kept. */
    public const GATELOG_DAYS = 7;

    /**
     * Name shown on the scheduled tasks page.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskexpirevisualdata', 'mod_presenterai');
    }

    /**
     * Run the task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $now = time();
        $cutoff = $now - visual_pipeline::visual_data_days() * DAYSECS;
        $select = 'visualevidenceat > 0 AND visualevidenceat <= :cutoff';
        $params = ['cutoff' => $cutoff];
        $notes = $DB->count_records_select('presenterai_recording', $select, $params);
        if ($notes > 0) {
            $DB->execute(
                "UPDATE {presenterai_recording}
                    SET visualevidence = NULL, visualevidenceat = 0
                  WHERE {$select}",
                $params
            );
        }
        mtrace('mod_presenterai: expired ' . $notes . ' raw body language note(s).');

        $logcutoff = $now - self::GATELOG_DAYS * DAYSECS;
        $logged = $DB->count_records_select('presenterai_gatelog', 'timecreated < :cutoff', ['cutoff' => $logcutoff]);
        if ($logged > 0) {
            $DB->delete_records_select('presenterai_gatelog', 'timecreated < :cutoff', ['cutoff' => $logcutoff]);
        }
        mtrace('mod_presenterai: deleted ' . $logged . ' visual feedback gate log row(s).');
    }
}
