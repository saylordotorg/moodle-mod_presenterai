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

namespace mod_presenterai\local\ai;

/**
 * Provider keys, encrypted at rest, read in plain text only here.
 *
 * A port of SOLA's secrets class (v7.7.3). Every key setting is an
 * admin_setting_encryptedpassword, which stores \core\encryption output
 * ("sodium:..." or "openssl-aes-256-ctr:...") keyed by a site key in
 * dataroot. get_config() returns that ciphertext, which a provider would
 * reject as a bad key, so everything reads keys through get().
 *
 * A value without the encryption prefix is returned as it is: a key forced
 * in config.php never passes through the setting. A value that carries the
 * prefix but will not decrypt returns '' and a developer notice, never the
 * ciphertext. The usual cause is a database restored on a site whose
 * dataroot holds a different key, where the keys have to be entered again.
 *
 * A key never leaves the server: not to a template, an event, a log line,
 * mtrace or an exception message.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class secrets {
    /**
     * Every setting that holds a secret.
     *
     * @return string[]
     */
    public static function names(): array {
        return ['claudeapikey', 'openaiapikey', 'geminiapikey', 'compatibleapikey', 'sttapikey'];
    }

    /**
     * Read a secret setting as plain text.
     *
     * @param string $name A setting name from names().
     * @return string '' when unset or when it will not decrypt.
     */
    public static function get(string $name): string {
        if (!in_array($name, self::names(), true)) {
            throw new \coding_exception('Not a mod_presenterai secret: ' . $name);
        }
        return self::reveal((string) (get_config('mod_presenterai', $name) ?: ''));
    }

    /**
     * Turn a stored value into plain text.
     *
     * @param string $stored Ciphertext from \core\encryption, or a plain value.
     * @return string
     */
    public static function reveal(string $stored): string {
        $stored = trim($stored);
        if (!self::is_encrypted($stored)) {
            return $stored;
        }
        try {
            return trim((string) \core\encryption::decrypt($stored));
        } catch (\Throwable $e) {
            debugging(
                'mod_presenterai: a stored key could not be decrypted with this site\'s encryption key, '
                . 'so it is treated as unset. If this database came from another site, enter the keys again.',
                DEBUG_DEVELOPER
            );
            return '';
        }
    }

    /**
     * Whether a stored value is \core\encryption output.
     *
     * Literal prefixes rather than class constants: Moodle 5.0 removed
     * \core\encryption::METHOD_OPENSSL, and a value written by 4.x with it
     * must still be recognised as ciphertext.
     *
     * @param string $value A stored value.
     * @return bool
     */
    public static function is_encrypted(string $value): bool {
        return (bool) preg_match('~^(sodium|openssl-aes-256-ctr):~', $value);
    }
}
