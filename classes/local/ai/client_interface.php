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
 * One text generating AI back end: Moodle core AI, Claude, OpenAI, Gemini or
 * an OpenAI compatible endpoint (plan section 5.2).
 *
 * Options understood by generate_text():
 * - 'schema' => ['name' => string, 'schema' => array]: ask for JSON matching
 *   the schema; the return value is then a JSON string. Every property must
 *   be required, every object must carry additionalProperties false, and the
 *   schema must not use minItems, maxItems, minimum, maximum, minLength,
 *   maxLength or pattern, because OpenAI's strict mode refuses some of them
 *   and Claude is sent the schema as plain guidance.
 * - 'images' => list of ['mime' => string, 'base64' => string].
 * - 'max_tokens' => int, default 4096.
 * - 'timeout' => int seconds, default 120.
 * - 'temperature' => float, sent only where the model accepts it.
 * - 'contextid' and 'userid' => int, required by the core AI route.
 *
 * Every failure is an ai_exception carrying a reason and a transient flag.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface client_interface {
    /**
     * Generate text from a system prompt and one user message.
     *
     * @param string $system The system prompt.
     * @param string $user The user message.
     * @param array $opts See the interface docblock.
     * @return string The text, or a JSON string when a schema was given.
     * @throws ai_exception On any failure.
     */
    public function generate_text(string $system, string $user, array $opts = []): string;

    /**
     * Whether this client can send images.
     *
     * @return bool
     */
    public function supports_images(): bool;

    /**
     * Whether this client enforces a JSON schema natively.
     *
     * @return bool
     */
    public function supports_json_schema(): bool;

    /**
     * The route name recorded in presenterai_aiusage.route.
     *
     * @return string One of core, claude, openai, gemini, compatible.
     */
    public function route(): string;

    /**
     * The model this client asks for.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Token usage of the last generate_text() call.
     *
     * Reset at the start of every call, summed across any internal retry, and
     * set even when the call then throws, because a refused or malformed reply
     * was still sent and is still billed.
     *
     * @return array ['prompttokens' => int, 'completiontokens' => int,
     *     'model' => string, 'provider' => string]
     */
    public function last_usage(): array;
}
