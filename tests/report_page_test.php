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

namespace mod_presenterai;

use mod_presenterai\output\report_page;

/**
 * The submissions report: who is listed, and what each column reads.
 *
 * Score and spend rows are inserted directly, because the report only reads
 * them and the classes that write them are tested on their own.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\output\report_page
 */
final class report_page_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The presenterai row. */
    private \stdClass $instance;

    /** @var \cm_info The course module. */
    private \cm_info $cm;

    /** @var \context_module The activity context. */
    private \context_module $context;

    /** @var \stdClass Learner with attempts, sorted second. */
    private \stdClass $ada;

    /** @var \stdClass Learner with no attempts, sorted first. */
    private \stdClass $alan;

    /** @var \stdClass A non-editing teacher. */
    private \stdClass $teacher;

    /**
     * One activity, two learners and a teacher.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->instance = $generator->create_module('presenterai', ['course' => $this->course->id, 'grade' => 100]);
        [, $this->cm] = get_course_and_cm_from_instance($this->instance->id, 'presenterai');
        $this->context = \context_module::instance($this->cm->id);
        $this->ada = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $this->alan = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Alan', 'lastname' => 'Babbage']);
        $this->teacher = $generator->create_and_enrol($this->course, 'teacher');
    }

    /**
     * Export the report for a viewer and a group.
     *
     * @param int $viewerid The viewer.
     * @param int $groupid The active group.
     * @return array
     */
    private function export(int $viewerid, int $groupid = 0): array {
        global $PAGE;

        $page = new report_page($this->instance, $this->course, $this->cm, $this->context, $viewerid, $groupid, '');
        return $page->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Create a recording.
     *
     * @param \stdClass $user The learner.
     * @param array $fields Overrides.
     * @return \stdClass
     */
    private function recording(\stdClass $user, array $fields = []): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording($fields + [
            'presenteraiid' => $this->instance->id,
            'userid' => $user->id,
            'storagekey' => 'k' . random_string(6) . '.webm',
        ]);
    }

    /**
     * Insert a score row directly.
     *
     * @param \stdClass $rec The recording.
     * @param string $origin 'ai' or 'teacher'.
     * @param int $rawsum Points awarded.
     * @param int $rawmax Points available.
     * @param int $timecreated When.
     * @return int The row id.
     */
    private function score(\stdClass $rec, string $origin, int $rawsum, int $rawmax, int $timecreated): int {
        global $DB;

        return (int) $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $rec->id,
            'userid' => $rec->userid,
            'rubricid' => 0,
            'origin' => $origin,
            'scores' => '[]',
            'rawsum' => $rawsum,
            'rawmax' => $rawmax,
            'overallpct' => $rawmax > 0 ? round(100 * $rawsum / $rawmax, 2) : null,
            'graderid' => 0,
            'timecreated' => $timecreated,
        ]);
    }

    /**
     * Rows by user id.
     *
     * @param array $data An export.
     * @return array userid => row
     */
    private function by_user(array $data): array {
        $out = [];
        foreach ($data['rows'] as $row) {
            $out[$row['userid']] = $row;
        }
        return $out;
    }

    /**
     * Every enrolled learner gets a row, sorted by last name, including one with no attempt.
     *
     * @return void
     */
    public function test_rows_for_enrolled_learners(): void {
        $this->recording($this->ada, ['attemptnumber' => 1, 'durationseconds' => 125]);

        $data = $this->export((int) $this->teacher->id);
        $this->assertSame((int) $this->cm->id, $data['cmid']);
        $this->assertTrue($data['graded']);
        $this->assertTrue($data['hasrows']);
        $this->assertSame(
            [(int) $this->alan->id, (int) $this->ada->id],
            array_column($data['rows'], 'userid'),
            'Rows are sorted by last name, and staff are not listed.'
        );

        $rows = $this->by_user($data);
        $alan = $rows[(int) $this->alan->id];
        $this->assertFalse($alan['hasattempt']);
        $this->assertSame(get_string('report_noattempt', 'mod_presenterai'), $alan['status']);
        $this->assertSame('0', $alan['attempts']);
        $this->assertSame('-', $alan['latestattempt']);
        $this->assertSame('-', $alan['length']);
        $this->assertSame('', $alan['gradeurl']);
        $this->assertSame([], $alan['attemptlinks']);

        $ada = $rows[(int) $this->ada->id];
        $this->assertSame('Ada Lovelace', $ada['fullname']);
        $this->assertTrue($ada['hasattempt']);
        $this->assertSame('1', $ada['attempts']);
        $this->assertSame('1', $ada['latestattempt']);
        $this->assertSame('2:05', $ada['length']);
        $this->assertSame('', $ada['aipct'], 'There is no AI row in phase 2, so the cell is empty.');
        $this->assertSame('$0.00', $ada['spend']);
    }

    /**
     * AI % and teacher % come from the latest attempt; overall % from the aggregate.
     *
     * @return void
     */
    public function test_percentages(): void {
        $now = time();
        $first = $this->recording($this->ada, ['attemptnumber' => 1, 'timecreated' => $now - 1000]);
        $latest = $this->recording($this->ada, ['attemptnumber' => 2, 'timecreated' => $now - 500, 'status' => 'scored']);
        // Unfinished rows don't count as attempts and don't become the latest.
        $this->recording($this->ada, ['attemptnumber' => 1, 'timecreated' => $now - 100, 'status' => 'abandoned']);

        $this->score($first, 'teacher', 5, 5, $now - 900);
        $this->score($latest, 'ai', 3, 4, $now - 400);
        $this->score($latest, 'teacher', 2, 3, $now - 300);

        $row = $this->by_user($this->export((int) $this->teacher->id))[(int) $this->ada->id];
        $this->assertSame('2', $row['attempts']);
        $this->assertSame('2', $row['latestattempt']);
        $this->assertSame(get_string('status_scored', 'mod_presenterai'), $row['status']);
        $this->assertSame(format_float(75, 2) . '%', $row['aipct']);
        $this->assertSame(format_float(66.67, 2) . '%', $row['teacherpct']);
        $aggregate = \mod_presenterai\local\grader::aggregate_for_users($this->instance, [(int) $this->ada->id]);
        $this->assertSame(format_float($aggregate[(int) $this->ada->id]['pct'], 2) . '%', $row['finalpct']);
        $this->assertNotSame('', $row['finalpct']);
    }

    /**
     * Spend sums the learner's aiusage rows in this activity only.
     *
     * @return void
     */
    public function test_spend(): void {
        global $DB;

        $other = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        foreach ([[$this->instance->id, 200000000], [$this->instance->id, 50000000], [$other->id, 900000000]] as [$pid, $cost]) {
            $DB->insert_record('presenterai_aiusage', (object) [
                'presenteraiid' => $pid,
                'recordingid' => 0,
                'userid' => $this->ada->id,
                'action' => 'score',
                'estmicrocents' => $cost,
                'timecreated' => time(),
            ]);
        }

        $rows = $this->by_user($this->export((int) $this->teacher->id));
        $this->assertSame('$' . format_float(2.5, 2), $rows[(int) $this->ada->id]['spend']);
        $this->assertSame('$' . format_float(0, 2), $rows[(int) $this->alan->id]['spend']);
    }

    /**
     * Graders get a link per finished attempt; viewers without grade get none.
     *
     * @return void
     */
    public function test_attempt_links_need_grade(): void {
        global $DB;

        $first = $this->recording($this->ada, ['attemptnumber' => 1]);
        $second = $this->recording($this->ada, ['attemptnumber' => 2]);
        $this->recording($this->ada, ['status' => 'uploading']);

        $row = $this->by_user($this->export((int) $this->teacher->id))[(int) $this->ada->id];
        $this->assertCount(2, $row['attemptlinks']);
        $this->assertSame(get_string('report_attemptn', 'mod_presenterai', 1), $row['attemptlinks'][0]['label']);
        $this->assertStringContainsString('recordingid=' . $first->id, $row['attemptlinks'][0]['url']);
        $this->assertStringContainsString('Ada Lovelace', $row['attemptlinks'][1]['aria']);
        $this->assertStringContainsString('recordingid=' . $second->id, $row['gradeurl']);

        $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('mod/presenterai:grade', CAP_PROHIBIT, $teacherrole, $this->context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $row = $this->by_user($this->export((int) $this->teacher->id))[(int) $this->ada->id];
        $this->assertSame([], $row['attemptlinks'], 'A viewer without grade was offered grading links.');
        $this->assertSame('', $row['gradeurl']);
        $this->assertSame('2', $row['attempts'], 'The report itself is still readable.');
    }

    /**
     * A chosen group narrows the rows; separate groups with no group shows nobody.
     *
     * @return void
     */
    public function test_group_filtering(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $this->ada->id]);

        $data = $this->export((int) $this->teacher->id, (int) $group->id);
        $this->assertSame([(int) $this->ada->id], array_column($data['rows'], 'userid'));

        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->cm->id]);
        rebuild_course_cache((int) $this->course->id, true);
        [, $this->cm] = get_course_and_cm_from_instance($this->instance->id, 'presenterai');

        // A non-editing teacher in no group, in separate groups, sees nobody.
        $this->setUser($this->teacher);
        $data = $this->export((int) $this->teacher->id, 0);
        $this->assertFalse($data['hasrows']);
        $this->assertSame([], $data['rows']);

        global $PAGE;
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/report', $data);
        $this->assertStringContainsString(get_string('report_nolearners', 'mod_presenterai'), $html);
    }
}
