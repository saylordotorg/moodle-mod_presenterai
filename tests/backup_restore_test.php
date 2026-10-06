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

use backup;
use backup_controller;
use mod_presenterai\local\topic_manager;
use restore_controller;
use restore_dbops;

/**
 * Course backup and restore of an activity, its topics and their files.
 *
 * Until phase 1 the module declared FEATURE_BACKUP_MOODLE2 with no backup
 * classes behind it, so any course backup containing the activity failed. This
 * is the test that says it no longer does, and that what comes back is the
 * same activity: settings, topics in order, each topic's file under that
 * topic's new id, and links in the intro pointing at the new course module.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_presenterai_activity_task
 * @covers     \backup_presenterai_activity_structure_step
 * @covers     \restore_presenterai_activity_task
 * @covers     \restore_presenterai_activity_structure_step
 */
final class backup_restore_test extends \advanced_testcase {
    /**
     * Load the backup and restore libraries.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
    }

    /**
     * Back a course up and restore it as a new course, without user data.
     *
     * @param \stdClass $course The course to back up.
     * @return int The new course id.
     */
    private function backup_and_restore(\stdClass $course): int {
        global $CFG, $USER;

        // File logging off, or the log file cannot be removed on some platforms.
        $CFG->backup_file_logger_level = backup::LOG_NONE;

        // MODE_IMPORT leaves the backup unzipped in the temp directory, which is
        // all a same site restore needs.
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = restore_dbops::create_new_course($course->fullname, $course->shortname . '_2', $course->category);
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return (int) $newcourseid;
    }

    /**
     * A draft area for the current user holding one PDF.
     *
     * @param string $filename The file's name.
     * @param string $content The file's content.
     * @return int The draft item id.
     */
    private function draft_with_pdf(string $filename, string $content): int {
        global $USER;
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        return $draftitemid;
    }

    /**
     * The activity, its settings, its topics and the topic file all arrive in the new course.
     *
     * @return void
     */
    public function test_backup_and_restore_carries_instance_topics_and_file(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);

        $DB->update_record('presenterai', (object) [
            'id' => $instance->id,
            'intro' => '<p>See <a href="' . $CFG->wwwroot . '/mod/presenterai/view.php?id=' . $instance->cmid .
                '">this activity</a>.</p>',
            'mode' => 'audio',
            'minseconds' => 120,
            'maxseconds' => 240,
            'maxattempts' => 4,
            'storedattempts' => 3,
            'slidesenabled' => 1,
            'retentiondays' => 14,
            'legacyassignid' => 77,
            'rubricid' => 5,
        ]);

        $data = (object) [
            'topicid' => [0, 0],
            'topictitle' => ['Opening topic', 'Case study'],
            'topicinstructions' => [
                ['text' => '<p>Introduce yourself.</p>', 'format' => FORMAT_HTML],
                ['text' => '<p>Read the case.</p>', 'format' => FORMAT_HTML],
            ],
            'topicfile' => [0, $this->draft_with_pdf('case.pdf', "%PDF-1.4\n% case study\n")],
        ];
        topic_manager::save_from_form($data, (int) $instance->id, $context);
        $oldtopics = array_values(topic_manager::get_topics((int) $instance->id));

        $newcourseid = $this->backup_and_restore($course);

        $restored = $DB->get_record('presenterai', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertNotEquals((int) $instance->id, (int) $restored->id);
        $this->assertSame('audio', $restored->mode);
        $this->assertSame(120, (int) $restored->minseconds);
        $this->assertSame(240, (int) $restored->maxseconds);
        $this->assertSame(4, (int) $restored->maxattempts);
        $this->assertSame(3, (int) $restored->storedattempts);
        $this->assertSame(1, (int) $restored->slidesenabled);
        $this->assertSame(14, (int) $restored->retentiondays);
        $this->assertSame(77, (int) $restored->legacyassignid);
        // Rubrics are not in a phase 1 backup, so the restored activity must not
        // point at a rubric in the source context.
        $this->assertNull($restored->rubricid);

        $newcm = get_coursemodule_from_instance('presenterai', $restored->id, $newcourseid, false, MUST_EXIST);
        $newcontext = \context_module::instance($newcm->id);
        $this->assertStringContainsString('/mod/presenterai/view.php?id=' . $newcm->id . '"', $restored->intro);

        $newtopics = array_values(topic_manager::get_topics((int) $restored->id));
        $this->assertCount(2, $newtopics);
        $this->assertSame('Opening topic', $newtopics[0]->title);
        $this->assertSame('Case study', $newtopics[1]->title);
        $this->assertSame((int) $oldtopics[0]->sortorder, (int) $newtopics[0]->sortorder);
        $this->assertSame((int) $oldtopics[1]->sortorder, (int) $newtopics[1]->sortorder);
        $this->assertSame('<p>Read the case.</p>', $newtopics[1]->instructions);
        $this->assertNotEquals((int) $oldtopics[1]->id, (int) $newtopics[1]->id);

        // The file sits under the new topic id in the new context, with its content.
        $fs = get_file_storage();
        $file = $fs->get_file($newcontext->id, 'mod_presenterai', topic_manager::FILEAREA, $newtopics[1]->id, '/', 'case.pdf');
        $this->assertNotFalse($file);
        $this->assertSame("%PDF-1.4\n% case study\n", $file->get_content());
        $this->assertTrue($fs->is_area_empty($newcontext->id, 'mod_presenterai', topic_manager::FILEAREA, $newtopics[0]->id));

        // And the original is untouched.
        $this->assertNotFalse(
            $fs->get_file($context->id, 'mod_presenterai', topic_manager::FILEAREA, $oldtopics[1]->id, '/', 'case.pdf')
        );
        $this->assertTrue(topic_manager::topic_belongs_to_context((int) $newtopics[1]->id, $newcontext));
    }

    /**
     * An activity with no topics backs up and restores too, which is the case that failed outright before phase 1.
     *
     * @return void
     */
    public function test_backup_and_restore_without_topics(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id, 'name' => 'Lonely']);

        $newcourseid = $this->backup_and_restore($course);

        $restored = $DB->get_record('presenterai', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertSame('Lonely', $restored->name);
        $this->assertSame([], topic_manager::get_topics((int) $restored->id));
    }
}
