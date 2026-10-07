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
 * A speech to text back end.
 *
 * Always the plugin's own client, never Moodle core AI, which has no action
 * that accepts audio (plan section 5.1, DECISIONS.md D6).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface stt_interface {
    /**
     * Transcribe one audio or video file.
     *
     * @param string $filepath A readable local file.
     * @param string $mime Its MIME type.
     * @return array ['text' => string, 'model' => string]; text is never empty.
     * @throws ai_exception On any failure, including an empty transcript.
     */
    public function transcribe(string $filepath, string $mime): array;

    /**
     * The model this client asks for.
     *
     * @return string
     */
    public function model(): string;

    /**
     * The route name recorded in presenterai_aiusage.route.
     *
     * @return string openai or compatible.
     */
    public function route(): string;
}
