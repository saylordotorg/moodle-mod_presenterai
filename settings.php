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

/**
 * Site administration settings for mod_presenterai.
 *
 * $settings, $ADMIN and $DB are all provided by the includer,
 * core\plugininfo\mod::load_settings() (lib/classes/plugininfo/mod.php:140-162),
 * which builds the page and includes this file only when the caller holds
 * moodle/site:config.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_presenterai\local\storage\store_factory;

if ($ADMIN->fulltree) {
    // Storage.

    $settings->add(new admin_setting_heading(
        'mod_presenterai/storageheading',
        get_string('storageheading', 'mod_presenterai'),
        get_string('storageheading_desc', 'mod_presenterai')
    ));

    // The menu is built from the factory's own list so that adding a backend
    // does not mean remembering to add it here as well.
    $backendchoices = [];
    foreach (store_factory::backends() as $backendname) {
        $backendchoices[$backendname] = get_string('backend' . $backendname, 'mod_presenterai');
    }

    $settings->add(new admin_setting_configselect(
        'mod_presenterai/backend',
        get_string('backend', 'mod_presenterai'),
        get_string('backend_desc', 'mod_presenterai'),
        store_factory::BACKEND_FS,
        $backendchoices
    ));

    // The s3 prefix on these names is load bearing. s3_store::config() in
    // classes/local/storage/s3_store.php turns a short name into
    // get_config('mod_presenterai', 's3' . $name), so renaming one of these
    // does not break anything visibly: it unconfigures the backend, and the
    // admin is left looking at a filled in form and a store that reports
    // itself unconfigured. The defaults below for region and prefix are the
    // same values as s3_store::DEFAULT_REGION and s3_store::DEFAULT_PREFIX, so
    // a blank field and a saved default resolve to the same bucket path.

    // PARAM_RAW_TRIMMED on the S3 text fields rather than PARAM_RAW, so a key
    // pasted with a trailing newline is refused on save:
    // admin_setting_configtext::validate() compares the submitted value against
    // clean_param() and reports a mismatch (lib/adminlib.php:2503-2523).
    // Accepting it would instead produce a signature mismatch on the first
    // upload, with nothing on this page to look at.
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/s3bucket',
        get_string('s3bucket', 'mod_presenterai'),
        get_string('s3bucket_desc', 'mod_presenterai'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'mod_presenterai/s3region',
        get_string('s3region', 'mod_presenterai'),
        get_string('s3region_desc', 'mod_presenterai'),
        'us-east-1',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'mod_presenterai/s3key',
        get_string('s3key', 'mod_presenterai'),
        get_string('s3key_desc', 'mod_presenterai'),
        '',
        PARAM_RAW_TRIMMED
    ));

    // Using configpasswordunmask rather than configtext: it masks the stored value in
    // the form and writes '********' to the config change log
    // (lib/adminlib.php:2757-2765) instead of the secret itself.
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_presenterai/s3secret',
        get_string('s3secret', 'mod_presenterai'),
        get_string('s3secret_desc', 'mod_presenterai'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_presenterai/s3prefix',
        get_string('s3prefix', 'mod_presenterai'),
        get_string('s3prefix_desc', 'mod_presenterai'),
        'presenterai/',
        PARAM_RAW_TRIMMED
    ));

    // PARAM_URL for the same reason: a value that is not a URL is refused on
    // save rather than quietly cleaned away to an empty string, which would
    // read as "no endpoint set" and send every request to Amazon.
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/s3endpoint',
        get_string('s3endpoint', 'mod_presenterai'),
        get_string('s3endpoint_desc', 'mod_presenterai'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_presenterai/s3pathstyle',
        get_string('s3pathstyle', 'mod_presenterai'),
        get_string('s3pathstyle_desc', 'mod_presenterai'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'mod_presenterai/s3lifecycledays',
        get_string('s3lifecycledays', 'mod_presenterai'),
        get_string('s3lifecycledays_desc', 'mod_presenterai'),
        0,
        PARAM_INT
    ));

    // Every S3 field is hidden unless the S3 backend is selected. hide_if is
    // display only: the values stay in the database and stay valid, which
    // matters because rows written while S3 was selected still have to be
    // readable after a switch (plan section 4.7).
    foreach (
        ['s3bucket', 's3region', 's3key', 's3secret', 's3prefix', 's3endpoint',
            's3pathstyle', 's3lifecycledays'] as $s3setting
    ) {
        $settings->hide_if(
            'mod_presenterai/' . $s3setting,
            'mod_presenterai/backend',
            'neq',
            store_factory::BACKEND_S3
        );
    }

    // Retention and download.

    $settings->add(new admin_setting_heading(
        'mod_presenterai/retentionheading',
        get_string('retentionheading', 'mod_presenterai'),
        get_string('retentionheading_desc', 'mod_presenterai')
    ));

    // Default 0, automatic deletion off, per DECISIONS.md D22. A negative value
    // is accepted rather than refused because section 8.1 of
    // docs/DESIGN-visual-feedback-and-retention.md resolves anything at or
    // below zero to "no automatic deletion" where this is read. One rule about
    // what a non-positive number means, in the place that acts on it, rather
    // than a second rule here that can come to disagree with it.
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/retentiondays',
        get_string('retentiondays', 'mod_presenterai'),
        get_string('retentiondays_desc', 'mod_presenterai'),
        0,
        PARAM_INT
    ));

    // An activity's own retentiondays of -1 means "use the site value"
    // (db/install.xml, presenterai.retentiondays DEFAULT -1), so anything else
    // is an override that changes to the site value will not reach. The count
    // is one aggregate on a small table, run only when this page is opened.
    $overridecount = $DB->count_records_select('presenterai', 'retentiondays <> -1');
    if ($overridecount > 0) {
        $settings->add(new admin_setting_description(
            'mod_presenterai/retentionoverridenote',
            '',
            get_string('retentionoverridenote', 'mod_presenterai', $overridecount)
        ));
    }

    $settings->add(new admin_setting_configtext(
        'mod_presenterai/deletewarndays',
        get_string('deletewarndays', 'mod_presenterai'),
        get_string('deletewarndays_desc', 'mod_presenterai'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_presenterai/allowlearnerdownload',
        get_string('allowlearnerdownload', 'mod_presenterai'),
        get_string('allowlearnerdownload_desc', 'mod_presenterai'),
        1
    ));

    // Section 8.7b of docs/DESIGN-visual-feedback-and-retention.md: deleting a
    // learner's presentation on a schedule while never letting them keep a copy
    // is a legitimate policy for some institutions and a nasty accident for the
    // rest, so it warns rather than blocking the save. get_config reads what is
    // stored, so this appears on the page load after the change, not as the
    // admin types. get_config returns false before this page has ever been
    // saved, and the shipped default allows download, so only an explicitly
    // stored zero is the state worth warning about.
    $downloadsetting = get_config('mod_presenterai', 'allowlearnerdownload');
    $downloadblocked = ($downloadsetting !== false && (int) $downloadsetting === 0);
    if ((int) get_config('mod_presenterai', 'retentiondays') > 0 && $downloadblocked) {
        $settings->add(new admin_setting_description(
            'mod_presenterai/retentionwithoutdownloadnote',
            '',
            get_string('retentionwithoutdownloadnote', 'mod_presenterai')
        ));
    }
}
