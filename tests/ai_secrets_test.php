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

/**
 * Encrypted keys: decrypted on read, plain values passed through, a broken
 * ciphertext never returned.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\secrets
 */
final class ai_secrets_test extends \advanced_testcase {
    /**
     * The setting writes ciphertext, and get() reads it back as plain text.
     *
     * @return void
     */
    public function test_encrypted_setting_round_trip(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();

        $setting = new \admin_setting_encryptedpassword('mod_presenterai/claudeapikey', 'k', 'd');
        $this->assertSame('', $setting->write_setting('sk-ant-plain-value'));
        $stored = get_config('mod_presenterai', 'claudeapikey');
        $this->assertNotSame('sk-ant-plain-value', $stored);
        $this->assertTrue(secrets::is_encrypted($stored));
        $this->assertSame('sk-ant-plain-value', secrets::get('claudeapikey'));
    }

    /**
     * A plain value, such as a key forced in config.php, is returned as is.
     *
     * @return void
     */
    public function test_plain_value_passes_through(): void {
        $this->resetAfterTest();
        set_config('openaiapikey', '  sk-plain  ', 'mod_presenterai');
        $this->assertSame('sk-plain', secrets::get('openaiapikey'));
        $this->assertSame('', secrets::get('geminiapikey'));
    }

    /**
     * Ciphertext that will not decrypt reads as unset, never as the ciphertext.
     *
     * @return void
     */
    public function test_undecryptable_is_empty(): void {
        $this->resetAfterTest();
        $bad = 'sodium:' . base64_encode(str_repeat('x', 64));
        set_config('sttapikey', $bad, 'mod_presenterai');
        $this->assertSame('', secrets::get('sttapikey'));
        $this->assertDebuggingCalled();
    }

    /**
     * The secret names are fixed, and anything else is refused.
     *
     * @return void
     */
    public function test_names(): void {
        $this->assertSame(['claudeapikey', 'openaiapikey', 'geminiapikey', 'compatibleapikey', 'sttapikey'], secrets::names());
        $this->expectException(\coding_exception::class);
        secrets::get('s3secret');
    }
}
