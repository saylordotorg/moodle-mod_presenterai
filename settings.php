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

    // The ceiling for one recording on either backend. fs_store enforces it
    // per chunk and local\recording_manager at start and at finalize, both
    // lowered further by $CFG->maxbytes and the course maxbytes.
    $mediasizes = [];
    foreach ([52428800, 104857600, 157286400, 262144000, 524288000] as $bytes) {
        $mediasizes[$bytes] = display_size($bytes);
    }
    $settings->add(new admin_setting_configselect(
        'mod_presenterai/maxmediabytes',
        get_string('maxmediabytes', 'mod_presenterai'),
        get_string('maxmediabytes_desc', 'mod_presenterai'),
        157286400,
        $mediasizes
    ));

    // Written by the chunk ladder on probe.php (D19). fs_store floors it to a
    // 64 KiB multiple and caps it at 5 MiB, so a hand-typed value cannot break
    // uploads, only slow them.
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/fschunkbytes',
        get_string('fschunkbytes', 'mod_presenterai'),
        get_string('fschunkbytes_desc', 'mod_presenterai'),
        0,
        PARAM_INT
    ));
    $settings->hide_if('mod_presenterai/fschunkbytes', 'mod_presenterai/backend', 'neq', store_factory::BACKEND_FS);

    $settings->add(new admin_setting_description(
        'mod_presenterai/probelink',
        '',
        html_writer::link(new moodle_url('/mod/presenterai/probe.php'), get_string('probelink', 'mod_presenterai'))
    ));

    // Recording.

    $settings->add(new admin_setting_heading(
        'mod_presenterai/recordingheading',
        get_string('recordingheading', 'mod_presenterai'),
        get_string('recordingheading_desc', 'mod_presenterai')
    ));

    // Built from the preset list so a new preset is added in one place.
    $qualitychoices = [];
    foreach (array_keys(\mod_presenterai\local\config::QUALITY_PRESETS) as $qualitykey) {
        $qualitychoices[$qualitykey] = get_string('quality_' . $qualitykey, 'mod_presenterai');
    }
    $settings->add(new admin_setting_configselect(
        'mod_presenterai/quality',
        get_string('quality', 'mod_presenterai'),
        get_string('quality_desc', 'mod_presenterai'),
        \mod_presenterai\local\config::DEFAULT_QUALITY,
        $qualitychoices
    ));

    // Zero or negative is read as the default by config::max_recording_seconds().
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/maxrecordingseconds',
        get_string('maxrecordingseconds', 'mod_presenterai'),
        get_string('maxrecordingseconds_desc', 'mod_presenterai'),
        \mod_presenterai\local\config::DEFAULT_MAX_RECORDING_SECONDS,
        PARAM_INT
    ));

    // AI services. DECISIONS.md D6 and plan section 5: the route chooses the
    // back end for scoring. Transcription always uses the plugin's own keys
    // below. Body language, slide design and the judge use them too, except
    // when scoring runs on core AI, where they're off (D26). The route's
    // description says both.

    $settings->add(new admin_setting_heading(
        'mod_presenterai/aiheading',
        get_string('aiheading', 'mod_presenterai'),
        get_string('aiheading_desc', 'mod_presenterai')
    ));

    $routechoices = [];
    foreach (\mod_presenterai\local\ai\route_resolver::ROUTES as $routename) {
        $routechoices[$routename] = get_string('airoute_' . $routename, 'mod_presenterai');
    }
    $settings->add(new admin_setting_configselect(
        'mod_presenterai/airoute',
        get_string('airoute', 'mod_presenterai'),
        get_string('airoute_desc', 'mod_presenterai'),
        'auto',
        $routechoices
    ));

    // What the current settings resolve to, worked out when the page opens.
    // It reads stored values, so it reflects a change after the save.
    $readiness = \mod_presenterai\local\ai\route_resolver::readiness();
    $settings->add(new admin_setting_description(
        'mod_presenterai/aireadiness',
        get_string('aireadiness', 'mod_presenterai'),
        html_writer::alist(array_map('s', $readiness['messages']))
    ));

    // Keys are admin_setting_encryptedpassword: stored encrypted with the
    // site key, read only through local\ai\secrets::get(), never shown back.
    // Model fields are PARAM_RAW_TRIMMED so a pasted trailing newline is
    // refused on save rather than sent as part of the model id.
    $settings->add(new admin_setting_encryptedpassword(
        'mod_presenterai/claudeapikey',
        get_string('claudeapikey', 'mod_presenterai'),
        get_string('claudeapikey_desc', 'mod_presenterai')
    ));
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/claudemodel',
        get_string('claudemodel', 'mod_presenterai'),
        get_string('claudemodel_desc', 'mod_presenterai'),
        'claude-sonnet-5-5',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/claudejudgemodel',
        get_string('claudejudgemodel', 'mod_presenterai'),
        get_string('claudejudgemodel_desc', 'mod_presenterai'),
        'claude-haiku-4-5',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_encryptedpassword(
        'mod_presenterai/openaiapikey',
        get_string('openaiapikey', 'mod_presenterai'),
        get_string('openaiapikey_desc', 'mod_presenterai')
    ));
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/openaimodel',
        get_string('openaimodel', 'mod_presenterai'),
        get_string('openaimodel_desc', 'mod_presenterai'),
        'gpt-4o-mini',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/openaijudgemodel',
        get_string('openaijudgemodel', 'mod_presenterai'),
        get_string('openaijudgemodel_desc', 'mod_presenterai'),
        'gpt-4o-mini',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_encryptedpassword(
        'mod_presenterai/geminiapikey',
        get_string('geminiapikey', 'mod_presenterai'),
        get_string('geminiapikey_desc', 'mod_presenterai')
    ));
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/geminimodel',
        get_string('geminimodel', 'mod_presenterai'),
        get_string('geminimodel_desc', 'mod_presenterai'),
        'gemini-2.5-flash',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/geminijudgemodel',
        get_string('geminijudgemodel', 'mod_presenterai'),
        get_string('geminijudgemodel_desc', 'mod_presenterai'),
        'gemini-2.5-flash',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new \mod_presenterai\admin\setting_endpoint(
        'mod_presenterai/compatibleendpoint',
        get_string('compatibleendpoint', 'mod_presenterai'),
        get_string('compatibleendpoint_desc', 'mod_presenterai')
    ));
    $settings->add(new admin_setting_encryptedpassword(
        'mod_presenterai/compatibleapikey',
        get_string('compatibleapikey', 'mod_presenterai'),
        get_string('compatibleapikey_desc', 'mod_presenterai')
    ));
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/compatiblemodel',
        get_string('compatiblemodel', 'mod_presenterai'),
        get_string('compatiblemodel_desc', 'mod_presenterai'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/compatiblejudgemodel',
        get_string('compatiblejudgemodel', 'mod_presenterai'),
        get_string('compatiblejudgemodel_desc', 'mod_presenterai'),
        '',
        PARAM_RAW_TRIMMED
    ));

    // Each vendor's fields are hidden when the route names a different one.
    // Under auto every keyed vendor can still be used. Under core (D26) only
    // the OpenAI key is, for transcription, so the rest are hidden there.
    $vendorfields = [
        'claude' => ['claudeapikey', 'claudemodel', 'claudejudgemodel'],
        'openai' => ['openaiapikey', 'openaimodel', 'openaijudgemodel'],
        'gemini' => ['geminiapikey', 'geminimodel', 'geminijudgemodel'],
        'compatible' => ['compatibleendpoint', 'compatibleapikey', 'compatiblemodel', 'compatiblejudgemodel'],
    ];
    foreach ($vendorfields as $vendor => $fields) {
        $othervendors = array_diff(array_keys($vendorfields), [$vendor]);
        foreach ($fields as $field) {
            $hideunder = $othervendors;
            if ($field !== 'openaiapikey') {
                $hideunder[] = 'core';
            }
            $settings->hide_if('mod_presenterai/' . $field, 'mod_presenterai/airoute', 'in', implode('|', $hideunder));
        }
    }

    // Transcription. Never core AI: core has no audio action (plan 5.1).
    $settings->add(new admin_setting_heading(
        'mod_presenterai/sttheading',
        get_string('sttheading', 'mod_presenterai'),
        get_string('sttheading_desc', 'mod_presenterai')
    ));
    $settings->add(new \mod_presenterai\admin\setting_endpoint(
        'mod_presenterai/sttendpoint',
        get_string('sttendpoint', 'mod_presenterai'),
        get_string('sttendpoint_desc', 'mod_presenterai')
    ));
    $settings->add(new admin_setting_encryptedpassword(
        'mod_presenterai/sttapikey',
        get_string('sttapikey', 'mod_presenterai'),
        get_string('sttapikey_desc', 'mod_presenterai')
    ));
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/sttmodel',
        get_string('sttmodel', 'mod_presenterai'),
        get_string('sttmodel_desc', 'mod_presenterai'),
        'whisper-1',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configcheckbox(
        'mod_presenterai/sttwarm',
        get_string('sttwarm', 'mod_presenterai'),
        get_string('sttwarm_desc', 'mod_presenterai'),
        0
    ));
    $settings->hide_if('mod_presenterai/sttwarm', 'mod_presenterai/sttendpoint', 'eq', '');

    // Optional. With ffmpeg the server can take the audio out of a video that
    // has no separate audio track, and cut a recording that's still over the
    // transcription service's limit into segments (transcription_source).
    // Empty by default; the setting checks the path is an executable file.
    $settings->add(new admin_setting_configexecutable(
        'mod_presenterai/ffmpegpath',
        get_string('ffmpegpath', 'mod_presenterai'),
        get_string('ffmpegpath_desc', 'mod_presenterai'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'mod_presenterai/trustedhosts',
        get_string('trustedhosts', 'mod_presenterai'),
        get_string('trustedhosts_desc', 'mod_presenterai'),
        '',
        PARAM_RAW_TRIMMED
    ));

    // Body language feedback (D17, D21, D5). Per activity it is opt in; these
    // are the site's rules for what happens to the evidence and the words.

    $settings->add(new admin_setting_heading(
        'mod_presenterai/visualheading',
        get_string('visualheading', 'mod_presenterai'),
        get_string('visualheading_desc', 'mod_presenterai')
    ));

    // No forever and a minimum of 1 (design 7.4). Zero or less is read as 1
    // where it is acted on, by visual_pipeline::visual_data_days().
    $settings->add(new admin_setting_configtext(
        'mod_presenterai/visualdatadays',
        get_string('visualdatadays', 'mod_presenterai'),
        get_string('visualdatadays_desc', 'mod_presenterai'),
        \mod_presenterai\local\vision\visual_pipeline::DEFAULT_VISUAL_DATA_DAYS,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_presenterai/storevisualevidence',
        get_string('storevisualevidence', 'mod_presenterai'),
        get_string('storevisualevidence_desc', 'mod_presenterai'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_presenterai/visualsummaryjudge',
        get_string('visualsummaryjudge', 'mod_presenterai'),
        get_string('visualsummaryjudge_desc', 'mod_presenterai'),
        1
    ));

    // Named on the settings page so nobody discovers the log by accident (design 5.3).
    $settings->add(new admin_setting_description(
        'mod_presenterai/gateloglink',
        '',
        html_writer::link(new moodle_url('/mod/presenterai/gatelog.php'), get_string('gatelog_link', 'mod_presenterai'))
    ));

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
