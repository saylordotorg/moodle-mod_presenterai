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

use mod_presenterai\local\ai\ai_exception;
use mod_presenterai\local\ai\client_interface;
use mod_presenterai\local\vision\video_vision;
use mod_presenterai\local\vision\visual_prompts;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/luminance_test.php');

/**
 * The vision pass: one sheet in, D18 JSON out, with the luminance check in front.
 *
 * No real AI service is called. The client is a double that records what it
 * was sent and replies with what the test gives it.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\vision\video_vision
 */
final class video_vision_test extends \advanced_testcase {
    /** @var \stdClass The activity. */
    private \stdClass $instance;

    /** @var \context_module Its context. */
    private \context_module $context;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /**
     * One video activity with video vision on and one learner.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->learner = $generator->create_and_enrol($course, 'student');
        $this->instance = $generator->create_module('presenterai', ['course' => $course->id, 'videovision' => 1]);
        $this->context = \context_module::instance($this->instance->cmid);
    }

    /**
     * A finished recording whose frame sheet holds these bytes.
     *
     * @param string|null $bytes The sheet, or null to name a key with no file behind it.
     * @return \stdClass The recording row.
     */
    private function recording_with_sheet(?string $bytes): \stdClass {
        $key = random_string(16) . '.jpg';
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'storagekey' => random_string(16) . '.webm',
            'frameskey' => $key,
        ]);
        if ($bytes !== null) {
            get_file_storage()->create_file_from_string([
                'contextid' => $this->context->id,
                'component' => 'mod_presenterai',
                'filearea' => 'frames',
                'itemid' => $rec->id,
                'filepath' => '/',
                'filename' => $key,
            ], $bytes);
        }

        return $rec;
    }

    /**
     * A client double that replies from a queue and remembers every call.
     *
     * @param array $replies Strings to return, or Throwables to throw, in order.
     * @return client_interface
     */
    public static function fake_client(array $replies): client_interface {
        return new class ($replies) implements client_interface {
            /** @var array Every call as ['system', 'user', 'opts']. */
            public array $calls = [];

            /** @var array What is left to reply. */
            private array $replies;

            /** @var array The last call's usage. */
            private array $usage = [];

            /**
             * Build the double.
             *
             * @param array $replies The replies.
             */
            public function __construct(array $replies) {
                $this->replies = $replies;
            }

            /**
             * Record the call and reply.
             *
             * @param string $system System prompt.
             * @param string $user User message.
             * @param array $opts Options.
             * @return string
             */
            public function generate_text(string $system, string $user, array $opts = []): string {
                $this->calls[] = ['system' => $system, 'user' => $user, 'opts' => $opts];
                $this->usage = ['prompttokens' => 120, 'completiontokens' => 30, 'model' => 'fake-vision', 'provider' => 'fake'];
                $reply = array_shift($this->replies);
                if ($reply instanceof \Throwable) {
                    throw $reply;
                }

                return (string) $reply;
            }

            /**
             * Images are supported.
             *
             * @return bool
             */
            public function supports_images(): bool {
                return true;
            }

            /**
             * Schemas are supported.
             *
             * @return bool
             */
            public function supports_json_schema(): bool {
                return true;
            }

            /**
             * The route.
             *
             * @return string
             */
            public function route(): string {
                return 'claude';
            }

            /**
             * The model.
             *
             * @return string
             */
            public function model(): string {
                return 'fake-vision';
            }

            /**
             * The last call's usage.
             *
             * @return array
             */
            public function last_usage(): array {
                return $this->usage;
            }
        };
    }

    /**
     * The request carries prompt 1 verbatim, the sheet as an image and a schema within the client rules.
     *
     * @return void
     */
    public function test_request_shape_and_parsed_reply(): void {
        if (!function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is not available.');
        }
        $sheet = luminance_test::sheet([]);
        $rec = $this->recording_with_sheet($sheet);
        $longnote = str_repeat('Hands stayed low in four of six frames. ', 40);
        $client = self::fake_client([json_encode(['note' => $longnote, 'unusable_frames' => 1, 'confidence' => ' High '])]);

        $result = video_vision::observe($rec, $this->instance, $this->context, $client, $called);

        $this->assertTrue($called);
        $this->assertCount(1, $client->calls);
        $call = $client->calls[0];
        $this->assertSame(visual_prompts::vision(), $call['system']);
        $this->assertSame([['mime' => 'image/jpeg', 'base64' => base64_encode($sheet)]], $call['opts']['images']);
        $schema = $call['opts']['schema'];
        $this->assertSame('visual_evidence', $schema['name']);
        $this->assertFalse($schema['schema']['additionalProperties']);
        $this->assertSame(['note', 'unusable_frames', 'confidence'], $schema['schema']['required']);
        $encoded = json_encode($schema);
        foreach (['enum', 'minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern'] as $keyword) {
            $this->assertStringNotContainsString('"' . $keyword . '"', $encoded, "The schema uses {$keyword}.");
        }

        $this->assertSame(900, \core_text::strlen($result['note']), 'The note was not clamped to 900 characters.');
        $this->assertSame('high', $result['confidence']);
        $this->assertSame(1, $result['unusable_frames']);
        $this->assertSame(0, $result['dark']);
    }

    /**
     * A confidence the gate doesn't know is low.
     *
     * @return void
     */
    public function test_unknown_confidence_is_low(): void {
        $rec = $this->recording_with_sheet(self::bright_sheet());
        $client = self::fake_client([json_encode(['note' => 'Hands visible.', 'unusable_frames' => 0, 'confidence' => 'certain'])]);

        $result = video_vision::observe($rec, $this->instance, $this->context, $client);
        $this->assertSame('low', $result['confidence']);
    }

    /**
     * A JPEG GD won't decode skips the luminance check with a debugging note and still goes to the model.
     *
     * @return void
     */
    public function test_undecodable_sheet_skips_the_check(): void {
        $rec = $this->recording_with_sheet("\xFF\xD8\xFF not decodable but a jpeg by its magic");
        $client = self::fake_client([json_encode(['note' => 'Hands visible.', 'unusable_frames' => 0, 'confidence' => 'high'])]);

        $result = video_vision::observe($rec, $this->instance, $this->context, $client, $called);

        $this->assertDebuggingCalled();
        $this->assertTrue($called);
        $this->assertNull($result['dark']);
        $this->assertSame('high', $result['confidence']);
    }

    /**
     * A bright, textured sheet that passes the luminance check, or a skipped test without GD.
     *
     * @return string JPEG bytes.
     */
    public static function bright_sheet(): string {
        if (!function_exists('imagejpeg')) {
            self::markTestSkipped('GD is not available.');
        }

        return luminance_test::sheet([]);
    }

    /**
     * A dark sheet never reaches the model.
     *
     * @return void
     */
    public function test_dark_sheet_is_not_sent(): void {
        if (!function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is not available.');
        }
        $rec = $this->recording_with_sheet(luminance_test::sheet([0, 1, 2, 3]));
        $client = self::fake_client([]);

        $result = video_vision::observe($rec, $this->instance, $this->context, $client, $called);

        $this->assertFalse($called);
        $this->assertSame([], $client->calls);
        $this->assertSame(4, $result['dark']);
    }

    /**
     * Bytes that are not a picture are not sent, whatever the key says.
     *
     * @return void
     */
    public function test_non_image_is_not_sent(): void {
        $rec = $this->recording_with_sheet('<html>not a picture</html>');
        $client = self::fake_client([]);

        $result = video_vision::observe($rec, $this->instance, $this->context, $client, $called);

        $this->assertFalse($called);
        $this->assertTrue($result['badimage']);
        $this->assertSame('', $result['note']);
    }

    /**
     * A key with no object behind it is a storage failure, thrown for the pipeline to classify.
     *
     * @return void
     */
    public function test_missing_sheet_throws(): void {
        $rec = $this->recording_with_sheet(null);
        $this->expectException(\moodle_exception::class);
        video_vision::observe($rec, $this->instance, $this->context, self::fake_client([]));
    }

    /**
     * A reply that isn't the JSON asked for is a failed call, not an empty note.
     *
     * @return void
     */
    public function test_unparseable_reply_throws(): void {
        $rec = $this->recording_with_sheet(self::bright_sheet());
        $client = self::fake_client(['The speaker looks fine to me.']);

        try {
            video_vision::observe($rec, $this->instance, $this->context, $client, $called);
            $this->fail('A prose reply was accepted as evidence.');
        } catch (ai_exception $e) {
            $this->assertSame('bad_response', $e->reason);
            $this->assertTrue($called, 'The call was made, so its spend must be recorded.');
        }
    }

    /**
     * PNG is recognised as well as JPEG, and anything else is not.
     *
     * @return void
     */
    public function test_sniff(): void {
        $this->assertSame('image/jpeg', video_vision::sniff("\xFF\xD8\xFF\xE0rest"));
        $this->assertSame('image/png', video_vision::sniff("\x89PNG\r\n\x1A\nrest"));
        $this->assertNull(video_vision::sniff('GIF89a'));
        $this->assertNull(video_vision::sniff(''));
    }
}
