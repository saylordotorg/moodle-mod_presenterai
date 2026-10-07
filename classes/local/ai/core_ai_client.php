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
 * Moodle core AI (route 'core'), for the scoring step only.
 *
 * Core AI's generate_text action takes exactly (contextid, userid,
 * prompttext): no system prompt, no images, no schema, no model, no token
 * ceiling (plan section 5.1). So this client flattens the system prompt and
 * the user message into one prompt, asks for JSON in words when a schema was
 * wanted, refuses images outright, and leaves the reply to json_parser.
 * Transcription and body language never come here.
 *
 * One adapter covers both APIs (plan section 5.4). On 4.5 \core_ai\manager
 * has no constructor and is_action_available() is static; on 5.x the
 * manager takes a \moodle_database and is reached through \core\di, and
 * is_action_enabled_in_context() exists and is honoured, so course and
 * activity level AI switches are respected (plan 5.5). The branch is decided
 * by inspecting the class, not by a version number.
 *
 * Core sets no timeout on its request path (plan 5.6), which is one reason
 * scoring runs in an adhoc task and never in a web request. A 429 from core's
 * own rate limiter is http_429 and transient; no provider, or the action
 * disabled, is core_disabled.
 *
 * Usage: core does not say which model served the call, so model is
 * 'core_ai' and is never priced. Token counts are recorded when the response
 * reports them and are zero otherwise.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_ai_client implements client_interface {
    /** @var string The model name recorded for core AI calls. */
    public const MODEL = 'core_ai';

    /** @var string The core action class. */
    private const ACTION = '\\core_ai\\aiactions\\generate_text';

    /** @var string The core manager class. */
    private const MANAGER = '\\core_ai\\manager';

    /** @var callable|null Test processor. */
    private static $testprocessor = null;

    /** @var array Usage of the last call. */
    private array $usage;

    /**
     * Build a client.
     */
    public function __construct() {
        $this->usage = self::empty_usage();
    }

    /**
     * Replace core AI with a processor (tests only).
     *
     * Called as fn(int $contextid, int $userid, string $prompttext) and
     * returns ['success' => bool, 'text' => string, 'errorcode' => int]. While
     * set, is_available() is true. Null removes it.
     *
     * @param callable|null $processor The processor, or null.
     * @return void
     */
    public static function set_test_processor(?callable $processor): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('core_ai_client::set_test_processor() is for unit tests only');
        }
        self::$testprocessor = $processor;
    }

    /**
     * Whether core AI exists here and can run generate_text.
     *
     * @return bool
     */
    public static function is_available(): bool {
        if (self::$testprocessor !== null) {
            return true;
        }
        if (!class_exists(self::MANAGER) || !class_exists(self::ACTION)) {
            return false;
        }
        try {
            $method = new \ReflectionMethod(self::MANAGER, 'is_action_available');
            if ($method->isStatic()) {
                return (bool) call_user_func([self::MANAGER, 'is_action_available'], self::ACTION);
            }
            return (bool) self::manager()->is_action_available(self::ACTION);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Which core AI API this site has.
     *
     * @return string '4.5' when the manager takes no constructor arguments,
     *     '5.x' when it needs the database injected.
     */
    public static function api_generation(): string {
        if (!class_exists(self::MANAGER)) {
            return '';
        }
        $constructor = (new \ReflectionClass(self::MANAGER))->getConstructor();
        return ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) ? '4.5' : '5.x';
    }

    /**
     * The generated text from a core response data array, trying the key
     * names used across Moodle 4.5 to 5.3: generatedcontent, then content,
     * then response.
     *
     * @param array $data From get_response_data().
     * @return string '' when no known key holds a string.
     */
    public static function extract_text(array $data): string {
        foreach (['generatedcontent', 'content', 'response'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                return $data[$key];
            }
        }
        return '';
    }

    /**
     * The one prompt core AI is sent.
     *
     * @param string $system The system prompt.
     * @param string $user The user message.
     * @param bool $wantsjson Whether a schema was asked for.
     * @return string
     */
    public static function flatten(string $system, string $user, bool $wantsjson): string {
        $prompt = "Instructions:\n" . $system . "\n\nRequest:\n" . $user;
        if ($wantsjson) {
            $prompt .= "\n\nRespond with JSON only.";
        }
        return $prompt;
    }

    /**
     * Generate text through core AI.
     *
     * @param string $system The system prompt.
     * @param string $user The user message.
     * @param array $opts See client_interface; contextid and userid required.
     * @return string
     * @throws ai_exception
     */
    public function generate_text(string $system, string $user, array $opts = []): string {
        $this->usage = self::empty_usage();
        if (!empty($opts['images'])) {
            throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, 'Moodle core AI cannot take images');
        }
        $contextid = (int) ($opts['contextid'] ?? 0);
        $userid = (int) ($opts['userid'] ?? 0);
        if ($contextid <= 0 || $userid <= 0) {
            throw new \coding_exception('core_ai_client needs contextid and userid');
        }
        $prompt = self::flatten($system, $user, !empty($opts['schema']));

        if (self::$testprocessor !== null) {
            $result = (array) (self::$testprocessor)($contextid, $userid, $prompt);
            $success = !empty($result['success']);
            $text = (string) ($result['text'] ?? '');
            $errorcode = (int) ($result['errorcode'] ?? 0);
            $errormessage = (string) ($result['errormessage'] ?? '');
            $data = [];
        } else {
            [$success, $data, $errorcode, $errormessage] = $this->process($contextid, $userid, $prompt);
            $text = self::extract_text($data);
        }

        $this->usage['prompttokens'] = (int) ($data['prompttokens'] ?? 0);
        $this->usage['completiontokens'] = (int) ($data['completiontokens'] ?? 0);

        if (!$success) {
            throw self::map_error($errorcode, $errormessage);
        }
        if ($text === '') {
            throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, 'Moodle core AI returned no text');
        }
        return $text;
    }

    /**
     * Run the action through the real manager.
     *
     * @param int $contextid The context.
     * @param int $userid The user the call is made for.
     * @param string $prompt The prompt.
     * @return array [success, response data, errorcode, errormessage]
     * @throws ai_exception core_disabled when core AI is missing or switched off.
     */
    private function process(int $contextid, int $userid, string $prompt): array {
        if (!class_exists(self::MANAGER) || !class_exists(self::ACTION)) {
            throw ai_exception::for_reason(ai_exception::CORE_DISABLED, 'Moodle core AI is not installed');
        }
        $manager = self::manager();
        if (method_exists($manager, 'is_action_enabled_in_context') && !self::enabled_in_context($manager, $contextid)) {
            throw ai_exception::for_reason(ai_exception::CORE_DISABLED, 'generate_text is switched off in this context');
        }
        $actionclass = self::ACTION;
        $action = new $actionclass($contextid, $userid, $prompt);
        $response = $manager->process_action($action);

        $success = method_exists($response, 'get_success') ? (bool) $response->get_success() : false;
        $data = method_exists($response, 'get_response_data') ? (array) $response->get_response_data() : [];
        $errorcode = method_exists($response, 'get_errorcode') ? (int) $response->get_errorcode() : 0;
        $errormessage = method_exists($response, 'get_errormessage') ? (string) $response->get_errormessage() : '';
        return [$success, $data, $errorcode, $errormessage];
    }

    /**
     * Ask the 5.x manager whether generate_text is enabled in a context.
     *
     * The signature is matched by parameter type rather than position, and a
     * call that cannot be made is treated as enabled with a developer notice:
     * the site wide check in process_action() still applies.
     *
     * @param object $manager The manager instance.
     * @param int $contextid The context.
     * @return bool
     */
    private static function enabled_in_context(object $manager, int $contextid): bool {
        try {
            $method = new \ReflectionMethod($manager, 'is_action_enabled_in_context');
            $args = [];
            foreach ($method->getParameters() as $param) {
                $type = $param->getType();
                $typename = $type instanceof \ReflectionNamedType ? $type->getName() : '';
                if ($typename === 'string') {
                    $args[] = ltrim(self::ACTION, '\\');
                } else if ($typename === 'int') {
                    $args[] = $contextid;
                } else if ($typename !== '' && is_a(\context::class, $typename, true)) {
                    $args[] = \context::instance_by_id($contextid);
                } else if ($param->isOptional()) {
                    break;
                } else {
                    $args[] = \context::instance_by_id($contextid);
                }
            }
            return (bool) $method->invokeArgs($manager, $args);
        } catch (\Throwable $e) {
            debugging('mod_presenterai: could not ask core AI whether generate_text is enabled in context '
                . $contextid . '; continuing with the site wide check.', DEBUG_DEVELOPER);
            return true;
        }
    }

    /**
     * The core AI manager for this Moodle version.
     *
     * @return object
     */
    private static function manager(): object {
        $class = ltrim(self::MANAGER, '\\');
        if (self::api_generation() === '4.5') {
            return new $class();
        }
        return \core\di::get($class);
    }

    /**
     * The ai_exception for a core error code.
     *
     * @param int $errorcode Core's error code, usually the provider's HTTP status.
     * @param string $errormessage Core's error message.
     * @return ai_exception
     */
    private static function map_error(int $errorcode, string $errormessage): ai_exception {
        $detail = 'Moodle core AI error ' . $errorcode . ': ' . \core_text::substr($errormessage, 0, 300);
        if ($errorcode === 429) {
            return ai_exception::for_reason(ai_exception::HTTP_429, $detail);
        }
        if ($errorcode >= 500 && $errorcode <= 599) {
            return ai_exception::for_reason(ai_exception::HTTP_5XX, $detail);
        }
        if ($errorcode >= 400 && $errorcode <= 499) {
            return ai_exception::for_reason(ai_exception::HTTP_4XX, $detail);
        }
        if ($errorcode === -1) {
            // No provider could take the action.
            return ai_exception::for_reason(ai_exception::CORE_DISABLED, $detail);
        }
        return ai_exception::for_reason(ai_exception::BAD_RESPONSE, $detail);
    }

    /**
     * Usage with nothing recorded yet.
     *
     * @return array
     */
    private static function empty_usage(): array {
        return ['prompttokens' => 0, 'completiontokens' => 0, 'model' => self::MODEL, 'provider' => 'core'];
    }

    /**
     * Core AI takes no images.
     *
     * @return bool
     */
    public function supports_images(): bool {
        return false;
    }

    /**
     * Core AI has no schema parameter.
     *
     * @return bool
     */
    public function supports_json_schema(): bool {
        return false;
    }

    /**
     * The route name.
     *
     * @return string
     */
    public function route(): string {
        return 'core';
    }

    /**
     * The model name recorded for core calls.
     *
     * @return string
     */
    public function model(): string {
        return self::MODEL;
    }

    /**
     * Usage of the last call.
     *
     * @return array
     */
    public function last_usage(): array {
        return $this->usage;
    }
}
