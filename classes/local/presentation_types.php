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
 * The presentation types a course designer picks from, and what each one sets.
 *
 * 0.6.0 replaces the settings form's two selects, presentation type and
 * speaking level, with one. Nothing new is stored for it beyond the preset
 * column: a type maps onto the ptype, speakinglevel and preset columns the
 * rest of the plugin already reads, so the scorer, backup and restore, the
 * importer and every activity made before 0.6.0 see the columns they always
 * did.
 *
 * Two keys exist only for activities made before 0.6.0, which have no preset
 * and fall back to the general rubric: general_informative and
 * general_persuasive. The form offers them only to an activity that already
 * has them, so saving an older activity doesn't change its rubric, and a new
 * activity can't pick them.
 *
 * Each type also has defaults the form fills in when it's picked: the length
 * range, whether slides are offered, and two or three starter topics. These
 * are suggestions for a new activity, never rules: normalise() doesn't apply
 * them, so a web service or the importer that sends its own values keeps
 * them.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class presentation_types {
    /** @var string An activity made before 0.6.0, informative, on the general rubric. */
    public const GENERAL_INFORMATIVE = 'general_informative';

    /** @var string An activity made before 0.6.0, persuasive, on the general rubric. */
    public const GENERAL_PERSUASIVE = 'general_persuasive';

    /** @var string The type a new activity starts on. */
    public const DEFAULT_TYPE = rubric_manager::PRESET_INFORMATIVE;

    /** @var array[] Per type: minseconds, maxseconds, slidesenabled, and the number of starter topics. */
    private const DEFAULTS = [
        rubric_manager::PRESET_PRACTICE => ['minseconds' => 20, 'maxseconds' => 90, 'slidesenabled' => 0, 'topics' => 3],
        rubric_manager::PRESET_INFORMATIVE => ['minseconds' => 180, 'maxseconds' => 300, 'slidesenabled' => 1, 'topics' => 3],
        rubric_manager::PRESET_PERSUASIVE => ['minseconds' => 240, 'maxseconds' => 360, 'slidesenabled' => 1, 'topics' => 3],
        rubric_manager::PRESET_ENTERTAIN => ['minseconds' => 180, 'maxseconds' => 300, 'slidesenabled' => 1, 'topics' => 3],
        rubric_manager::LEVEL_ESL_BEGINNER => ['minseconds' => 60, 'maxseconds' => 180, 'slidesenabled' => 1, 'topics' => 3],
        rubric_manager::LEVEL_ESL_INTERMEDIATE => ['minseconds' => 60, 'maxseconds' => 180, 'slidesenabled' => 1, 'topics' => 3],
        rubric_manager::LEVEL_ESL_ADVANCED => ['minseconds' => 60, 'maxseconds' => 180, 'slidesenabled' => 1, 'topics' => 3],
    ];

    /**
     * The types a new activity can pick, in the order the form lists them.
     *
     * @return string[]
     */
    public static function types(): array {
        return rubric_manager::PRESETS;
    }

    /**
     * The options for the form's select, labels in the user's language.
     *
     * @param string $current The activity's current type, from from_instance(); a general_* type is offered only
     *     when it's the current one.
     * @return string[] type => label
     */
    public static function options(string $current = ''): array {
        $options = [];
        foreach (self::types() as $type) {
            $options[$type] = get_string('presentationtype_' . $type, 'mod_presenterai');
        }
        if (in_array($current, [self::GENERAL_INFORMATIVE, self::GENERAL_PERSUASIVE], true)) {
            $options[$current] = get_string('presentationtype_' . $current, 'mod_presenterai');
        }
        return $options;
    }

    /**
     * The type an existing activity shows on the form.
     *
     * Its preset when it has one. Otherwise its speaking level when that's an
     * English as a second language level, because the ESL preset is the
     * rubric it already falls back to. Otherwise one of the general_* types,
     * so saving the form leaves it on the general rubric.
     *
     * @param \stdClass $instance The presenterai row, or the form's default values as an object.
     * @return string A key of options().
     */
    public static function from_instance(\stdClass $instance): string {
        $preset = rubric_manager::clean_preset(isset($instance->preset) ? (string) $instance->preset : '');
        if ($preset !== '' && $preset !== rubric_manager::LEVEL_GENERAL) {
            return $preset;
        }
        $level = rubric_manager::clean_level(isset($instance->speakinglevel) ? (string) $instance->speakinglevel : '');
        if ($level !== rubric_manager::LEVEL_GENERAL) {
            return $level;
        }
        return ((string) ($instance->ptype ?? '')) === 'persuasive' ? self::GENERAL_PERSUASIVE : self::GENERAL_INFORMATIVE;
    }

    /**
     * The columns a type is stored as.
     *
     * An ESL type keeps the activity's informative or persuasive ptype when it
     * has one (it steers the prompt's hint), and is informative otherwise.
     *
     * @param string $type A key of options(); anything else reads as the default type.
     * @param string|null $ptype The activity's current ptype, null for a new activity.
     * @return array ['ptype' => string, 'speakinglevel' => string|null, 'preset' => string|null]
     */
    public static function columns(string $type, ?string $ptype = null): array {
        switch ($type) {
            case self::GENERAL_INFORMATIVE:
                return ['ptype' => 'informative', 'speakinglevel' => null, 'preset' => null];
            case self::GENERAL_PERSUASIVE:
                return ['ptype' => 'persuasive', 'speakinglevel' => null, 'preset' => null];
            case rubric_manager::LEVEL_ESL_BEGINNER:
            case rubric_manager::LEVEL_ESL_INTERMEDIATE:
            case rubric_manager::LEVEL_ESL_ADVANCED:
                return [
                    'ptype' => in_array($ptype, ['informative', 'persuasive'], true) ? $ptype : 'informative',
                    'speakinglevel' => $type,
                    'preset' => $type,
                ];
            case rubric_manager::PRESET_PRACTICE:
            case rubric_manager::PRESET_INFORMATIVE:
            case rubric_manager::PRESET_PERSUASIVE:
            case rubric_manager::PRESET_ENTERTAIN:
                return ['ptype' => $type, 'speakinglevel' => null, 'preset' => $type];
            default:
                return self::columns(self::DEFAULT_TYPE);
        }
    }

    /**
     * The settings a type suggests for a new activity.
     *
     * Every type records video. Practice talks are 0:20 to 1:30 with no slides;
     * informative and entertaining talks 3 to 5 minutes, persuasive 4 to 6 and
     * ESL 1 to 3, all with slides offered (a learner may still record without
     * a deck).
     *
     * @param string $type A key of options(); anything else reads as the default type.
     * @return array ['mode' => string, 'minseconds' => int, 'maxseconds' => int, 'slidesenabled' => int, 'topics' => string[]]
     */
    public static function defaults(string $type): array {
        if (in_array($type, [self::GENERAL_INFORMATIVE, self::GENERAL_PERSUASIVE], true)) {
            $type = $type === self::GENERAL_PERSUASIVE ? rubric_manager::PRESET_PERSUASIVE : rubric_manager::PRESET_INFORMATIVE;
        }
        if (!isset(self::DEFAULTS[$type])) {
            $type = self::DEFAULT_TYPE;
        }
        $defaults = self::DEFAULTS[$type];
        $topics = [];
        for ($n = 1; $n <= $defaults['topics']; $n++) {
            $topics[] = get_string('startertopic_' . $type . '_' . $n, 'mod_presenterai');
        }
        return [
            'mode' => 'video',
            'minseconds' => $defaults['minseconds'],
            'maxseconds' => $defaults['maxseconds'],
            'slidesenabled' => $defaults['slidesenabled'],
            'topics' => $topics,
        ];
    }

    /**
     * Every type's defaults, for the settings form's script.
     *
     * @return array type => defaults()
     */
    public static function all_defaults(): array {
        $out = [];
        foreach (self::types() as $type) {
            $out[$type] = self::defaults($type);
        }
        return $out;
    }
}
