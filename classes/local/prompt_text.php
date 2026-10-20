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
 * Text the plugin hands to a model, and the default rubric, always read in English.
 *
 * These strings live in lang/en like every other string, but a learner never reads a prompt, and a rubric
 * criterion's name is copied verbatim into score JSON, where a translated name would make one score read
 * differently depending on who looked at it (design 10). So they are read in English whatever language the
 * site or the user has chosen, and they are not for translation: a language pack that carries them is
 * ignored here.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_text {
    /**
     * One English string.
     *
     * @param string $id A string identifier in lang/en/presenterai.php (prompt:..., rubric:... or error:...).
     * @param string|\stdClass|array|null $a The placeholder value, as for get_string().
     * @return string
     */
    public static function get(string $id, $a = null): string {
        return get_string_manager()->get_string($id, 'mod_presenterai', $a, 'en');
    }
}
