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
use mod_presenterai\local\ai\http_client;
use mod_presenterai\local\ai\security;

/**
 * The HTTP seam: SSRF refusal before any request, status and transport
 * mapping, and the transient flag on each.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\http_client
 * @covers     \mod_presenterai\local\ai\ai_exception
 */
final class ai_http_client_test extends \advanced_testcase {
    /**
     * Remove the seams.
     *
     * @return void
     */
    protected function tearDown(): void {
        http_client::set_test_handler(null);
        security::set_test_resolver(null);
        parent::tearDown();
    }

    /**
     * The handler answers, the request is recorded, and the DNS pin is applied.
     *
     * @return void
     */
    public function test_handler_answers_and_pins(): void {
        $this->resetAfterTest();
        http_client::set_test_handler(fn() => ['status' => 200, 'body' => 'ok']);
        $result = http_client::post('https://api.example.com/x', ['A: b'], 'payload', ['timeout' => 33]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('ok', $result['body']);
        $requests = http_client::last_requests();
        $this->assertCount(1, $requests);
        $this->assertSame('https://api.example.com/x', $requests[0]['url']);
        $this->assertSame('payload', $requests[0]['body']);
        $this->assertSame(33, $requests[0]['options']['timeout']);
        $this->assertSame(10, $requests[0]['options']['connecttimeout']);
        $this->assertSame('POST', $requests[0]['options']['method']);
        $this->assertSame(
            ['api.example.com:443:' . security::TEST_PUBLIC_ADDRESS],
            $requests[0]['options']['CURLOPT_RESOLVE']
        );
    }

    /**
     * An unsafe endpoint is refused before the handler is ever called.
     *
     * @dataProvider unsafe_urls
     * @param string $url An endpoint that must be refused.
     * @return void
     */
    public function test_unsafe_endpoint_refused(string $url): void {
        $this->resetAfterTest();
        $called = false;
        http_client::set_test_handler(function () use (&$called) {
            $called = true;
            return ['status' => 200, 'body' => ''];
        });
        try {
            http_client::post($url, [], '');
            $this->fail('Expected unsafe_endpoint');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::UNSAFE_ENDPOINT, $e->reason);
            $this->assertFalse($e->transient);
        }
        $this->assertFalse($called);
        $this->assertSame([], http_client::last_requests());
    }

    /**
     * Endpoints that must never be called.
     *
     * @return array
     */
    public static function unsafe_urls(): array {
        return [
            'plain http' => ['http://api.example.com/v1'],
            'metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'loopback' => ['https://127.0.0.1/v1'],
            'private 10' => ['https://10.0.0.5/v1'],
            'ipv6 loopback' => ['https://[::1]/v1'],
        ];
    }

    /**
     * A name that resolves to a private address is refused too.
     *
     * @return void
     */
    public function test_rebinding_name_refused(): void {
        $this->resetAfterTest();
        security::set_test_resolver(fn() => ['169.254.169.254']);
        http_client::set_test_handler(fn() => ['status' => 200, 'body' => '']);
        $this->expectException(ai_exception::class);
        http_client::post('https://evil.example.com/v1', [], '');
    }

    /**
     * Statuses map to reasons with the right transient flag.
     *
     * @dataProvider statuses
     * @param int $status The HTTP status.
     * @param string $reason The expected reason.
     * @param bool $transient The expected flag.
     * @return void
     */
    public function test_status_mapping(int $status, string $reason, bool $transient): void {
        try {
            http_client::raise_for_status($status, 'detail');
            $this->fail('Expected an exception');
        } catch (ai_exception $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($transient, $e->transient);
            $this->assertSame('error:aicall', $e->errorcode);
            $this->assertStringContainsString('detail', $e->debuginfo);
        }
    }

    /**
     * Status to reason table.
     *
     * @return array
     */
    public static function statuses(): array {
        return [
            '429' => [429, ai_exception::HTTP_429, true],
            '503' => [503, ai_exception::HTTP_5XX, true],
            '500' => [500, ai_exception::HTTP_5XX, true],
            '529 overloaded' => [529, ai_exception::HTTP_5XX, true],
            '400' => [400, ai_exception::HTTP_4XX, false],
            '401' => [401, ai_exception::HTTP_4XX, false],
            '404' => [404, ai_exception::HTTP_4XX, false],
        ];
    }

    /**
     * A 2xx does not throw.
     *
     * @return void
     */
    public function test_success_status_passes(): void {
        http_client::raise_for_status(200);
        http_client::raise_for_status(204);
        $this->assertTrue(true);
    }

    /**
     * curl error 28 is a transient timeout, any other a transient network failure.
     *
     * @return void
     */
    public function test_transport_errors(): void {
        $this->resetAfterTest();
        http_client::set_test_handler(fn() => ['status' => 0, 'body' => '', 'errno' => 28, 'error' => 'timed out']);
        try {
            http_client::post('https://api.example.com/v1', [], '');
            $this->fail('Expected timeout');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::TIMEOUT, $e->reason);
            $this->assertTrue($e->transient);
        }

        http_client::set_test_handler(fn() => ['status' => 0, 'body' => '', 'errno' => 7, 'error' => 'refused']);
        try {
            http_client::get('https://api.example.com/v1');
            $this->fail('Expected network');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::NETWORK, $e->reason);
            $this->assertTrue($e->transient);
        }
        $this->assertSame('GET', http_client::last_requests()[0]['options']['method']);
    }

    /**
     * The transient set is exactly the five retryable reasons.
     *
     * @return void
     */
    public function test_transient_reasons(): void {
        foreach (['rate_limited', 'http_429', 'http_5xx', 'timeout', 'network'] as $reason) {
            $this->assertTrue(ai_exception::for_reason($reason)->transient, $reason);
        }
        foreach (['not_configured', 'unsafe_endpoint', 'http_4xx', 'refusal', 'bad_response', 'core_disabled'] as $reason) {
            $this->assertFalse(ai_exception::for_reason($reason)->transient, $reason);
        }
    }
}
