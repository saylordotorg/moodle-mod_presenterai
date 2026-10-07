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

use mod_presenterai\admin\setting_endpoint;
use mod_presenterai\local\ai\ai_exception;
use mod_presenterai\local\ai\security;

/**
 * SSRF validation, trusted hosts, DNS pinning and the endpoint setting.
 *
 * No test here resolves a real name: literal addresses, and a test resolver
 * where a name is needed.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\security
 * @covers     \mod_presenterai\admin\setting_endpoint
 */
final class ai_security_test extends \advanced_testcase {
    /**
     * Remove the resolver seam.
     *
     * @return void
     */
    protected function tearDown(): void {
        security::set_test_resolver(null);
        parent::tearDown();
    }

    /**
     * Private, loopback, link local and metadata addresses are refused.
     *
     * @dataProvider refused_urls
     * @param string $url A URL that must be refused.
     * @return void
     */
    public function test_refused(string $url): void {
        $this->resetAfterTest();
        $this->assertFalse(security::is_safe_url($url));
    }

    /**
     * URLs that must be refused.
     *
     * @return array
     */
    public static function refused_urls(): array {
        return [
            'http' => ['http://8.8.8.8/v1'],
            'private 10' => ['https://10.0.0.5/v1'],
            'private 192.168' => ['https://192.168.1.1/v1'],
            'private 172.16' => ['https://172.16.4.4/v1'],
            'loopback' => ['https://127.0.0.1/v1'],
            'loopback range' => ['https://127.8.9.10/v1'],
            'metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'link local' => ['https://169.254.1.1/'],
            'ipv6 loopback' => ['https://[::1]/v1'],
            'ipv6 unique local' => ['https://[fd00::1]/v1'],
            'ipv6 link local' => ['https://[fe80::1]/v1'],
            'ipv4 mapped loopback' => ['https://[::ffff:127.0.0.1]/v1'],
            'localhost' => ['https://localhost/v1'],
            'zero' => ['https://0.0.0.0/v1'],
            'no host' => ['https:///v1'],
            'not a url' => ['not a url'],
            'ftp' => ['ftp://8.8.8.8/'],
        ];
    }

    /**
     * A public address over https passes.
     *
     * @return void
     */
    public function test_public_https_passes(): void {
        $this->resetAfterTest();
        $this->assertTrue(security::is_safe_url('https://8.8.8.8/v1'));
        $this->assertTrue(security::is_safe_url('https://[2001:4860:4860::8888]/v1'));
        $this->assertTrue(security::is_safe_url('https://api.example.com/v1'));
    }

    /**
     * A name is judged by every address it resolves to; DNS failure is refused.
     *
     * @return void
     */
    public function test_names_are_resolved(): void {
        $this->resetAfterTest();
        security::set_test_resolver(fn() => ['93.184.216.34', '169.254.169.254']);
        $this->assertFalse(security::is_safe_url('https://sneaky.example.com/v1'));
        security::set_test_resolver(fn() => ['10.1.2.3']);
        $this->assertFalse(security::is_safe_url('https://internal.example.com/v1'));
        security::set_test_resolver(fn() => []);
        $this->assertFalse(security::is_safe_url('https://nxdomain.example.com/v1'));
        security::set_test_resolver(fn() => ['93.184.216.34']);
        $this->assertTrue(security::is_safe_url('https://public.example.com/v1'));
    }

    /**
     * A trusted entry lets a self hosted http endpoint on a private network
     * through, matching scheme and port when given.
     *
     * @return void
     */
    public function test_trusted_hosts(): void {
        $this->resetAfterTest();
        security::set_test_resolver(fn() => ['10.0.0.9']);
        $this->assertFalse(security::is_safe_url('http://whisper.internal:8000/v1/audio/transcriptions'));

        set_config('trustedhosts', "# comment\nhttp://whisper.internal:8000\n\n10.0.0.5", 'mod_presenterai');
        $this->assertTrue(security::is_safe_url('http://whisper.internal:8000/v1/audio/transcriptions'));
        $this->assertFalse(security::is_safe_url('http://whisper.internal:9000/v1'));
        $this->assertFalse(security::is_safe_url('https://whisper.internal:8000/v1'));
        $this->assertTrue(security::is_safe_url('http://10.0.0.5/v1'));
        $this->assertTrue(security::is_safe_url('https://10.0.0.5:8443/v1'));
        $this->assertCount(2, security::trusted_hosts());

        // No pin for a trusted host: it may legitimately be private.
        $this->assertSame([], security::pin_options('http://whisper.internal:8000/v1'));
    }

    /**
     * The pin uses the validated address and refuses a rebinding answer.
     *
     * @return void
     */
    public function test_pin_options(): void {
        $this->resetAfterTest();
        security::set_test_resolver(fn() => ['2606:4700::1', '93.184.216.34']);
        $this->assertSame(
            ['CURLOPT_RESOLVE' => ['api.example.com:443:93.184.216.34']],
            security::pin_options('https://api.example.com/v1')
        );
        security::set_test_resolver(fn() => ['2606:4700::1']);
        $this->assertSame(
            ['CURLOPT_RESOLVE' => ['api.example.com:8443:[2606:4700::1]']],
            security::pin_options('https://api.example.com:8443/v1')
        );
        $this->assertSame([], security::pin_options('https://8.8.8.8/v1'));

        security::set_test_resolver(fn() => ['169.254.169.254']);
        try {
            security::pin_options('https://api.example.com/v1');
            $this->fail('Expected unsafe_endpoint');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::UNSAFE_ENDPOINT, $e->reason);
        }
    }

    /**
     * A log line carries scheme, host and port, never a path or query.
     *
     * @return void
     */
    public function test_loggable(): void {
        $this->assertSame('https://api.example.com:8443', security::loggable('https://u:p@api.example.com:8443/v1?key=secret'));
    }

    /**
     * The endpoint setting refuses an unsafe URL on save and accepts empty.
     *
     * @return void
     */
    public function test_endpoint_setting(): void {
        $this->resetAfterTest();
        $setting = new setting_endpoint('mod_presenterai/compatibleendpoint', 'x', 'y');
        $this->assertTrue($setting->validate(''));
        $this->assertTrue($setting->validate('https://8.8.8.8/v1'));
        $this->assertSame(
            get_string('error:unsafeendpoint', 'mod_presenterai'),
            $setting->validate('https://169.254.169.254/v1')
        );
        $this->assertSame(
            get_string('error:unsafeendpoint', 'mod_presenterai'),
            $setting->validate('http://8.8.8.8/v1')
        );
    }
}
