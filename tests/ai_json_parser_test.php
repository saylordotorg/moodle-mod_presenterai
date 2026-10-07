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

namespace mod_presenterai;

use mod_presenterai\local\ai\json_parser;

/**
 * Reading a JSON object out of whatever a model sent back.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\json_parser
 */
final class ai_json_parser_test extends \advanced_testcase {
    /**
     * Clean, fenced and prose wrapped objects all decode.
     *
     * @return void
     */
    public function test_objects_decode(): void {
        $this->assertSame(['a' => 1], json_parser::decode_object('{"a":1}'));
        $fence = str_repeat(chr(96), 3);
        $this->assertSame(['a' => 1], json_parser::decode_object($fence . "json\n{\"a\":1}\n" . $fence));
        $this->assertSame(['a' => 1], json_parser::decode_object($fence . "\n{\"a\":1}\n" . $fence));
        $this->assertSame(['a' => 2], json_parser::decode_object("Sure:\n" . $fence . "json\n{\"a\":2}\n" . $fence . "\nDone."));
        $this->assertSame(
            ['a' => ['b' => 'c']],
            json_parser::decode_object('Here is the result: {"a":{"b":"c"}} Hope that helps.')
        );
        $this->assertSame([], json_parser::decode_object('{}'));
    }

    /**
     * Arrays, malformed text, empty text and scalars are null.
     *
     * @return void
     */
    public function test_failures_are_null(): void {
        $this->assertNull(json_parser::decode_object('[1,2,3]'));
        $this->assertNull(json_parser::decode_object('[{"a":1}]'));
        $this->assertNull(json_parser::decode_object('{"a":1,'));
        $this->assertNull(json_parser::decode_object('no json here'));
        $this->assertNull(json_parser::decode_object(''));
        $this->assertNull(json_parser::decode_object('42'));
        $this->assertNull(json_parser::decode_object('"just a string"'));
    }

    /**
     * Missing required keys make the result null.
     *
     * @return void
     */
    public function test_required_keys(): void {
        $this->assertSame(['a' => 1, 'b' => null], json_parser::decode_object('{"a":1,"b":null}', ['a', 'b']));
        $this->assertNull(json_parser::decode_object('{"a":1}', ['a', 'b']));
    }
}
