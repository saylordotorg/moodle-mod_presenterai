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

use mod_presenterai\local\score_manager;
use mod_presenterai\local\scorer;
use mod_presenterai\output\view_page;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/presenterai/lib.php');

/**
 * Progress across attempts (0.8.0): the summary on the learner page, and the
 * previous attempt that a later attempt's scoring prompt is given (D33).
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\score_manager::previous_released
 * @covers     \mod_presenterai\local\scorer::previous
 * @covers     \mod_presenterai\output\view_page::progress
 */
final class progress_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity row, carrying cmid. */
    private \stdClass $instance;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /** @var \mod_presenterai_generator The plugin generator. */
    private \mod_presenterai_generator $gen;

    /**
     * One course, one graded activity and one learner.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', [
            'course' => $this->course->id,
            'grade' => 100,
        ]);
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
    }

    /**
     * A recording with an AI score.
     *
     * @param int $userid The learner.
     * @param int $presenteraiid The activity.
     * @param int $attempt The attempt number.
     * @param float|null $pct The score's percentage, or null for no score row.
     * @param array $score Other fields for the score row.
     * @param string $status The recording's status.
     * @return \stdClass The recording row.
     */
    private function scored(
        int $userid,
        int $presenteraiid,
        int $attempt,
        ?float $pct,
        array $score = [],
        string $status = 'scored'
    ): \stdClass {
        $rec = $this->gen->create_recording([
            'presenteraiid' => $presenteraiid,
            'userid' => $userid,
            'status' => $status,
            'attemptnumber' => $attempt,
        ]);
        if ($pct !== null) {
            $this->gen->create_score($score + [
                'recordingid' => $rec->id,
                'origin' => 'ai',
                'overallpct' => $pct,
                'released' => 1,
                'tips' => json_encode(['Tip for ' . $pct]),
            ]);
        }
        return $rec;
    }

    /**
     * The previous attempt is the learner's own latest earlier released score in the same activity, and nothing else.
     *
     * @return void
     */
    public function test_previous_released_reads_only_the_learners_own_earlier_released_score(): void {
        $uid = (int) $this->learner->id;
        $pid = (int) $this->instance->id;
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $otheractivity = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);

        $first = $this->scored($uid, $pid, 1, 60.0, ['tips' => json_encode(['Open with the claim.', '', 'Slow down.'])]);
        $this->assertNull(score_manager::previous_released($first), 'A first attempt has no previous one.');

        $held = $this->scored($uid, $pid, 2, 90.0, ['released' => 0]);
        $this->scored($uid, $pid, 3, null, [], 'failed');
        $this->scored((int) $other->id, $pid, 3, 99.0);
        $this->scored($uid, (int) $otheractivity->id, 3, 95.0);
        // Attempt 5 made before attempt 4 is still a later attempt: the order is the attempt number.
        $this->scored($uid, $pid, 5, 70.0);
        $current = $this->scored($uid, $pid, 4, null, [], 'scoring');

        $previous = score_manager::previous_released($current);
        $this->assertSame((int) $first->id, $previous['recordingid'], 'Held, failed, later, other learner or other activity used.');
        $this->assertEqualsWithDelta(60.0, $previous['pct'], 0.001);
        $this->assertSame(['Open with the claim.', 'Slow down.'], $previous['tips']);

        // Once the held score is released it's the previous one; a teacher's score on it wins, and has no tips.
        $this->gen->create_score(['recordingid' => $held->id, 'origin' => 'teacher', 'overallpct' => 85.0,
            'timecreated' => time() + 10]);
        $previous = score_manager::previous_released($current);
        $this->assertSame((int) $held->id, $previous['recordingid']);
        $this->assertEqualsWithDelta(85.0, $previous['pct'], 0.001);
        $this->assertSame([], $previous['tips']);
    }

    /**
     * Two recordings with the same attempt number are ordered by id, as the progress table orders them.
     *
     * @return void
     */
    public function test_previous_released_same_attempt_number(): void {
        $a = $this->scored((int) $this->learner->id, (int) $this->instance->id, 2, 40.0);
        $b = $this->scored((int) $this->learner->id, (int) $this->instance->id, 2, 50.0);
        $this->assertSame((int) $a->id, score_manager::previous_released($b)['recordingid']);
        $this->assertNull(score_manager::previous_released($a));
    }

    /**
     * The scorer passes the previous attempt to the prompt, but not while the gradebook hides the grade.
     *
     * @return void
     */
    public function test_scorer_previous_respects_a_hidden_grade(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $this->scored((int) $this->learner->id, (int) $this->instance->id, 1, 55.5);
        $current = $this->scored((int) $this->learner->id, (int) $this->instance->id, 2, null, [], 'scoring');

        $this->assertSame(['pct' => 55.5, 'tips' => ['Tip for 55.5']], scorer::previous($current, $this->instance));

        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'presenterai',
            'iteminstance' => $this->instance->id, 'courseid' => $this->course->id]);
        $item->set_hidden(1);
        $this->assertNull(scorer::previous($current, $this->instance));
    }

    /**
     * A row as attempt_row::export() gives it, with only the keys progress() reads.
     *
     * @param int $recid The recording id.
     * @param string $attempt The attempt number, or '-'.
     * @param float|null $pct The percentage, or null for no score.
     * @param bool $inreview Whether the score is held for review.
     * @return array
     */
    private static function row(int $recid, string $attempt, ?float $pct, bool $inreview = false): array {
        return [
            'recid' => $recid,
            'attempt' => $attempt,
            'inreview' => $inreview,
            'hasscore' => $pct !== null && !$inreview,
            'score' => ($pct !== null && !$inreview) ? format_float($pct, 2) . '%' : '',
            'scorepct' => ($pct !== null && !$inreview) ? $pct : null,
        ];
    }

    /**
     * Progress lists scored attempts oldest first with the change since the one before, and needs two of them.
     *
     * @return void
     */
    public function test_progress_summary(): void {
        $this->assertFalse(view_page::progress([])['hasprogress']);
        $this->assertFalse(view_page::progress([self::row(1, '1', 60.0)])['hasprogress'], 'One scored attempt is no progress.');
        $this->assertFalse(view_page::progress([
            self::row(2, '2', 70.0, true),
            self::row(1, '1', 60.0),
            self::row(3, '-', null),
        ])['hasprogress'], 'A held score or an unscored row counts.');

        // Newest first, as the page lists them.
        $progress = view_page::progress([
            self::row(5, '5', 72.5),
            self::row(4, '4', 72.5),
            self::row(3, '3', 80.0),
            self::row(9, '-', null),
            self::row(2, '2', 70.0, true),
            self::row(1, '1', 60.0),
        ]);
        $this->assertTrue($progress['hasprogress']);
        $this->assertSame(['1', '3', '4', '5'], array_column($progress['rows'], 'attempt'));
        $this->assertSame(['first', 'up', 'down', 'same'], array_column($progress['rows'], 'direction'));
        $this->assertSame([
            get_string('progress_first', 'mod_presenterai'),
            get_string('progress_up', 'mod_presenterai', '20'),
            get_string('progress_down', 'mod_presenterai', '7.5'),
            get_string('progress_same', 'mod_presenterai'),
        ], array_column($progress['rows'], 'change'));
        $this->assertSame('Up 20 percentage points', $progress['rows'][1]['change']);
        $this->assertSame(
            'Your score went from 60.00% on attempt 1 to 72.50% on attempt 5.',
            $progress['summary']
        );
    }

    /**
     * The learner page carries progress for the learner's own scored attempts, and renders it as an accessible table.
     *
     * @return void
     */
    public function test_view_page_renders_progress(): void {
        global $DB, $PAGE;

        $now = time();
        $uid = (int) $this->learner->id;
        $pid = (int) $this->instance->id;
        foreach ([[1, 50.0], [2, 65.0]] as [$n, $pct]) {
            $rec = $this->gen->create_recording(['presenteraiid' => $pid, 'userid' => $uid, 'status' => 'scored',
                'attemptnumber' => $n, 'timecreated' => $now - 100 + $n]);
            $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'ai', 'overallpct' => $pct, 'released' => 1]);
        }
        // Another learner's attempt never appears.
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $rec = $this->gen->create_recording(['presenteraiid' => $pid, 'userid' => $other->id, 'status' => 'scored']);
        $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'ai', 'overallpct' => 99.0, 'released' => 1]);

        $this->setUser($this->learner);
        $instance = $DB->get_record('presenterai', ['id' => $pid], '*', MUST_EXIST);
        $context = \context_module::instance($this->instance->cmid);
        $page = new view_page($instance, $this->course, $context, $uid);
        $data = $page->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($data['progress']['hasprogress']);
        $this->assertSame(['1', '2'], array_column($data['progress']['rows'], 'attempt'));
        $this->assertSame('Up 15 percentage points', $data['progress']['rows'][1]['change']);

        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/progress', $data['progress']);
        $this->assertStringContainsString(get_string('progress_heading', 'mod_presenterai'), $html);
        $this->assertStringContainsString('<caption', $html);
        $this->assertSame(3, substr_count($html, 'scope="col"'));
        $this->assertSame(2, substr_count($html, 'scope="row"'));
        $this->assertStringContainsString('Your score went from 50.00% on attempt 1 to 65.00% on attempt 2.', $html);
        $this->assertStringNotContainsString('99.00%', $html);
        $this->assertStringNotContainsString('<canvas', $html);
        $this->assertStringNotContainsString('<svg', $html);

        // The whole page renders it, above the attempts disclosure.
        $page = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/view', $data);
        $this->assertStringContainsString('data-region="progress"', $page);
        $this->assertLessThan(strpos($page, 'data-region="allattempts"'), strpos($page, 'data-region="progress"'));
    }
}
