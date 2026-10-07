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
 * Whisper compatible transcription (plan section 5.2).
 *
 * A port of SOLA's soapbox_scorer::transcribe_object(): a multipart POST of
 * the file and the model name, a Bearer key, and a 300 second timeout rather
 * than the chat path's 120, because a long recording takes a while. It goes
 * through http_client, so SSRF validation and DNS pinning apply to an admin
 * entered endpoint exactly as to a chat endpoint.
 *
 * Route 'openai' when it talks to OpenAI's own endpoint, 'compatible' for
 * any other (a self hosted Whisper server, for example).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stt_client implements stt_interface {
    /** @var string OpenAI's transcription endpoint. */
    public const OPENAI_ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';

    /** @var string The default model. */
    public const DEFAULT_MODEL = 'whisper-1';

    /** @var int Seconds allowed for one transcription. */
    public const TIMEOUT = 300;

    /** @var string The endpoint. */
    private string $endpoint;

    /** @var string The key; may be '' for a keyless self hosted server. */
    private string $apikey;

    /** @var string The model. */
    private string $model;

    /**
     * Build a client.
     *
     * @param string $endpoint The full transcription URL.
     * @param string $apikey The key, already decrypted.
     * @param string $model The model id; '' means whisper-1.
     */
    public function __construct(string $endpoint, string $apikey, string $model = self::DEFAULT_MODEL) {
        $this->endpoint = trim($endpoint);
        $this->apikey = trim($apikey);
        $this->model = trim($model) !== '' ? trim($model) : self::DEFAULT_MODEL;
    }

    /**
     * Whether the admin turned pre warming on and there is a custom endpoint
     * to warm. OpenAI's own endpoint is never cold, so it is never pinged.
     *
     * @return bool
     */
    public static function warm_enabled(): bool {
        return (int) get_config('mod_presenterai', 'sttwarm') === 1
            && trim((string) get_config('mod_presenterai', 'sttendpoint')) !== '';
    }

    /**
     * Transcribe a file.
     *
     * @param string $filepath A readable local file.
     * @param string $mime Its MIME type.
     * @return array ['text' => string, 'model' => string]
     * @throws ai_exception
     */
    public function transcribe(string $filepath, string $mime): array {
        if ($this->endpoint === '') {
            throw ai_exception::for_reason(ai_exception::NOT_CONFIGURED, 'No transcription endpoint');
        }
        if (!is_readable($filepath) || filesize($filepath) === 0) {
            throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, 'The recording file is missing or empty');
        }
        $headers = [];
        if ($this->apikey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apikey;
        }
        $body = [
            'file' => new \CURLFile($filepath, $mime !== '' ? $mime : 'application/octet-stream', basename($filepath)),
            'model' => $this->model,
        ];
        $response = http_client::post($this->endpoint, $headers, $body, ['timeout' => self::TIMEOUT]);
        $data = json_decode($response['body'], true);
        http_client::raise_for_status($response['status'], $this->describe_error($data, $response['body']));

        $text = is_array($data) && isset($data['text']) && is_string($data['text']) ? trim($data['text']) : '';
        if ($text === '') {
            throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, 'The transcription came back empty');
        }
        return ['text' => $text, 'model' => $this->model];
    }

    /**
     * A one line diagnostic from an error payload, without the key.
     *
     * @param mixed $data The decoded body, or null.
     * @param string $raw The raw body.
     * @return string
     */
    private function describe_error($data, string $raw): string {
        if (is_array($data) && isset($data['error'])) {
            $error = $data['error'];
            $text = is_array($error) ? (string) ($error['message'] ?? 'error') : (string) $error;
        } else {
            $text = trim($raw) !== '' ? 'Unrecognised response: ' . trim($raw) : 'Empty response';
        }
        if ($this->apikey !== '') {
            $text = str_replace($this->apikey, '[key]', $text);
        }
        return \core_text::substr($text, 0, 400);
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
     * The route name.
     *
     * @return string
     */
    public function route(): string {
        return $this->endpoint === self::OPENAI_ENDPOINT ? 'openai' : 'compatible';
    }

    /**
     * The endpoint, for the warm up ping.
     *
     * @return string
     */
    public function endpoint(): string {
        return $this->endpoint;
    }
}
