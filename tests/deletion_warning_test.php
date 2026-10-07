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

use mod_presenterai\local\deletion_warning;
use mod_presenterai\task\cleanup;

/**
 * The advance message before a recording reaches its deletion date.
 *
 * Sent once per date, only inside the window, only while there is media to
 * lose, and always naming the date on the row rather than one worked out from
 * today's settings (D22).
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\deletion_warning
 * @covers     \mod_presenterai\task\cleanup
 */
final class deletion_warning_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity. */
    private \stdClass $instance;

    /** @var \stdClass A learner. */
    private \stdClass $alice;

    /**
     * One course, one activity, one learner; the site warns three days ahead.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        unset_config('backend', 'mod_presenterai');
        set_config('deletewarndays', 3, 'mod_presenterai');
        set_config('allowlearnerdownload', 1, 'mod_presenterai');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->instance = $generator->create_module('presenterai', ['course' => $this->course->id, 'name' => 'Pitch practice']);
        $this->alice = $generator->create_and_enrol($this->course, 'student');
    }

    /**
     * A finished recording with media and the given deletion date.
     *
     * @param int $expiresat The deletion date.
     * @param array $fields Other overrides.
     * @return \stdClass
     */
    private function recording(int $expiresat, array $fields = []): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording($fields + [
            'presenteraiid' => $this->instance->id,
            'userid' => $this->alice->id,
            'storagekey' => random_string(32) . '.webm',
            'expiresat' => $expiresat,
            // Old enough that the cleanup task's one day floor does not apply.
            'timecreated' => time() - 10 * DAYSECS,
        ]);
    }

    /**
     * The row's deletewarnedat as the database holds it.
     *
     * @param \stdClass $rec The recording.
     * @return int
     */
    private function warnedat(\stdClass $rec): int {
        global $DB;

        return (int) $DB->get_field('presenterai_recording', 'deletewarnedat', ['id' => $rec->id], MUST_EXIST);
    }

    /**
     * Inside the window: one message, recorded on the row, and not sent again.
     *
     * @return void
     */
    public function test_sent_once_inside_the_window(): void {
        $now = time();
        $rec = $this->recording($now + 2 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->assertSame(1, deletion_warning::send_due($now));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('mod_presenterai', $messages[0]->component);
        $this->assertSame('deletionwarning', $messages[0]->eventtype);
        $this->assertSame((int) $this->alice->id, (int) $messages[0]->useridto);
        $this->assertStringContainsString('Pitch practice', $messages[0]->subject);
        $this->assertSame($now, $this->warnedat($rec));

        $sink->clear();
        $this->assertSame(0, deletion_warning::send_due($now + HOURSECS));
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * An activity name with '&' reads as '&' in the plain-text parts and stays escaped in the HTML body.
     *
     * @return void
     */
    public function test_name_escaped_only_in_html(): void {
        global $DB;

        $DB->set_field('presenterai', 'name', 'Q&A pitch', ['id' => $this->instance->id]);
        $now = time();
        $this->recording($now + 2 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->assertSame(1, deletion_warning::send_due($now));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Q&A pitch', $messages[0]->subject);
        $this->assertStringNotContainsString('&amp;', $messages[0]->fullmessage);
        $this->assertStringContainsString('Q&amp;A pitch', $messages[0]->fullmessagehtml);
    }

    /**
     * Nothing is sent before the window, without a date, without media, while uploading, or with the setting at 0.
     *
     * @return void
     */
    public function test_not_sent_when_it_should_not_be(): void {
        $now = time();
        $early = $this->recording($now + 5 * DAYSECS);
        $nodate = $this->recording(0);
        $nomedia = $this->recording($now + DAYSECS, ['storagekey' => null, 'mediagonereason' => 'learner']);
        $uploading = $this->recording($now + DAYSECS, ['status' => 'uploading']);
        $passed = $this->recording($now - 60);

        $sink = $this->redirectMessages();
        $this->assertSame(0, deletion_warning::send_due($now));
        $this->assertCount(0, $sink->get_messages());
        foreach ([$early, $nodate, $nomedia, $uploading, $passed] as $rec) {
            $this->assertSame(0, $this->warnedat($rec));
        }

        // Switched off: a row inside the window is left alone too.
        set_config('deletewarndays', 0, 'mod_presenterai');
        $inside = $this->recording($now + DAYSECS);
        $this->assertSame(0, deletion_warning::send_due($now));
        $this->assertCount(0, $sink->get_messages());
        $this->assertSame(0, $this->warnedat($inside));
    }

    /**
     * The date in the message is the row's, whatever the retention settings now say.
     *
     * @return void
     */
    public function test_the_date_comes_from_the_row(): void {
        $now = time();
        $expiresat = $now + 2 * DAYSECS + 1234;
        $this->recording($expiresat);
        // A settings change after the fact does not move the promised date.
        set_config('retentiondays', 400, 'mod_presenterai');

        $sink = $this->redirectMessages();
        deletion_warning::send_due($now);
        $message = $sink->get_messages()[0];

        $date = userdate($expiresat, get_string('strftimedatetime', 'langconfig'), $this->alice->timezone);
        $this->assertStringContainsString($date, $message->fullmessage);
        $this->assertStringContainsString($date, $message->fullmessagehtml);
        $this->assertStringContainsString($date, $message->smallmessage);
    }

    /**
     * The body says whether the learner can still save a copy.
     *
     * @return void
     */
    public function test_download_sentence_follows_access(): void {
        $now = time();
        $sink = $this->redirectMessages();

        $this->recording($now + DAYSECS);
        deletion_warning::send_due($now);
        $this->assertStringContainsString(
            get_string('message_deletionwarning_download', 'mod_presenterai'),
            $sink->get_messages()[0]->fullmessage
        );

        $sink->clear();
        set_config('allowlearnerdownload', 0, 'mod_presenterai');
        $this->recording($now + DAYSECS);
        deletion_warning::send_due($now);
        $this->assertStringContainsString(
            get_string('message_deletionwarning_nodownload', 'mod_presenterai'),
            $sink->get_messages()[0]->fullmessage
        );
    }

    /**
     * A suspended learner and a hidden activity are skipped, and the row is still marked.
     *
     * @return void
     */
    public function test_skipped_recipients_are_not_retried(): void {
        global $DB;

        $now = time();
        $rec = $this->recording($now + DAYSECS);
        $DB->set_field('user', 'suspended', 1, ['id' => $this->alice->id]);

        $sink = $this->redirectMessages();
        $this->assertSame(0, deletion_warning::send_due($now));
        $this->assertCount(0, $sink->get_messages());
        $this->assertSame($now, $this->warnedat($rec));
    }

    /**
     * The cleanup task sends the warnings, and does so before it applies retention.
     *
     * @return void
     */
    public function test_cleanup_warns_before_retention(): void {
        $now = time();
        $this->recording($now + DAYSECS);

        $sink = $this->redirectMessages();
        ob_start();
        (new cleanup())->execute();
        $output = (string) ob_get_clean();

        $this->assertCount(1, $sink->get_messages());
        $warned = strpos($output, 'advance deletion messages sent');
        $retention = strpos($output, 'deleted on their deletion date');
        $this->assertNotFalse($warned);
        $this->assertNotFalse($retention);
        $this->assertLessThan($retention, $warned);
    }
}
