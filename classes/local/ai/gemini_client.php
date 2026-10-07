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
 * Google Gemini (route 'gemini'), through Google's OpenAI compatibility
 * endpoint.
 *
 * Deviation from plan section 5.2, which says Gemini is "not OpenAI
 * compatible at the wire level": this follows SOLA's shipped gemini_provider,
 * which talks to https://generativelanguage.googleapis.com/v1beta/openai
 * with the chat completions shape, image_url parts and response_format, and
 * has done so in production. Google's native generateContent shape would be
 * a fourth wire format to carry for no capability PresenterAI uses. If the
 * compatibility endpoint ever stops accepting a feature the scorer needs,
 * this class is where the native shape goes.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_client extends openai_client {
    /** @var string Google's OpenAI compatibility base. */
    public const GEMINI_BASE = 'https://generativelanguage.googleapis.com/v1beta/openai';

    /**
     * Build a client.
     *
     * @param string $apikey The Gemini API key, already decrypted.
     * @param string $model The model id.
     * @param string $baseurl The API base; tests and proxies only.
     */
    public function __construct(string $apikey, string $model, string $baseurl = self::GEMINI_BASE) {
        parent::__construct($apikey, $model, $baseurl, 'gemini');
    }
}
