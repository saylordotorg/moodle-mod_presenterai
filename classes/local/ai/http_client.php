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
 * The one place PresenterAI's AI code sends an HTTP request.
 *
 * Every request goes through Moodle's \curl, after security::is_safe_url()
 * and with security::pin_options() merged in, so SSRF validation and DNS
 * pinning cannot be skipped by a client that forgets them. Moodle's own curl
 * security (the blocked hosts and ports settings) applies on top.
 *
 * Transport failures become ai_exception here: curl error 28 is a timeout,
 * any other is a network failure, both transient. HTTP statuses are returned
 * to the caller, who maps them with raise_for_status() once it has read the
 * provider's error message out of the body.
 *
 * Tests install a handler with set_test_handler(); every request is then
 * answered by it and nothing touches the network.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_client {
    /** @var int Default request timeout, seconds. */
    public const DEFAULT_TIMEOUT = 120;

    /** @var int Connect timeout, seconds. */
    public const CONNECT_TIMEOUT = 10;

    /** @var int The curl error number for a timeout. */
    private const CURLE_OPERATION_TIMEDOUT = 28;

    /** @var callable|null Test handler. */
    private static $testhandler = null;

    /** @var array Requests seen by the test handler. */
    private static array $requests = [];

    /**
     * Answer every request with a handler instead of the network (tests only).
     *
     * The handler is called as fn(string $url, array $headers, string|array
     * $body, array $options) and returns ['status' => int, 'body' => string],
     * optionally with 'errno' and 'error' to simulate a transport failure.
     * Passing null removes it and clears the recorded requests.
     *
     * @param callable|null $handler The handler, or null.
     * @return void
     */
    public static function set_test_handler(?callable $handler): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('http_client::set_test_handler() is for unit tests only');
        }
        self::$testhandler = $handler;
        self::$requests = [];
    }

    /**
     * The requests the test handler has answered, oldest first.
     *
     * @return array List of ['url', 'headers', 'body', 'options'].
     */
    public static function last_requests(): array {
        return self::$requests;
    }

    /**
     * POST to an endpoint.
     *
     * @param string $url The endpoint.
     * @param array $headers Header lines, "Name: value".
     * @param string|array $body A raw body, or an array for multipart.
     * @param array $options 'timeout' and 'connecttimeout' in seconds.
     * @return array ['status' => int, 'body' => string, 'error' => string]
     * @throws ai_exception unsafe_endpoint, timeout or network.
     */
    public static function post(string $url, array $headers, string|array $body, array $options = []): array {
        return self::request('POST', $url, $headers, $body, $options);
    }

    /**
     * GET an endpoint.
     *
     * @param string $url The endpoint.
     * @param array $headers Header lines.
     * @param array $options 'timeout' and 'connecttimeout' in seconds.
     * @return array ['status' => int, 'body' => string, 'error' => string]
     * @throws ai_exception unsafe_endpoint, timeout or network.
     */
    public static function get(string $url, array $headers = [], array $options = []): array {
        return self::request('GET', $url, $headers, '', $options);
    }

    /**
     * Throw the ai_exception an HTTP status calls for, or return on 2xx.
     *
     * 429 is http_429 and 500 to 599 (Anthropic's 529 overloaded among them)
     * is http_5xx, both transient; any other status is http_4xx, which is
     * not. A transport level status of 0 is a network failure.
     *
     * @param int $status The HTTP status.
     * @param string $debuginfo The provider's error message; never a key.
     * @return void
     * @throws ai_exception For anything but 2xx.
     */
    public static function raise_for_status(int $status, string $debuginfo = ''): void {
        if ($status >= 200 && $status < 300) {
            return;
        }
        $detail = 'HTTP ' . $status . ($debuginfo !== '' ? ': ' . $debuginfo : '');
        if ($status === 429) {
            throw ai_exception::for_reason(ai_exception::HTTP_429, $detail);
        }
        if ($status >= 500 && $status <= 599) {
            throw ai_exception::for_reason(ai_exception::HTTP_5XX, $detail);
        }
        if ($status === 0) {
            throw ai_exception::for_reason(ai_exception::NETWORK, $detail);
        }
        throw ai_exception::for_reason(ai_exception::HTTP_4XX, $detail);
    }

    /**
     * Send one request.
     *
     * @param string $method POST or GET.
     * @param string $url The endpoint.
     * @param array $headers Header lines.
     * @param string|array $body The body.
     * @param array $options Timeouts.
     * @return array ['status', 'body', 'error']
     * @throws ai_exception
     */
    private static function request(string $method, string $url, array $headers, string|array $body, array $options): array {
        global $CFG;

        if (!security::is_safe_url($url)) {
            throw ai_exception::for_reason(
                ai_exception::UNSAFE_ENDPOINT,
                'Refused endpoint ' . security::loggable($url)
            );
        }
        $timeout = max(1, (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT));
        $connecttimeout = max(1, (int) ($options['connecttimeout'] ?? self::CONNECT_TIMEOUT));

        if (self::$testhandler !== null) {
            $options = ['method' => $method, 'timeout' => $timeout, 'connecttimeout' => $connecttimeout]
                + security::pin_options($url);
            self::$requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
            $result = (array) (self::$testhandler)($url, $headers, $body, $options);
            return self::finish(
                (int) ($result['errno'] ?? 0),
                (string) ($result['error'] ?? ''),
                (int) ($result['status'] ?? 0),
                (string) ($result['body'] ?? ''),
                $url
            );
        }

        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader($headers);
        $curlopts = [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => $connecttimeout,
        ] + security::pin_options($url);

        $response = $method === 'GET' ? $curl->get($url, [], $curlopts) : $curl->post($url, $body, $curlopts);
        $info = $curl->get_info();
        return self::finish(
            (int) $curl->get_errno(),
            (string) ($curl->error ?? ''),
            (int) ($info['http_code'] ?? 0),
            is_string($response) ? $response : '',
            $url
        );
    }

    /**
     * Turn a transport result into a response or an exception.
     *
     * @param int $errno The curl error number, 0 for none.
     * @param string $error The curl error text.
     * @param int $status The HTTP status.
     * @param string $body The response body.
     * @param string $url The endpoint, for the diagnostic.
     * @return array ['status', 'body', 'error']
     * @throws ai_exception timeout or network.
     */
    private static function finish(int $errno, string $error, int $status, string $body, string $url): array {
        if ($errno === self::CURLE_OPERATION_TIMEDOUT) {
            throw ai_exception::for_reason(ai_exception::TIMEOUT, 'Timed out calling ' . security::loggable($url));
        }
        if ($errno !== 0) {
            throw ai_exception::for_reason(
                ai_exception::NETWORK,
                'Transport error ' . $errno . ' calling ' . security::loggable($url) . ': '
                    . \core_text::substr($error, 0, 200)
            );
        }
        return ['status' => $status, 'body' => $body, 'error' => $error];
    }
}
