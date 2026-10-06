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

namespace mod_presenterai\local;

/**
 * Topics a learner can choose between, and the PDF brief attached to each.
 *
 * A topic's file lives in its own file area with itemid = topic id (plan
 * section 3.2), which is what lets backup, restore and course copy carry it
 * with the topic rather than leaving it behind. This is a new feature, not a
 * port: Soapbox had a pdf_itemid column and nothing that ever wrote a file to
 * it.
 *
 * Topic instructions are plain editor text with no embedded files (editor
 * maxfiles 0). A brief that needs a document attaches it as the topic file,
 * which keeps one file per topic and one place to look for it.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class topic_manager {
    /** @var string The file area holding each topic's attached PDF, itemid = topic id. */
    public const FILEAREA = 'topicfile';

    /**
     * Options for the topic file manager.
     *
     * The size limit is the course's, which get_max_upload_file_size() then
     * bounds by the site's, so a topic brief obeys the same ceiling as any
     * other file a teacher adds to the course.
     *
     * @param \stdClass $course The course, carrying maxbytes.
     * @return array Options for the filemanager element and file_save_draft_area_files().
     */
    public static function file_options(\stdClass $course): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['.pdf'],
            'maxbytes' => (int) ($course->maxbytes ?? 0),
        ];
    }

    /**
     * Options for the topic instructions editor.
     *
     * @param \context $context The context the editor works in.
     * @return array
     */
    public static function editor_options(\context $context): array {
        return [
            'maxfiles' => 0,
            'noclean' => false,
            'context' => $context,
        ];
    }

    /**
     * The topics of an activity, in the order a teacher arranged them.
     *
     * @param int $presenteraiid The instance id.
     * @return \stdClass[] Topic rows keyed by id, ordered by sortorder then id.
     */
    public static function get_topics(int $presenteraiid): array {
        global $DB;
        return $DB->get_records('presenterai_topic', ['presenteraiid' => $presenteraiid], 'sortorder ASC, id ASC');
    }

    /**
     * Write the topics posted by the activity form.
     *
     * Reads the repeat arrays topicid[], topictitle[], topicinstructions[] and
     * topicfile[]. A row with a title is inserted or updated; a row whose title
     * was cleared deletes that topic. A row with no title and no id is an
     * unused repeat and is skipped.
     *
     * A posted topicid is only a claim. One that does not belong to this
     * instance is skipped entirely, never updated and never deleted, so a
     * tampered form cannot reach another activity's topics.
     *
     * @param \stdClass $data Form data.
     * @param int $presenteraiid The instance being saved.
     * @param \context_module $context The instance's module context.
     * @return void
     */
    public static function save_from_form(\stdClass $data, int $presenteraiid, \context_module $context): void {
        global $DB;

        if (!isset($data->topictitle) || !is_array($data->topictitle)) {
            return;
        }

        $owned = self::get_topics($presenteraiid);
        $course = get_course($context->get_course_context()->instanceid);
        $fileoptions = self::file_options($course);
        $now = time();

        foreach ($data->topictitle as $index => $rawtitle) {
            $topicid = (int) ($data->topicid[$index] ?? 0);
            if ($topicid > 0 && !isset($owned[$topicid])) {
                continue;
            }

            $title = \core_text::substr(trim(clean_param((string) $rawtitle, PARAM_TEXT)), 0, 255);

            if ($title === '') {
                if ($topicid > 0) {
                    self::delete_topic($owned[$topicid], $context);
                }
                continue;
            }

            [$instructions, $format] = self::editor_value($data->topicinstructions[$index] ?? null);

            $record = (object) [
                'title' => $title,
                'instructions' => $instructions,
                'instructionsformat' => $format,
                'sortorder' => (int) $index,
                'timemodified' => $now,
            ];
            if ($topicid > 0) {
                $record->id = $topicid;
                $DB->update_record('presenterai_topic', $record);
            } else {
                $record->presenteraiid = $presenteraiid;
                $record->timecreated = $now;
                $topicid = (int) $DB->insert_record('presenterai_topic', $record);
            }

            $draftitemid = (int) ($data->topicfile[$index] ?? 0);
            if ($draftitemid > 0) {
                file_save_draft_area_files(
                    $draftitemid,
                    $context->id,
                    'mod_presenterai',
                    self::FILEAREA,
                    $topicid,
                    $fileoptions
                );
            }
        }
    }

    /**
     * Delete every topic of an activity.
     *
     * Rows only. The files go when core deletes the module context, which
     * course_delete_module() does after presenterai_delete_instance() returns.
     *
     * @param int $presenteraiid The instance id.
     * @return void
     */
    public static function delete_all(int $presenteraiid): void {
        global $DB;
        $DB->delete_records('presenterai_topic', ['presenteraiid' => $presenteraiid]);
    }

    /**
     * The topics of an activity, ready for the view page's topic picker.
     *
     * The key names are a contract with the front end's topics template.
     *
     * @param \stdClass $instance The instance row.
     * @param \context_module $context Its module context.
     * @return array[] List of topics, each with id, title, instructions, hasinstructions, files and hasfiles.
     */
    public static function export_for_view(\stdClass $instance, \context_module $context): array {
        $fs = get_file_storage();
        $out = [];
        foreach (self::get_topics((int) $instance->id) as $topic) {
            $files = [];
            $areafiles = $fs->get_area_files(
                $context->id,
                'mod_presenterai',
                self::FILEAREA,
                $topic->id,
                'filename',
                false
            );
            foreach ($areafiles as $file) {
                $files[] = [
                    'filename' => $file->get_filename(),
                    'url' => \moodle_url::make_pluginfile_url(
                        $context->id,
                        'mod_presenterai',
                        self::FILEAREA,
                        $topic->id,
                        $file->get_filepath(),
                        $file->get_filename(),
                        false
                    )->out(false),
                ];
            }

            $instructions = trim((string) $topic->instructions) === ''
                ? ''
                : format_text($topic->instructions, $topic->instructionsformat, ['context' => $context]);

            $out[] = [
                'id' => (int) $topic->id,
                'title' => format_string($topic->title, true, ['context' => $context]),
                'instructions' => $instructions,
                'hasinstructions' => $instructions !== '',
                'files' => $files,
                'hasfiles' => !empty($files),
            ];
        }
        return $out;
    }

    /**
     * Whether a topic id names a topic of the activity this context belongs to.
     *
     * The guard serve_file() applies before sending anything. Factored out so
     * it can be tested: send_stored_file() ends the request.
     *
     * @param int $topicid The itemid from the URL.
     * @param \context_module $context The module context the URL addressed.
     * @return bool
     */
    public static function topic_belongs_to_context(int $topicid, \context_module $context): bool {
        global $DB;
        if ($topicid <= 0) {
            return false;
        }
        $cm = get_coursemodule_from_id('presenterai', $context->instanceid);
        if (!$cm) {
            return false;
        }
        return $DB->record_exists('presenterai_topic', ['id' => $topicid, 'presenteraiid' => $cm->instance]);
    }

    /**
     * Send a topic file.
     *
     * Called by mod_presenterai_pluginfile() after require_login() and the view
     * capability check. The itemid is checked against this activity's topics,
     * because a file area is shared by every topic in the context and a URL is
     * only a claim about which one it names.
     *
     * @param \context_module $context The module context.
     * @param array $args Path segments after the file area: itemid, any path, then the filename.
     * @param bool $forcedownload Whether to send as an attachment.
     * @param array $options Options for send_stored_file().
     * @return bool False when the file is not one this activity serves; otherwise does not return.
     */
    public static function serve_file(\context_module $context, array $args, bool $forcedownload, array $options): bool {
        $topicid = (int) array_shift($args);
        $filename = array_pop($args);
        if ($filename === null || $filename === '') {
            return false;
        }
        if (!self::topic_belongs_to_context($topicid, $context)) {
            return false;
        }
        $filepath = empty($args) ? '/' : '/' . implode('/', $args) . '/';

        $file = get_file_storage()->get_file($context->id, 'mod_presenterai', self::FILEAREA, $topicid, $filepath, $filename);
        if (!$file || $file->is_directory()) {
            return false;
        }

        // Private for the same reason as the recording areas in lib.php: the
        // default for a non-zero lifetime is public, and a course brief is not
        // something a shared proxy should hold.
        send_stored_file($file, 0, 0, $forcedownload, $options + ['cacheability' => 'private']);
        return true;
    }

    /**
     * Delete one topic, its file, and the topic label on attempts that used it.
     *
     * The attempt survives with no topic rather than being deleted with it: a
     * learner's recording and score are not the teacher's to remove by editing
     * a topic list.
     *
     * @param \stdClass $topic The topic row.
     * @param \context_module $context The module context holding its file.
     * @return void
     */
    private static function delete_topic(\stdClass $topic, \context_module $context): void {
        global $DB;
        get_file_storage()->delete_area_files($context->id, 'mod_presenterai', self::FILEAREA, $topic->id);
        $DB->set_field(
            'presenterai_recording',
            'topicid',
            null,
            ['presenteraiid' => $topic->presenteraiid, 'topicid' => $topic->id]
        );
        $DB->delete_records('presenterai_topic', ['id' => $topic->id]);
    }

    /**
     * Read an editor value, which arrives as text and format from the form.
     *
     * A plain string is accepted too, for callers that are not the form, and
     * is taken as HTML.
     *
     * @param mixed $value The posted editor array, a string, or null.
     * @return array [string text, int format]
     */
    private static function editor_value($value): array {
        if (is_array($value)) {
            return [(string) ($value['text'] ?? ''), (int) ($value['format'] ?? FORMAT_HTML)];
        }
        return [(string) $value, FORMAT_HTML];
    }
}
