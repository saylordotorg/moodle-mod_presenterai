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
 * Read a JSON object out of a model reply.
 *
 * The clients with native structured output return clean JSON, but core AI
 * and a Claude reply that answered in prose do not: models wrap JSON in a
 * markdown fence or put a sentence before it. This takes the reply as it
 * comes and returns the object, or null. It never throws, so a caller has
 * one check to make.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class json_parser {
    /**
     * Decode a JSON object from raw model output.
     *
     * Tries the whole text, then the contents of a ```json fence, then the
     * span from the first { to the last }. A reply that is a well formed
     * top level array is not an object and returns null, even when the
     * array holds objects.
     *
     * @param string $raw The model's reply.
     * @param string[] $requiredkeys Keys that must be present at the top level.
     * @return array|null The object as an associative array, or null.
     */
    public static function decode_object(string $raw, array $requiredkeys = []): ?array {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $candidates = [$raw];
        // A markdown code fence, three backticks, spelled with chr() because
        // the coding standard warns on a literal backtick in a string.
        $fence = str_repeat(chr(96), 3);
        if (preg_match('/' . $fence . '(?:json)?\s*(.*?)' . $fence . '/is', $raw, $m)) {
            $candidates[] = trim($m[1]);
        }
        $first = strpos($raw, '{');
        $last = strrpos($raw, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $candidates[] = substr($raw, $first, $last - $first + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate);
            if (is_array($decoded)) {
                // A well formed top level array is an answer of the wrong
                // shape, not an object with noise around it.
                return null;
            }
            if (!($decoded instanceof \stdClass)) {
                continue;
            }
            $object = json_decode($candidate, true);
            foreach ($requiredkeys as $key) {
                if (!array_key_exists($key, $object)) {
                    return null;
                }
            }
            return $object;
        }
        return null;
    }
}
