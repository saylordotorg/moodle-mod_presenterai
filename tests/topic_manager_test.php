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

use mod_presenterai\local\topic_manager;

/**
 * Topics, their file area, and the guard that keeps one activity out of another's.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\topic_manager
 */
final class topic_manager_test extends \advanced_testcase {
    /**
     * Create an activity and return it with its module context.
     *
     * @param \stdClass|null $course The course, or null for a new one.
     * @return array [\stdClass instance, \context_module context]
     */
    private function create_activity(?\stdClass $course = null): array {
        $course = $course ?? $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);
        return [$instance, \context_module::instance($instance->cmid)];
    }

    /**
     * A draft area belonging to the current user, holding one PDF.
     *
     * The same thing the file manager element leaves behind when a teacher
     * uploads a brief and presses save.
     *
     * @param string $filename The file's name.
     * @return int The draft item id.
     */
    private function draft_with_pdf(string $filename = 'brief.pdf'): int {
        global $USER;
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], "%PDF-1.4\n% brief\n");
        return $draftitemid;
    }

    /**
     * Form data in the shape repeat_elements() posts.
     *
     * @param array $rows Each row: [topicid, title, instructions text, draft item id].
     * @return \stdClass
     */
    private function form_data(array $rows): \stdClass {
        $data = (object) ['topicid' => [], 'topictitle' => [], 'topicinstructions' => [], 'topicfile' => []];
        foreach ($rows as $index => [$id, $title, $text, $draft]) {
            $data->topicid[$index] = $id;
            $data->topictitle[$index] = $title;
            $data->topicinstructions[$index] = ['text' => $text, 'format' => FORMAT_HTML, 'itemid' => 0];
            $data->topicfile[$index] = $draft;
        }
        return $data;
    }

    /**
     * Names of the files in a topic's area.
     *
     * @param \context_module $context The module context.
     * @param int $topicid The topic id.
     * @return string[]
     */
    private function topic_files(\context_module $context, int $topicid): array {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_presenterai',
            topic_manager::FILEAREA,
            $topicid,
            'filename',
            false
        );
        return array_values(array_map(fn($file) => $file->get_filename(), $files));
    }

    /**
     * New rows with titles become topics in order; empty rows are skipped; the file lands on its topic.
     *
     * @return void
     */
    public function test_save_new_topics_with_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$instance, $context] = $this->create_activity();

        $draft = $this->draft_with_pdf();
        topic_manager::save_from_form($this->form_data([
            [0, 'First topic', '<p>Talk about one thing.</p>', $draft],
            [0, '', '', 0],
            [0, 'Second topic', '', 0],
        ]), (int) $instance->id, $context);

        $topics = array_values(topic_manager::get_topics((int) $instance->id));
        $this->assertCount(2, $topics);
        $this->assertSame('First topic', $topics[0]->title);
        $this->assertSame('<p>Talk about one thing.</p>', $topics[0]->instructions);
        $this->assertSame(0, (int) $topics[0]->sortorder);
        $this->assertSame('Second topic', $topics[1]->title);
        $this->assertSame(2, (int) $topics[1]->sortorder);

        $this->assertSame(['brief.pdf'], $this->topic_files($context, (int) $topics[0]->id));
        $this->assertSame([], $this->topic_files($context, (int) $topics[1]->id));
    }

    /**
     * A posted id updates that topic in place rather than creating another.
     *
     * @return void
     */
    public function test_update_title(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$instance, $context] = $this->create_activity();

        topic_manager::save_from_form($this->form_data([[0, 'Old title', '', 0]]), (int) $instance->id, $context);
        $topic = array_values(topic_manager::get_topics((int) $instance->id))[0];

        topic_manager::save_from_form(
            $this->form_data([[(int) $topic->id, 'New title', 'Notes', 0]]),
            (int) $instance->id,
            $context
        );

        $topics = topic_manager::get_topics((int) $instance->id);
        $this->assertCount(1, $topics);
        $this->assertSame('New title', $topics[$topic->id]->title);
        $this->assertSame('Notes', $topics[$topic->id]->instructions);
    }

    /**
     * Clearing a title deletes the topic and its file; an attempt that used it keeps everything but the label.
     *
     * @return void
     */
    public function test_delete_by_empty_title(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$instance, $context] = $this->create_activity();

        topic_manager::save_from_form(
            $this->form_data([[0, 'Doomed', '', $this->draft_with_pdf()], [0, 'Kept', '', 0]]),
            (int) $instance->id,
            $context
        );
        $topics = array_values(topic_manager::get_topics((int) $instance->id));
        [$doomed, $kept] = $topics;
        $this->assertNotEmpty($this->topic_files($context, (int) $doomed->id));

        $learner = $this->getDataGenerator()->create_user();
        $recordingid = $DB->insert_record('presenterai_recording', (object) [
            'presenteraiid' => $instance->id,
            'userid' => $learner->id,
            'topicid' => $doomed->id,
            'status' => 'uploaded',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $otherid = $DB->insert_record('presenterai_recording', (object) [
            'presenteraiid' => $instance->id,
            'userid' => $learner->id,
            'topicid' => $kept->id,
            'status' => 'uploaded',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        topic_manager::save_from_form(
            $this->form_data([[(int) $doomed->id, '   ', '', 0], [(int) $kept->id, 'Kept', '', 0]]),
            (int) $instance->id,
            $context
        );

        $this->assertFalse($DB->record_exists('presenterai_topic', ['id' => $doomed->id]));
        $this->assertTrue($DB->record_exists('presenterai_topic', ['id' => $kept->id]));
        $this->assertSame([], $this->topic_files($context, (int) $doomed->id));

        $recording = $DB->get_record('presenterai_recording', ['id' => $recordingid], '*', MUST_EXIST);
        $this->assertNull($recording->topicid);
        $this->assertSame((int) $kept->id, (int) $DB->get_field('presenterai_recording', 'topicid', ['id' => $otherid]));
    }

    /**
     * A topic id that belongs to another activity is neither updated nor deleted, and nothing is created.
     *
     * @return void
     */
    public function test_topicid_from_another_instance_is_ignored(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$mine, $minecontext] = $this->create_activity();
        [$theirs, $theircontext] = $this->create_activity();

        topic_manager::save_from_form($this->form_data([[0, 'Theirs', 'Original', 0]]), (int) $theirs->id, $theircontext);
        $theirtopic = array_values(topic_manager::get_topics((int) $theirs->id))[0];

        // An update through my activity naming their topic.
        topic_manager::save_from_form(
            $this->form_data([[(int) $theirtopic->id, 'Hijacked', 'Changed', 0]]),
            (int) $mine->id,
            $minecontext
        );
        // A delete through my activity naming their topic.
        topic_manager::save_from_form($this->form_data([[(int) $theirtopic->id, '', '', 0]]), (int) $mine->id, $minecontext);

        $after = $DB->get_record('presenterai_topic', ['id' => $theirtopic->id], '*', MUST_EXIST);
        $this->assertSame('Theirs', $after->title);
        $this->assertSame('Original', $after->instructions);
        $this->assertSame((int) $theirs->id, (int) $after->presenteraiid);
        $this->assertSame([], topic_manager::get_topics((int) $mine->id));
    }

    /**
     * Data without the topic arrays changes nothing, which is what a non-form caller sends.
     *
     * @return void
     */
    public function test_save_without_topic_fields_is_a_no_op(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$instance, $context] = $this->create_activity();
        topic_manager::save_from_form($this->form_data([[0, 'Stays', '', 0]]), (int) $instance->id, $context);

        topic_manager::save_from_form((object) ['name' => 'Renamed'], (int) $instance->id, $context);

        $this->assertCount(1, topic_manager::get_topics((int) $instance->id));
    }

    /**
     * export_for_view() returns exactly the keys the front end's template reads.
     *
     * @return void
     */
    public function test_export_for_view_keys(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$instance, $context] = $this->create_activity();

        topic_manager::save_from_form($this->form_data([
            [0, 'With everything', '<p>Brief</p>', $this->draft_with_pdf('case.pdf')],
            [0, 'Bare', '', 0],
        ]), (int) $instance->id, $context);

        $exported = topic_manager::export_for_view($instance, $context);

        $this->assertCount(2, $exported);
        $this->assertSame(
            ['id', 'title', 'instructions', 'hasinstructions', 'files', 'hasfiles'],
            array_keys($exported[0])
        );
        $this->assertSame('With everything', $exported[0]['title']);
        $this->assertTrue($exported[0]['hasinstructions']);
        $this->assertStringContainsString('Brief', $exported[0]['instructions']);
        $this->assertTrue($exported[0]['hasfiles']);
        $this->assertCount(1, $exported[0]['files']);
        $this->assertSame(['filename', 'url'], array_keys($exported[0]['files'][0]));
        $this->assertSame('case.pdf', $exported[0]['files'][0]['filename']);
        $this->assertStringContainsString(
            '/pluginfile.php/' . $context->id . '/mod_presenterai/topicfile/' . $exported[0]['id'] . '/case.pdf',
            $exported[0]['files'][0]['url']
        );

        $this->assertSame('Bare', $exported[1]['title']);
        $this->assertSame('', $exported[1]['instructions']);
        $this->assertFalse($exported[1]['hasinstructions']);
        $this->assertSame([], $exported[1]['files']);
        $this->assertFalse($exported[1]['hasfiles']);
    }

    /**
     * The serve_file() guard accepts this activity's topics and nothing else.
     *
     * @return void
     */
    public function test_topic_belongs_to_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$mine, $minecontext] = $this->create_activity();
        [$theirs, $theircontext] = $this->create_activity();

        topic_manager::save_from_form($this->form_data([[0, 'Mine', '', 0]]), (int) $mine->id, $minecontext);
        topic_manager::save_from_form($this->form_data([[0, 'Theirs', '', 0]]), (int) $theirs->id, $theircontext);
        $minetopic = array_values(topic_manager::get_topics((int) $mine->id))[0];
        $theirtopic = array_values(topic_manager::get_topics((int) $theirs->id))[0];

        $this->assertTrue(topic_manager::topic_belongs_to_context((int) $minetopic->id, $minecontext));
        $this->assertFalse(topic_manager::topic_belongs_to_context((int) $theirtopic->id, $minecontext));
        $this->assertFalse(topic_manager::topic_belongs_to_context(0, $minecontext));
        $this->assertFalse(topic_manager::topic_belongs_to_context((int) $theirtopic->id + 1000, $minecontext));
    }

    /**
     * serve_file() refuses before sending when the itemid is another activity's topic or the name is missing.
     *
     * @return void
     */
    public function test_serve_file_refuses_foreign_topic(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$mine, $minecontext] = $this->create_activity();
        [$theirs, $theircontext] = $this->create_activity();

        topic_manager::save_from_form(
            $this->form_data([[0, 'Theirs', '', $this->draft_with_pdf()]]),
            (int) $theirs->id,
            $theircontext
        );
        $theirtopic = array_values(topic_manager::get_topics((int) $theirs->id))[0];

        // Their topic id and filename, asked for through my context.
        $this->assertFalse(topic_manager::serve_file($minecontext, [$theirtopic->id, 'brief.pdf'], false, []));
        // No filename at all.
        $this->assertFalse(topic_manager::serve_file($theircontext, [$theirtopic->id], false, []));
        // The right topic, a file that is not there.
        $this->assertFalse(topic_manager::serve_file($theircontext, [$theirtopic->id, 'other.pdf'], false, []));
    }

    /**
     * delete_all() removes every topic of the activity and no other.
     *
     * @return void
     */
    public function test_delete_all(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$mine, $minecontext] = $this->create_activity();
        [$theirs, $theircontext] = $this->create_activity();

        topic_manager::save_from_form($this->form_data([[0, 'A', '', 0], [0, 'B', '', 0]]), (int) $mine->id, $minecontext);
        topic_manager::save_from_form($this->form_data([[0, 'C', '', 0]]), (int) $theirs->id, $theircontext);

        topic_manager::delete_all((int) $mine->id);

        $this->assertSame([], topic_manager::get_topics((int) $mine->id));
        $this->assertCount(1, topic_manager::get_topics((int) $theirs->id));
    }
}
