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
 * Read-only questions the runner asks of this site: the map table, the activity an earlier run made, the course.
 *
 * Split out of runner so the runner keeps the order of operations. Nothing here writes.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class destination {
    /** @var string The map table. */
    public const MAP = 'presenterai_migration_map';

    /**
     * The map row of one source row on this site.
     *
     * @param string $table An ids::SOURCE_* value.
     * @param int $sourceid The source id.
     * @return \stdClass|null
     */
    public static function map_row(string $table, int $sourceid): ?\stdClass {
        global $DB;

        $row = $DB->get_record(self::MAP, ['sourcetable' => $table, 'sourceid' => $sourceid, 'sourcesiteid' => ids::site_id()]);

        return $row ?: null;
    }

    /**
     * The source ids of one kind of map row that belong to one migrated activity (every row carries its cmid).
     *
     * @param string $table An ids::SOURCE_* value.
     * @param int $cmid The activity.
     * @return int[]
     */
    public static function mapped_ids(string $table, int $cmid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select(self::MAP, 'sourceid',
            'sourcetable = :t AND sourcesiteid = :s AND targetcmid = :c', ['t' => $table, 's' => ids::site_id(), 'c' => $cmid]));
    }

    /**
     * Whether the source changed after the assignment was first imported: the assignment itself, any of its topics,
     * or a topic that was attached and is gone from the source.
     *
     * @param \stdClass $map The assignment's map row; its timecreated is when it was migrated.
     * @param array $assign The source assignment.
     * @param array[] $topics The source topics.
     * @return bool
     */
    public static function source_changed(\stdClass $map, array $assign, array $topics): bool {
        $since = (int) $map->timecreated;
        if ((int) $assign['timemodified'] > $since) {
            return true;
        }
        $ids = [];
        foreach ($topics as $topic) {
            if ((int) $topic['timemodified'] > $since) {
                return true;
            }
            $ids[] = (int) $topic['id'];
        }

        return array_diff(self::mapped_ids(ids::SOURCE_TOPIC, (int) $map->targetcmid), $ids) !== [];
    }

    /**
     * Whether an earlier run's activity still exists and is still the one made from this assignment.
     *
     * @param \stdClass $map The assignment's map row.
     * @param int $sourceid The source assignment.
     * @return bool
     */
    public static function target_resolves(\stdClass $map, int $sourceid): bool {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $map->targetcmid]);
        $module = $DB->get_field('modules', 'id', ['name' => 'presenterai']);
        if (!$cm || (int) $cm->module !== (int) $module || (int) $cm->instance !== (int) $map->targetid
                || !empty($cm->deletioninprogress)) {
            return false;
        }

        return (int) $DB->get_field('presenterai', 'legacyassignid', ['id' => $map->targetid]) === $sourceid;
    }

    /**
     * Whether an earlier run's activity has been touched since: an attempt the import didn't make, grading on, or
     * shown. The import creates it hidden and only finish() shows it, so a visible unfinished activity means a
     * person unhid it, and finishing would silently hide it again or override their choice.
     *
     * @param \stdClass $map The assignment's map row.
     * @return bool
     */
    public static function target_used(\stdClass $map): bool {
        global $DB;

        return $DB->record_exists('presenterai_recording', ['presenteraiid' => $map->targetid, 'legacyrecid' => 0])
            || (int) $DB->get_field('presenterai', 'grade', ['id' => $map->targetid]) !== 0
            || (int) $DB->get_field('course_modules', 'visible', ['id' => $map->targetcmid]) !== 0;
    }

    /**
     * What SOLA gained since a verified assignment was imported: recordings with no map row, and imported
     * recordings that have a score in SOLA but none here (only while the destination row still exists).
     *
     * @param \stdClass $map The assignment's map row.
     * @param array[] $recs The source recordings now.
     * @return array [int new source rows, int newly scored rows].
     */
    public static function drift(\stdClass $map, array $recs): array {
        global $DB;

        $mapped = array_flip(self::mapped_ids(ids::SOURCE_REC, (int) $map->targetcmid));
        $scored = $DB->get_records_select_menu('presenterai_recording', 'presenteraiid = :p AND legacyrecid > 0',
            ['p' => $map->targetid], '', 'legacyrecid, legacyscoreid');
        $new = 0;
        $newlyscored = 0;
        foreach ($recs as $rec) {
            $id = (int) $rec['id'];
            if (!isset($mapped[$id])) {
                $new++;
            } else if (!empty($rec['scoreid']) && array_key_exists($id, $scored) && (int) $scored[$id] === 0) {
                $newlyscored++;
            }
        }

        return [$new, $newlyscored];
    }

    /**
     * Whether a stand-in can be hidden from the course page and stay reachable (D15).
     *
     * @param \stdClass $course The course.
     * @param \cm_info $cm The stand-in.
     * @return bool
     */
    public static function stealth_allowed(\stdClass $course, \cm_info $cm): bool {
        global $CFG;

        if (empty($CFG->allowstealth)) {
            return false;
        }

        return (bool) course_get_format($course)->allow_stealth_module_visibility($cm, $cm->get_section_info());
    }

    /**
     * The course's last existing section that isn't a subsection's. A section is never created.
     *
     * @param \stdClass $course The course.
     * @return int
     */
    public static function last_section(\stdClass $course): int {
        $last = 0;
        foreach (get_fast_modinfo($course)->get_section_info_all() as $section) {
            if (!$section->is_delegated()) {
                $last = max($last, (int) $section->section);
            }
        }

        return $last;
    }
}
