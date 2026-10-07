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
 * Claude, through the Anthropic Messages API (route 'claude').
 *
 * A port of SOLA's claude_provider (non streaming path only), keeping the
 * rules SOLA learned in production:
 *
 * (a) temperature is sent only to models on an allow list. Every reasoning
 *     class model since Opus 4.7 rejects it with a 400, including
 *     claude-opus-5-5, claude-sonnet-5-5, claude-fable-5-1 and
 *     claude-mythos-5-1, and an unknown model is more likely to reject it
 *     than accept it, so an unknown model gets none. Thinking is off unless
 *     asked for, and asking for it never adds temperature to those models.
 * (b) Structured output is a tool. Models that accept a forced tool choice
 *     get tool_choice {type: tool}. Opus 5.5, Sonnet 5.5, Fable 5.1 and
 *     Mythos 5.1 reject it with a 400, so they get tool_choice {type: auto}
 *     plus a second system block telling them to call the tool, kept apart
 *     from the first so the cached prefix survives. 'auto' does not
 *     guarantee a call, so when no tool_use comes back the request is sent
 *     once more, the token usage of both is summed, and if the retry fails
 *     in any way the first response is used.
 * (c) "strict" is never sent. Strict compiles the schema through a pipeline
 *     that rejects minItems above 1, maxItems and numeric bounds, and this
 *     is raw HTTP, so nothing strips them first: SOLA measured a 400 on
 *     every structured call the one time it was tried.
 * (d) Usage is recorded for any response that parsed, before the refusal and
 *     empty content exits, because a refused call is still billed. A
 *     refusal (stop_reason 'refusal') throws ai_exception refusal.
 * (e) The tool input comes back json encoded; otherwise the first text
 *     block, skipping thinking blocks.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claude_client implements client_interface {
    /** @var string The Anthropic API version header value. */
    public const API_VERSION = '2023-06-01';

    /** @var string The default API base. */
    public const DEFAULT_BASE = 'https://api.anthropic.com';

    /** @var string The stop_reason of a model level refusal. */
    public const STOP_REASON_REFUSAL = 'refusal';

    /**
     * @var string[] Prefixes of models known to accept temperature. An allow
     * list: sending temperature to a model that rejects it fails every call,
     * leaving it out of one that accepts it costs nothing.
     */
    public const DEFAULT_TEMPERATURE_ALLOW_PREFIXES = [
        'claude-opus-4-6', 'claude-opus-4-5', 'claude-opus-4-1', 'claude-opus-4-0',
        'claude-sonnet-4-6', 'claude-sonnet-4-5', 'claude-sonnet-4-0',
        'claude-haiku-4-5', 'claude-haiku-3-5', 'claude-haiku-3',
        'claude-opus-4-2025', 'claude-sonnet-4-2025',
        'claude-3', 'claude-2',
    ];

    /**
     * @var string[] Prefixes of models that reject a forced tool choice. A
     * deny list is safe here because each denied id is longer than the ids
     * it could be confused with.
     */
    public const FORCED_TOOL_CHOICE_DENY_PREFIXES = [
        'claude-opus-5-5',
        'claude-sonnet-5-5',
        'claude-fable-5-1',
        'claude-mythos-5-1',
    ];

    /** @var string The API key. */
    private string $apikey;

    /** @var string The model. */
    private string $model;

    /** @var string The API base, without a trailing slash. */
    private string $baseurl;

    /** @var array Usage of the last call. */
    private array $usage;

    /**
     * Build a client.
     *
     * @param string $apikey The API key, already decrypted.
     * @param string $model The model id.
     * @param string $baseurl The API base.
     */
    public function __construct(string $apikey, string $model, string $baseurl = self::DEFAULT_BASE) {
        $this->apikey = trim($apikey);
        $this->model = trim($model);
        $this->baseurl = rtrim(trim($baseurl), '/');
        $this->usage = $this->empty_usage();
    }

    /**
     * Whether a model accepts the temperature parameter.
     *
     * @param string $model A model id.
     * @return bool
     */
    public static function model_supports_temperature(string $model): bool {
        $model = strtolower(trim($model));
        if ($model === '') {
            return false;
        }
        foreach (self::DEFAULT_TEMPERATURE_ALLOW_PREFIXES as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a model accepts tool_choice of type tool.
     *
     * @param string $model A model id.
     * @return bool
     */
    public static function model_supports_forced_tool_choice(string $model): bool {
        $model = strtolower(trim($model));
        foreach (self::FORCED_TOOL_CHOICE_DENY_PREFIXES as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Generate text or structured output.
     *
     * @param string $system The system prompt.
     * @param string $user The user message.
     * @param array $opts See client_interface; also 'thinking' => bool.
     * @return string
     * @throws ai_exception
     */
    public function generate_text(string $system, string $user, array $opts = []): string {
        $this->usage = $this->empty_usage();
        if ($this->apikey === '' || $this->model === '') {
            throw ai_exception::for_reason(ai_exception::NOT_CONFIGURED, 'Claude has no key or no model');
        }

        $body = $this->build_body($system, $user, $opts);
        $url = $this->baseurl . '/v1/messages';
        $timeout = (int) ($opts['timeout'] ?? http_client::DEFAULT_TIMEOUT);
        $data = $this->send($url, $body, $timeout);

        if (!empty($opts['schema'])) {
            $input = self::find_tool_input($data);
            if ($input !== null) {
                return self::encode_tool_input($input);
            }
            if (!$this->forces_tool($opts)) {
                // Rule (b): 'auto' did not call the tool. One retry, best
                // effort: whatever goes wrong, the first response stands.
                try {
                    $retry = $this->send($url, $body, $timeout);
                    $input = self::find_tool_input($retry);
                    if ($input !== null) {
                        return self::encode_tool_input($input);
                    }
                } catch (ai_exception $e) {
                    debugging('mod_presenterai: Claude structured output retry failed, keeping the first response ('
                        . $e->reason . ')', DEBUG_DEVELOPER);
                }
            }
        }

        foreach ($data['content'] as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                return $block['text'];
            }
        }
        throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, 'No text block in the Claude response');
    }

    /**
     * Build the request body.
     *
     * @param string $system The system prompt.
     * @param string $user The user message.
     * @param array $opts The options.
     * @return array
     */
    public function build_body(string $system, string $user, array $opts): array {
        $content = [['type' => 'text', 'text' => $user]];
        foreach ((array) ($opts['images'] ?? []) as $image) {
            if (empty($image['mime']) || empty($image['base64'])) {
                continue;
            }
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => (string) $image['mime'],
                    'data' => (string) $image['base64'],
                ],
            ];
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => (int) ($opts['max_tokens'] ?? 4096),
            'system' => [
                ['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages' => [['role' => 'user', 'content' => $content]],
        ];

        $temperatureok = self::model_supports_temperature($this->model);
        if (!empty($opts['thinking'])) {
            $body['thinking'] = ['type' => 'adaptive'];
            if ($temperatureok) {
                // Extended thinking on the models that still take sampling
                // parameters requires exactly 1.
                $body['temperature'] = 1;
            }
        } else if ($temperatureok && isset($opts['temperature'])) {
            $body['temperature'] = (float) $opts['temperature'];
        }

        if (!empty($opts['schema'])) {
            $name = (string) ($opts['schema']['name'] ?? 'structured_output');
            $body['tools'] = [[
                'name' => $name,
                'description' => 'Return the result as structured data.',
                'input_schema' => $opts['schema']['schema'] ?? ['type' => 'object'],
            ]];
            if ($this->forces_tool($opts)) {
                $body['tool_choice'] = ['type' => 'tool', 'name' => $name];
            } else {
                $body['tool_choice'] = ['type' => 'auto'];
                $body['system'][] = [
                    'type' => 'text',
                    'text' => 'Respond by calling the ' . $name . ' tool. Do not answer in prose.',
                ];
            }
        }

        return $body;
    }

    /**
     * Whether this call forces the tool. Not on the models that reject it,
     * and not with thinking on, which the API refuses alongside a forced tool.
     *
     * @param array $opts The options.
     * @return bool
     */
    private function forces_tool(array $opts): bool {
        return empty($opts['thinking']) && self::model_supports_forced_tool_choice($this->model);
    }

    /**
     * Send the request, record its usage and check its shape.
     *
     * @param string $url The endpoint.
     * @param array $body The request body.
     * @param int $timeout Seconds.
     * @return array The decoded response, with a non empty content list.
     * @throws ai_exception
     */
    private function send(string $url, array $body, int $timeout): array {
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apikey,
            'anthropic-version: ' . self::API_VERSION,
        ];
        $response = http_client::post($url, $headers, self::encode($body), ['timeout' => $timeout]);
        $data = json_decode($response['body'], true);

        // Rule (d): bill every response that parsed, before any exit.
        if (is_array($data)) {
            $this->add_usage($data);
        }
        http_client::raise_for_status($response['status'], $this->describe_error($data, $response['body']));

        if (!is_array($data)) {
            throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, $this->describe_error($data, $response['body']));
        }
        if (($data['stop_reason'] ?? '') === self::STOP_REASON_REFUSAL) {
            throw ai_exception::for_reason(ai_exception::REFUSAL, 'Claude declined the request');
        }
        if (empty($data['content']) || !is_array($data['content'])) {
            throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, $this->describe_error($data, $response['body']));
        }
        return $data;
    }

    /**
     * Add one response's usage to the running total.
     *
     * Cache creation and cache read tokens are folded into prompttokens: they
     * are input, billed at their own rates, and usage has one input column.
     *
     * @param array $data A decoded response.
     * @return void
     */
    private function add_usage(array $data): void {
        if (!empty($data['model']) && is_string($data['model'])) {
            $this->usage['model'] = $data['model'];
        }
        if (empty($data['usage']) || !is_array($data['usage'])) {
            return;
        }
        $u = $data['usage'];
        $this->usage['prompttokens'] += (int) ($u['input_tokens'] ?? 0)
            + (int) ($u['cache_creation_input_tokens'] ?? 0)
            + (int) ($u['cache_read_input_tokens'] ?? 0);
        $this->usage['completiontokens'] += (int) ($u['output_tokens'] ?? 0);
    }

    /**
     * The input of the first tool_use block, or null.
     *
     * @param array $data A decoded response.
     * @return array|null
     */
    private static function find_tool_input(array $data): ?array {
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                return (array) ($block['input'] ?? []);
            }
        }
        return null;
    }

    /**
     * Encode a tool input as the JSON string callers expect.
     *
     * An empty input encodes as an object, not a list.
     *
     * @param array $input The tool input.
     * @return string
     */
    private static function encode_tool_input(array $input): string {
        return $input === [] ? '{}' : (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Encode a request body; invalid UTF-8 is substituted rather than sent
     * as an empty body, which the API would answer with a 400 naming nothing.
     *
     * @param array $body The body.
     * @return string
     * @throws ai_exception bad_response when it cannot be encoded at all.
     */
    private static function encode(array $body): string {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, 'Request body could not be encoded');
        }
        return $json;
    }

    /**
     * A one line diagnostic from an error payload, 400 characters at most and
     * with the key removed.
     *
     * @param mixed $data The decoded body, or null.
     * @param string $raw The raw body.
     * @return string
     */
    private function describe_error($data, string $raw): string {
        if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
            $text = trim((string) ($data['error']['type'] ?? 'error') . ': ' . (string) ($data['error']['message'] ?? ''));
        } else if (trim($raw) !== '') {
            $text = 'Unrecognised response: ' . trim($raw);
        } else {
            $text = 'Empty response';
        }
        if ($this->apikey !== '') {
            $text = str_replace($this->apikey, '[key]', $text);
        }
        return \core_text::substr($text, 0, 400);
    }

    /**
     * Usage with nothing recorded yet.
     *
     * @return array
     */
    private function empty_usage(): array {
        return ['prompttokens' => 0, 'completiontokens' => 0, 'model' => $this->model, 'provider' => 'claude'];
    }

    /**
     * Claude takes images.
     *
     * @return bool
     */
    public function supports_images(): bool {
        return true;
    }

    /**
     * Claude enforces a schema through a tool.
     *
     * @return bool
     */
    public function supports_json_schema(): bool {
        return true;
    }

    /**
     * The route name.
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
        return $this->model;
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
