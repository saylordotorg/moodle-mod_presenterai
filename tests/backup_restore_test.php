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
 * Course backup and restore of an activity, its topics, rubrics and, with user data, its attempts.
 *
 * Until phase 1 the module declared FEATURE_BACKUP_MOODLE2 with no backup
 * classes behind it, so any course backup containing the activity failed. This
 * is the test that says it no longer does, and that what comes back is the
 * same activity: settings, topics in order, each topic's file under that
 * topic's new id, and links in the intro pointing at the new course module.
 *
 * From phase 2 a backup with user data also carries attempts and scores, and
 * every restored attempt either has media this site can reach or says
 * honestly that it does not ('notbackedup', plan 4.7).
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
        // Rubric 5 is not a rubric of this activity's context, so it is not in
        // the backup, and the restored activity must not point at it.
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

    /**
     * Back a course up with user data, then restore it as a new course on this site.
     *
     * @param \stdClass $course The course to back up.
     * @param bool $othersite Pretend the backup came from another site, by changing its site hash.
     * @return int The new course id.
     */
    private function backup_and_restore_with_users(\stdClass $course, bool $othersite = false): int {
        global $CFG, $USER;

        $CFG->backup_file_logger_level = backup::LOG_NONE;

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupid = 'presenterai_' . random_string(10);
        $path = make_backup_temp_directory($backupid);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);
        if ($othersite) {
            $xml = file_get_contents($path . '/moodle_backup.xml');
            $xml = preg_replace(
                '~<original_site_identifier_hash>[^<]*</original_site_identifier_hash>~',
                '<original_site_identifier_hash>' . md5('another site') . '</original_site_identifier_hash>',
                $xml
            );
            file_put_contents($path . '/moodle_backup.xml', $xml);
        }

        $newcourseid = restore_dbops::create_new_course($course->fullname, $course->shortname . '_u', $course->category);
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->execute_precheck();
        $this->assertArrayNotHasKey('errors', $rc->get_precheck_results());
        $rc->execute_plan();
        $rc->destroy();

        return (int) $newcourseid;
    }

    /**
     * A file in one of a recording's media areas.
     *
     * @param \context_module $context The module context.
     * @param string $area recording, deck or frames.
     * @param int $itemid The recording id.
     * @param string $filename The key.
     * @param string $content The bytes.
     * @return void
     */
    private function media_file(\context_module $context, string $area, int $itemid, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_presenterai',
            'filearea' => $area,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * A score row, straight to the table.
     *
     * @param int $recordingid The recording.
     * @param int $userid The learner.
     * @param string $origin 'ai' or 'teacher'.
     * @param int $graderid The grader, or 0.
     * @param int $rubricid The rubric, or 0 for the in-code default.
     * @return int The score id.
     */
    private function score_row(int $recordingid, int $userid, string $origin, int $graderid, int $rubricid): int {
        global $DB;

        return (int) $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $recordingid,
            'userid' => $userid,
            'rubricid' => $rubricid,
            'origin' => $origin,
            'scores' => json_encode([['name' => 'Clarity', 'score' => 4, 'max_score' => 5]]),
            'rawsum' => 4,
            'rawmax' => 5,
            'overallpct' => 80,
            'feedback' => 'From ' . $origin,
            'graderid' => $graderid,
            'timecreated' => time(),
        ]);
    }

    /**
     * An activity with learner work in every media state, for the user data tests.
     *
     * @return array [course, instance, context, alice, bob, teacher, rubricid, topic, fsrec, s3rec, missingrec, teacherscoreid]
     */
    private function course_with_learner_work(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $plugin = $generator->get_plugin_generator('mod_presenterai');
        $course = $generator->create_course();
        $instance = $generator->create_module('presenterai', ['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);
        $alice = $generator->create_and_enrol($course, 'student');
        $bob = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $rubricid = (int) $DB->insert_record('presenterai_rubric', (object) [
            'contextid' => $context->id,
            'type' => 'speech',
            'title' => 'Our rubric',
            'criteria' => json_encode([['name' => 'Clarity', 'max_score' => 5]]),
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->set_field('presenterai', 'rubricid', $rubricid, ['id' => $instance->id]);
        $topic = $plugin->create_topic(['presenteraiid' => $instance->id, 'title' => 'Pitch']);

        $fsrec = $plugin->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $alice->id,
            'topicid' => $topic->id,
            'storagekey' => 'original-recording-key.webm',
            'deckkey' => 'original-deck-key.pdf',
            'transcript' => 'Hello there.',
            'expiresat' => time() + 30 * DAYSECS,
        ]);
        $this->media_file($context, 'recording', (int) $fsrec->id, $fsrec->storagekey, 'the video bytes');
        $this->media_file($context, 'deck', (int) $fsrec->id, $fsrec->deckkey, '%PDF-1.4 the deck');
        $this->score_row((int) $fsrec->id, (int) $alice->id, 'ai', 0, 0);
        $teacherscoreid = $this->score_row((int) $fsrec->id, (int) $alice->id, 'teacher', (int) $teacher->id, $rubricid);
        $DB->set_field('presenterai_recording', 'scoreid', $teacherscoreid, ['id' => $fsrec->id]);

        $s3rec = $plugin->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $alice->id,
            'attemptnumber' => 2,
            'backend' => 's3',
            'storagekey' => 'presenterai/' . $course->id . '/' . $alice->id . '/s3-object.webm',
        ]);

        // A row whose file is not there to be backed up.
        $missingrec = $plugin->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $bob->id,
            'storagekey' => 'no-file-for-this-key.webm',
            'visualevidence' => 'A note about a video this course will not have.',
        ]);

        // An unfinished attempt is never backed up.
        $plugin->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $alice->id,
            'status' => 'uploading',
            'attemptnumber' => 0,
            'clienttoken' => random_string(32),
        ]);

        return [$course, $instance, $context, $alice, $bob, $teacher, $rubricid, $topic, $fsrec, $s3rec, $missingrec,
            $teacherscoreid];
    }

    /**
     * Without user data: rubrics come back and the activity points at its own copy, but no attempts do.
     *
     * @return void
     */
    public function test_without_userinfo_rubrics_come_back_and_attempts_do_not(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->course_with_learner_work();

        $newcourseid = $this->backup_and_restore($course);

        $restored = $DB->get_record('presenterai', ['course' => $newcourseid], '*', MUST_EXIST);
        $newcm = get_coursemodule_from_instance('presenterai', $restored->id, $newcourseid, false, MUST_EXIST);
        $newcontext = \context_module::instance($newcm->id);
        $rubric = $DB->get_record('presenterai_rubric', ['contextid' => $newcontext->id], '*', MUST_EXIST);
        $this->assertSame('Our rubric', $rubric->title);
        $this->assertSame((int) $rubric->id, (int) $restored->rubricid);

        $this->assertSame(0, $DB->count_records('presenterai_recording', ['presenteraiid' => $restored->id]));
    }

    /**
     * With user data: attempts and scores come back mapped, fs bytes arrive under new keys, and the gaps are honest.
     *
     * @return void
     */
    public function test_with_userinfo_attempts_scores_and_fs_media_are_restored(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $instance, , $alice, $bob, $teacher, , , $fsrec, $s3rec] = $this->course_with_learner_work();

        $newcourseid = $this->backup_and_restore_with_users($course);

        $restored = $DB->get_record('presenterai', ['course' => $newcourseid], '*', MUST_EXIST);
        $newcm = get_coursemodule_from_instance('presenterai', $restored->id, $newcourseid, false, MUST_EXIST);
        $newcontext = \context_module::instance($newcm->id);
        $newrubric = $DB->get_record('presenterai_rubric', ['contextid' => $newcontext->id], '*', MUST_EXIST);
        $this->assertSame((int) $newrubric->id, (int) $restored->rubricid);
        $newtopic = $DB->get_record('presenterai_topic', ['presenteraiid' => $restored->id], '*', MUST_EXIST);

        $rows = $DB->get_records('presenterai_recording', ['presenteraiid' => $restored->id], 'id');
        $this->assertCount(3, $rows, 'The uploading attempt was restored, or a finished one was not.');
        foreach ($rows as $row) {
            $this->assertNotSame('uploading', $row->status);
            $this->assertNull($row->clienttoken);
            $this->assertNull($row->uploadid);
        }

        // The fs attempt: mapped ids, both scores, and its bytes under a fresh key.
        $fsrows = array_filter($rows, fn($r) => $r->backend === 'fs' && (int) $r->userid === (int) $alice->id);
        $this->assertCount(1, $fsrows);
        $newfs = reset($fsrows);
        $this->assertSame((int) $newtopic->id, (int) $newfs->topicid);
        $this->assertSame('Hello there.', $newfs->transcript);
        $this->assertSame((int) $fsrec->expiresat, (int) $newfs->expiresat);
        $scores = $DB->get_records('presenterai_score', ['recordingid' => $newfs->id], 'id');
        $this->assertCount(2, $scores);
        $teacherscore = null;
        foreach ($scores as $score) {
            $this->assertSame((int) $alice->id, (int) $score->userid);
            if ($score->origin === 'teacher') {
                $teacherscore = $score;
            }
        }
        $this->assertNotNull($teacherscore);
        $this->assertSame((int) $teacher->id, (int) $teacherscore->graderid);
        $this->assertSame((int) $newrubric->id, (int) $teacherscore->rubricid);
        $this->assertSame((int) $teacherscore->id, (int) $newfs->scoreid);

        $this->assertNotSame($fsrec->storagekey, $newfs->storagekey, 'Two rows now name one fs filename.');
        $fs = get_file_storage();
        $video = $fs->get_file($newcontext->id, 'mod_presenterai', 'recording', $newfs->id, '/', $newfs->storagekey);
        $this->assertNotFalse($video);
        $this->assertSame('the video bytes', $video->get_content());
        $deck = $fs->get_file($newcontext->id, 'mod_presenterai', 'deck', $newfs->id, '/', $newfs->deckkey);
        $this->assertNotFalse($deck);

        // The original still has its own file, untouched.
        $oldcontext = \context_module::instance($instance->cmid);
        $this->assertNotFalse($fs->get_file($oldcontext->id, 'mod_presenterai', 'recording', $fsrec->id, '/', $fsrec->storagekey));

        // The S3 attempt on this same site keeps its key.
        $s3rows = array_filter($rows, fn($r) => $r->backend === 's3');
        $this->assertSame($s3rec->storagekey, reset($s3rows)->storagekey);

        // Bob's file was not there, so his attempt arrives honestly without media.
        $bobrows = array_filter($rows, fn($r) => (int) $r->userid === (int) $bob->id);
        $bobrow = reset($bobrows);
        $this->assertNull($bobrow->storagekey);
        $this->assertSame('notbackedup', $bobrow->mediagonereason);
        $this->assertGreaterThan(0, (int) $bobrow->mediadeletedat);
        $this->assertNull($bobrow->visualevidence);
    }

    /**
     * After a same-site restore the shared S3 object survives either copy's deletion.
     *
     * The bucket is unreachable, so a delete that was attempted would fail and
     * drop_media() would return false. True proves the shared object was left alone.
     *
     * @return void
     */
    public function test_same_site_s3_restore_is_protected_by_the_shared_key_guard(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, , , , , , , , , $s3rec] = $this->course_with_learner_work();
        $newcourseid = $this->backup_and_restore_with_users($course);
        $restored = $DB->get_record('presenterai', ['course' => $newcourseid], '*', MUST_EXIST);
        $copy = $DB->get_record('presenterai_recording', ['presenteraiid' => $restored->id, 'backend' => 's3'], '*', MUST_EXIST);
        $this->assertSame($s3rec->storagekey, $copy->storagekey);

        set_config('s3key', 'AKIAIOSFODNN7EXAMPLE', 'mod_presenterai');
        set_config('s3secret', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'mod_presenterai');
        set_config('s3bucket', 'presenterai-test', 'mod_presenterai');
        set_config('s3region', 'us-east-1', 'mod_presenterai');
        set_config('s3endpoint', 'http://127.0.0.1:1', 'mod_presenterai');
        set_config('s3pathstyle', 1, 'mod_presenterai');

        $original = $DB->get_record('presenterai_recording', ['id' => $s3rec->id], '*', MUST_EXIST);
        $this->assertTrue(\mod_presenterai\local\recording_manager::drop_media($original, 'learner'));
        $this->assertSame(
            $s3rec->storagekey,
            $DB->get_field('presenterai_recording', 'storagekey', ['id' => $copy->id]),
            'The restored copy lost media its learner still has.'
        );
    }

    /**
     * From another site, S3 attempts arrive without media, because the bucket is not this site's.
     *
     * @return void
     */
    public function test_other_site_s3_restore_is_not_backed_up(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->course_with_learner_work();
        $newcourseid = $this->backup_and_restore_with_users($course, true);

        $restored = $DB->get_record('presenterai', ['course' => $newcourseid], '*', MUST_EXIST);
        $s3rows = $DB->get_records('presenterai_recording', ['presenteraiid' => $restored->id, 'backend' => 's3']);
        $this->assertCount(1, $s3rows);
        $row = reset($s3rows);
        $this->assertNull($row->storagekey);
        $this->assertSame('notbackedup', $row->mediagonereason);
    }

    /**
     * The events about a recording map their object to the restored recording.
     *
     * @return void
     */
    public function test_recording_events_map_on_restore(): void {
        $expected = ['db' => 'presenterai_recording', 'restore' => 'presenterai_recording'];
        $this->assertSame($expected, \mod_presenterai\event\recording_deleted::get_objectid_mapping());
        $this->assertSame($expected, \mod_presenterai\event\recording_downloaded::get_objectid_mapping());
    }
}
