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

use mod_presenterai\local\ai\secrets;
use mod_presenterai\local\storage\s3_store;

/**
 * The S3 secret is encrypted at rest, like every other key (0.5.1).
 *
 * It was a configpasswordunmask, which masks the value in the form and the config log but stores plain text.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\secrets
 * @covers     \mod_presenterai\local\storage\s3_store
 */
final class s3_secret_encryption_test extends \advanced_testcase {
    /** @var string A plausible secret access key (AWS's published example). */
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    /**
     * The settings page declares the secret as an encrypted field, and saving it stores ciphertext.
     *
     * @return void
     */
    public function test_the_setting_is_an_encrypted_password_that_stores_ciphertext(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        // The plugin's settings load only for a user who holds moodle/site:config.
        $this->setAdminUser();

        $page = admin_get_root(true, true)->locate('modsettingpresenterai');
        $this->assertNotNull($page, 'the plugin settings page is registered');
        $setting = null;
        foreach ((array) $page->settings as $candidate) {
            if ($candidate->get_full_name() === 's_mod_presenterai_s3secret') {
                $setting = $candidate;
            }
        }
        $this->assertNotNull($setting, 'the S3 secret setting is on the page');
        $this->assertInstanceOf(\admin_setting_encryptedpassword::class, $setting);

        $this->assertSame('', $setting->write_setting(self::SECRET));
        $stored = (string) get_config('mod_presenterai', 's3secret');
        $this->assertNotSame(self::SECRET, $stored);
        $this->assertTrue(secrets::is_encrypted($stored));
        $this->assertSame(self::SECRET, secrets::get('s3secret'));
    }

    /**
     * A store signs with the decrypted secret, not with the ciphertext it finds in the config table.
     *
     * Two stores sign the same key in the same second, one reading the stored ciphertext and one given the plain
     * secret directly. Their URLs, signatures included, must be identical.
     *
     * @return void
     */
    public function test_the_store_signs_with_the_decrypted_secret(): void {
        $this->resetAfterTest();
        set_config('s3key', 'AKIAIOSFODNN7EXAMPLE', 'mod_presenterai');
        set_config('s3bucket', 'presenterai-test', 'mod_presenterai');
        set_config('s3region', 'us-east-1', 'mod_presenterai');
        set_config('s3secret', \core\encryption::encrypt(self::SECRET), 'mod_presenterai');

        $key = 'presenterai/1/2/abc.webm';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $fromconfig = (new s3_store())->read_url($key, 60);
            $direct = (new s3_store([
                'key' => 'AKIAIOSFODNN7EXAMPLE',
                'secret' => self::SECRET,
                'bucket' => 'presenterai-test',
                'region' => 'us-east-1',
            ]))->read_url($key, 60);
            if ($fromconfig === $direct) {
                break;
            }
        }
        $this->assertSame($direct, $fromconfig);
        $this->assertTrue((new s3_store())->is_configured());
    }

    /**
     * A ciphertext this site cannot decrypt reads as no secret, so the backend reports itself unconfigured.
     *
     * @return void
     */
    public function test_an_undecryptable_secret_means_not_configured(): void {
        $this->resetAfterTest();
        set_config('s3key', 'AKIAIOSFODNN7EXAMPLE', 'mod_presenterai');
        set_config('s3bucket', 'presenterai-test', 'mod_presenterai');
        set_config('s3region', 'us-east-1', 'mod_presenterai');
        set_config('s3secret', 'sodium:' . base64_encode(str_repeat('x', 64)), 'mod_presenterai');

        $this->assertFalse((new s3_store())->is_configured());
        $this->assertDebuggingCalled();
    }

    /**
     * Run the plugin's real upgrade function as a site on the given build would.
     *
     * @param int $from The build the site is upgrading from.
     * @return void
     */
    private function upgrade_from(int $from): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/presenterai/db/upgrade.php');
        set_config('version', $from, 'mod_presenterai');
        $this->assertTrue(\xmldb_presenterai_upgrade($from));
    }

    /**
     * Upgrading from 0.5.0 encrypts a stored plain secret, and a repeat leaves the ciphertext alone.
     *
     * @return void
     */
    public function test_the_upgrade_encrypts_a_plain_secret_once(): void {
        $this->resetAfterTest();
        set_config('s3secret', self::SECRET, 'mod_presenterai');

        $this->upgrade_from(2026101901);
        $stored = (string) get_config('mod_presenterai', 's3secret');
        $this->assertNotSame(self::SECRET, $stored);
        $this->assertTrue(secrets::is_encrypted($stored));
        $this->assertSame(self::SECRET, secrets::get('s3secret'));
        $this->assertGreaterThanOrEqual(2026102001, (int) get_config('mod_presenterai', 'version'));

        // A second run sees ciphertext and writes nothing: the stored value is byte-for-byte what it was.
        $this->upgrade_from(2026101901);
        $this->assertSame($stored, (string) get_config('mod_presenterai', 's3secret'));
    }

    /**
     * The stored S3 secret, straight from the config table (get_config() would return a forced value instead).
     *
     * @return string '' when the row is empty or missing.
     */
    private function stored_secret(): string {
        global $DB;
        return (string) $DB->get_field('config_plugins', 'value', ['plugin' => 'mod_presenterai', 'name' => 's3secret']);
    }

    /**
     * A site with no S3 secret gets none written, and the upgrade still finishes.
     *
     * @return void
     */
    public function test_the_upgrade_with_no_secret_writes_nothing(): void {
        $this->resetAfterTest();

        $this->upgrade_from(2026101901);

        $this->assertSame('', $this->stored_secret());
    }

    /**
     * A secret forced in config.php is not copied into the database by the upgrade.
     *
     * @return void
     */
    public function test_the_upgrade_does_not_copy_a_forced_secret_into_the_database(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->forced_plugin_settings['mod_presenterai']['s3secret'] = self::SECRET;

        $this->upgrade_from(2026101901);

        $this->assertSame('', $this->stored_secret());
    }
}
