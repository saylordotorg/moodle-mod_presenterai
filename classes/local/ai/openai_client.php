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
 * OpenAI chat completions (route 'openai'), and any OpenAI compatible
 * endpoint an admin enters (route 'compatible').
 *
 * A port of SOLA's openai_compatible_provider, non streaming path only.
 * Images go as image_url content parts carrying data URIs. A schema goes as
 * response_format json_schema with strict true, OpenAI's native strict mode,
 * as SOLA ships it; the schema rules on client_interface keep that legal.
 * The GPT-5 and o1, o3 and o4 families reject max_tokens and temperature, so
 * they get max_completion_tokens and no temperature.
 *
 * Thinking models spend output tokens on reasoning before they write a word,
 * and the budgets here are small (the judge has 400). So a reasoning model
 * is asked to think little (reasoning_effort) and its token ceiling is raised
 * by REASONING_HEADROOM, and a reply cut off by the ceiling (finish_reason
 * length) is reported as truncated rather than as a bad reply. That covers
 * GPT-5 and the o3 and o4 families here, and Gemini 2.5 and later on the
 * gemini route, where Google maps reasoning_effort to its thinking budget.
 *
 * A compatible endpoint may be entered as an API base (https://host/v1) or as
 * the full chat completions URL; both work. Its key is optional, because a
 * self hosted server on a trusted host often has none.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openai_client implements client_interface {
    /** @var string The default API base. */
    public const DEFAULT_BASE = 'https://api.openai.com/v1';

    /** @var int Output tokens added to a reasoning model's ceiling, for its thinking. */
    public const REASONING_HEADROOM = 8192;

    /** @var string The API key. */
    protected string $apikey;

    /** @var string The model. */
    protected string $model;

    /** @var string The API base or full endpoint. */
    protected string $baseurl;

    /** @var string The route name. */
    protected string $routename;

    /** @var array Usage of the last call. */
    protected array $usage;

    /**
     * Build a client.
     *
     * @param string $apikey The API key, already decrypted; may be '' only
     *     on the compatible route.
     * @param string $model The model id.
     * @param string $baseurl The API base, or a full chat completions URL.
     * @param string $route 'openai' or 'compatible'.
     */
    public function __construct(
        string $apikey,
        string $model,
        string $baseurl = self::DEFAULT_BASE,
        string $route = 'openai'
    ) {
        $this->apikey = trim($apikey);
        $this->model = trim($model);
        $this->baseurl = rtrim(trim($baseurl), '/');
        $this->routename = $route;
        $this->usage = $this->empty_usage();
    }

    /**
     * Whether the model takes max_completion_tokens instead of max_tokens,
     * and rejects temperature: GPT-5 and the o1, o3 and o4 families.
     *
     * @param string $model A model id.
     * @return bool
     */
    public static function uses_max_completion_tokens(string $model): bool {
        $model = strtolower(trim($model));
        if ($model === '') {
            return false;
        }
        if (str_starts_with($model, 'gpt-5')) {
            return true;
        }
        return preg_match('/^o(?:1|3|4)(?:[-.]|$)/', $model) === 1;
    }

    /**
     * The reasoning_effort to send for this client's model, or '' to send none.
     *
     * 'low' rather than 'minimal' or 'none': every reasoning model OpenAI
     * offers takes 'low', and o1 takes none of them, so it gets nothing.
     *
     * @return string
     */
    protected function reasoning_effort(): string {
        $model = strtolower($this->model);
        if (str_starts_with($model, 'gpt-5') || preg_match('/^o(?:3|4)(?:[-.]|$)/', $model) === 1) {
            return 'low';
        }
        return '';
    }

    /**
     * The chat completions URL.
     *
     * @return string
     */
    public function endpoint(): string {
        if (str_ends_with($this->baseurl, '/chat/completions')) {
            return $this->baseurl;
        }
        return $this->baseurl . '/chat/completions';
    }

    /**
     * Generate text or structured output.
     *
     * @param string $system The system prompt.
     * @param string $user The user message.
     * @param array $opts See client_interface.
     * @return string
     * @throws ai_exception
     */
    public function generate_text(string $system, string $user, array $opts = []): string {
        $this->usage = $this->empty_usage();
        if ($this->model === '' || $this->baseurl === '' || ($this->apikey === '' && $this->routename !== 'compatible')) {
            throw ai_exception::for_reason(
                ai_exception::NOT_CONFIGURED,
                ucfirst($this->routename) . ' has no key, model or endpoint'
            );
        }

        $headers = ['Content-Type: application/json'];
        if ($this->apikey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apikey;
        }
        $json = json_encode($this->build_body($system, $user, $opts), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, 'Request body could not be encoded');
        }
        $response = http_client::post(
            $this->endpoint(),
            $headers,
            $json,
            ['timeout' => (int) ($opts['timeout'] ?? http_client::DEFAULT_TIMEOUT)]
        );
        $data = json_decode($response['body'], true);

        if (is_array($data)) {
            $this->add_usage($data);
        }
        http_client::raise_for_status($response['status'], $this->describe_error($data, $response['body']));
        if (!is_array($data) || !isset($data['choices'][0]['message']) || !is_array($data['choices'][0]['message'])) {
            throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, $this->describe_error($data, $response['body']));
        }

        $message = $data['choices'][0]['message'];
        $finish = (string) ($data['choices'][0]['finish_reason'] ?? '');
        if (!empty($message['refusal']) || $finish === 'content_filter') {
            throw ai_exception::for_reason(ai_exception::REFUSAL, ucfirst($this->routename) . ' declined the request');
        }
        if ($finish === 'length') {
            // Thinking, or a long answer, used the whole budget: what came back is
            // empty or cut off, and parsing it would only report a bad reply.
            throw ai_exception::for_reason(
                ai_exception::TRUNCATED,
                ucfirst($this->routename) . ' stopped at its output token limit (' . $this->model . ')'
            );
        }
        if (!isset($message['content']) || !is_string($message['content']) || $message['content'] === '') {
            throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, 'No content in the response');
        }
        return $message['content'];
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
        $messages = [];
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $parts = [];
        foreach ((array) ($opts['images'] ?? []) as $image) {
            if (empty($image['mime']) || empty($image['base64'])) {
                continue;
            }
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . $image['base64']],
            ];
        }
        if ($parts) {
            array_unshift($parts, ['type' => 'text', 'text' => $user]);
            $messages[] = ['role' => 'user', 'content' => $parts];
        } else {
            $messages[] = ['role' => 'user', 'content' => $user];
        }

        $body = ['model' => $this->model, 'messages' => $messages];
        $reasoning = self::uses_max_completion_tokens($this->model);
        $effort = $this->reasoning_effort();
        $maxtokens = (int) ($opts['max_tokens'] ?? 4096);
        if ($effort !== '' || $reasoning) {
            $maxtokens += self::REASONING_HEADROOM;
        }
        $body[$reasoning ? 'max_completion_tokens' : 'max_tokens'] = $maxtokens;
        if ($effort !== '') {
            $body['reasoning_effort'] = $effort;
        }
        if (!$reasoning && isset($opts['temperature'])) {
            $body['temperature'] = (float) $opts['temperature'];
        }

        if (!empty($opts['schema'])) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => (string) ($opts['schema']['name'] ?? 'structured_output'),
                    'schema' => $opts['schema']['schema'] ?? ['type' => 'object'],
                    'strict' => true,
                ],
            ];
        }
        return $body;
    }

    /**
     * Record one response's usage.
     *
     * @param array $data A decoded response.
     * @return void
     */
    protected function add_usage(array $data): void {
        if (!empty($data['model']) && is_string($data['model'])) {
            $this->usage['model'] = $data['model'];
        }
        if (empty($data['usage']) || !is_array($data['usage'])) {
            return;
        }
        $usage = $data['usage'];
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);
        $reasoning = (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);
        // Thinking is billed as output. OpenAI counts it inside
        // completion_tokens; Google's compatibility endpoint has not reliably
        // done so (SOLA measured 0.35M logged against 1.78M billed). Rather
        // than guess by vendor, total_tokens says which: when it is the sum
        // of all three, completion_tokens left the thinking out.
        $separate = isset($usage['total_tokens']) && (int) $usage['total_tokens'] >= $prompt + $completion + $reasoning;
        if ($reasoning > 0 && $separate) {
            $completion += $reasoning;
        }
        $this->usage['prompttokens'] += $prompt;
        $this->usage['completiontokens'] += $completion;
    }

    /**
     * A one line diagnostic from an error payload, without the key.
     *
     * @param mixed $data The decoded body, or null.
     * @param string $raw The raw body.
     * @return string
     */
    protected function describe_error($data, string $raw): string {
        if (is_array($data) && isset($data['error'])) {
            $error = $data['error'];
            $text = is_array($error)
                ? trim((string) ($error['type'] ?? $error['code'] ?? 'error') . ': ' . (string) ($error['message'] ?? ''))
                : 'error: ' . (string) $error;
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
    protected function empty_usage(): array {
        return ['prompttokens' => 0, 'completiontokens' => 0, 'model' => $this->model, 'provider' => $this->routename];
    }

    /**
     * OpenAI compatible endpoints take image parts.
     *
     * @return bool
     */
    public function supports_images(): bool {
        return true;
    }

    /**
     * Schemas are enforced through response_format.
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
        return $this->routename;
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
