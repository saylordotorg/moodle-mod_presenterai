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

use mod_presenterai\local\storage\store_factory;

/**
 * The two rules that decide which backend a request touches.
 *
 * Both failures this file guards against are silent. A row read from the site
 * setting rather than from its own backend column finds no media and reports
 * the recording as missing; an unconfigured s3 that degrades to fs writes a
 * learner video somewhere the administrator does not believe it goes, and
 * nothing downstream notices, because an fs_store handed an S3 key simply finds
 * no file.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\storage\store_factory
 */
final class store_factory_test extends \advanced_testcase {

    /**
     * Give the site a complete and plausible S3 configuration.
     *
     * @return void
     */
    private function configure_s3(): void {
        set_config('s3key', 'AKIAIOSFODNN7EXAMPLE', 'mod_presenterai');
        set_config('s3secret', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'mod_presenterai');
        set_config('s3bucket', 'presenterai-test', 'mod_presenterai');
        set_config('s3region', 'us-east-1', 'mod_presenterai');
    }

    /**
     * A recording row on a named backend, read back from the database.
     *
     * Read back rather than built in memory, so the test exercises the same
     * object shape a caller gets from a query.
     *
     * @param string $backend The backend machine name to stamp on the row.
     * @return \stdClass The recording row.
     */
    private function recording_on(string $backend): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);

        $id = $DB->insert_record('presenterai_recording', (object) [
            'presenteraiid' => $instance->id,
            'userid' => $user->id,
            'attemptnumber' => 1,
            'mode' => 'video',
            'backend' => $backend,
            'status' => 'ready',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return $DB->get_record('presenterai_recording', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * An existing recording is read through the backend named on its own row.
     *
     * Section 4.7 of the plan lets an administrator switch backends without
     * migrating anything, so a site holds rows on both at once indefinitely.
     *
     * @return void
     */
    public function test_for_recording_follows_the_row_not_the_site_setting(): void {
        $this->resetAfterTest();
        $this->configure_s3();

        set_config('backend', store_factory::BACKEND_FS, 'mod_presenterai');
        $this->assertSame(
            store_factory::BACKEND_S3,
            store_factory::for_recording($this->recording_on(store_factory::BACKEND_S3))->name(),
            'A recording made on S3 was read through the file system store because the site setting has since been '
                . 'changed to fs. Every recording made before the switch is then reported as missing, and the '
                . 'retention task walks past the objects in the bucket forever.'
        );

        set_config('backend', store_factory::BACKEND_S3, 'mod_presenterai');
        $this->assertSame(
            store_factory::BACKEND_FS,
            store_factory::for_recording($this->recording_on(store_factory::BACKEND_FS))->name(),
            'A recording held in Moodle file storage was looked for in the bucket because the site setting now says '
                . 's3, so the learner is shown a missing recording for media that is still on disk.'
        );
    }

    /**
     * A row with no backend column is an error rather than a guess.
     *
     * @return void
     */
    public function test_a_row_without_its_backend_column_is_refused(): void {
        $this->resetAfterTest();
        set_config('backend', store_factory::BACKEND_FS, 'mod_presenterai');

        $this->expectException(\coding_exception::class);
        store_factory::for_recording((object) ['id' => 1, 'userid' => 2]);
    }

    /**
     * Selecting s3 without configuring it fails loudly instead of writing to fs.
     *
     * @return void
     */
    public function test_unconfigured_s3_throws_rather_than_falling_back(): void {
        $this->resetAfterTest();
        set_config('backend', store_factory::BACKEND_S3, 'mod_presenterai');

        try {
            $store = store_factory::default_store();
            $this->fail(
                'An unconfigured S3 backend returned a ' . $store->name() . ' store. The learner recording is then '
                    . 'written somewhere the administrator does not believe it goes, and nothing downstream notices, '
                    . 'because a file system store handed an S3 key simply finds no file.'
            );
        } catch (\moodle_exception $e) {
            $this->assertSame(
                'errorstorenotconfigured',
                $e->errorcode,
                'The administrator needs a message naming the backend and the missing settings. A bare exception '
                    . 'reads as a plugin fault rather than as four fields nobody filled in.'
            );
        }
    }

    /**
     * An existing S3 row is refused too, rather than read through the file system.
     *
     * @return void
     */
    public function test_an_s3_row_is_refused_while_s3_is_unconfigured(): void {
        $this->resetAfterTest();
        $this->configure_s3();
        $recording = $this->recording_on(store_factory::BACKEND_S3);

        set_config('s3key', '', 'mod_presenterai');

        try {
            $store = store_factory::for_recording($recording);
            $this->fail(
                'A recording stored in S3 was handed a ' . $store->name() . ' store after the credentials were '
                    . 'cleared. The learner is told their recording no longer exists, when in fact it is intact and '
                    . 'only the settings are missing.'
            );
        } catch (\moodle_exception $e) {
            $this->assertSame(
                'errorstorenotconfigured',
                $e->errorcode,
                'The message must say the backend is unconfigured, not that the media is gone.',
            );
        }
    }

    /**
     * The settings page can still build a store for a backend that is not working.
     *
     * That hole is deliberate. Both the settings page and the self test exist to
     * tell an administrator why a backend is broken, and neither can do that
     * with an exception in place of an object.
     *
     * @return void
     */
    public function test_unchecked_store_reports_rather_than_throws(): void {
        $this->resetAfterTest();

        $store = store_factory::unchecked_store(store_factory::BACKEND_S3);

        $this->assertSame(store_factory::BACKEND_S3, $store->name(
            ), 'The self test must be able to name the backend it is reporting on.',
        );
        $this->assertFalse(
            $store->is_configured(),
            'An unconfigured backend that reports itself as configured makes the settings page and the health check '
                . 'tell an administrator everything is fine while no upload can succeed.'
        );
    }

    /**
     * A backend name the plugin does not have is refused by name.
     *
     * @return void
     */
    public function test_an_unknown_backend_is_refused(): void {
        $this->resetAfterTest();

        try {
            store_factory::for_backend('minio');
            $this->fail(
                'A backend name this plugin does not implement was accepted, so media would be written by a '
                    . 'store that does not exist.',
            );
        } catch (\moodle_exception $e) {
            $this->assertSame(
                'errorunknownbackend',
                $e->errorcode,
                'A row written by a newer version of the plugin, or a setting holding a typo, must say so rather than '
                    . 'surface as a fatal the administrator cannot act on.'
            );
        }
    }

    /**
     * With nothing configured the site writes to Moodle file storage.
     *
     * @return void
     */
    public function test_the_default_backend_is_the_file_system(): void {
        $this->resetAfterTest();

        $this->assertSame(
            store_factory::BACKEND_FS,
            store_factory::default_backend(),
            'A site that has configured nothing must still be able to take a recording. Any other default makes the '
                . 'plugin unusable until an administrator finds a bucket.'
        );
        $this->assertSame(
            store_factory::BACKEND_FS,
            store_factory::default_store()->name(),
            'default_store() must agree with default_backend(), or new media is stamped with one backend and written '
                . 'through another.'
        );
    }
}
