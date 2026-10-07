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
 * SSRF validation and DNS pinning for every outbound AI request (D20, plan 5.7).
 *
 * A port of SOLA's security::is_safe_provider_url(), host_is_trusted() and
 * resolve_pin_options(), with three changes. IPv6 is resolved and checked as
 * well as IPv4, where SOLA only ever asked gethostbyname(). Every resolved
 * address must pass, not just the first. And the ranges that matter most are
 * checked by explicit CIDR as well as by PHP's FILTER_FLAG_NO_PRIV_RANGE and
 * FILTER_FLAG_NO_RES_RANGE, whose coverage has changed between PHP releases:
 * 169.254.0.0/16 (where 169.254.169.254, the cloud metadata service, lives),
 * 127.0.0.0/8, ::1, fc00::/7 and fe80::/10 are refused whatever PHP thinks.
 *
 * Rules: https only; a host is required; localhost and 0.0.0.0 are refused;
 * the host is resolved and every address must be public; a host that does not
 * resolve is refused (fail closed). An entry in the trustedhosts setting
 * bypasses the https and private address checks, for a self hosted model on
 * the same network as Moodle.
 *
 * pin_options() closes the DNS rebinding window between this check and the
 * connection: it resolves the host a final time and pins curl to that address
 * with CURLOPT_RESOLVE, so a hostile DNS server cannot answer with a public
 * address at validation time and a private one at connect time.
 *
 * Tests never resolve real names: under PHPUNIT_TEST every name resolves to a
 * fixed public address unless a test installs its own resolver.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class security {
    /** @var string What every name resolves to under PHPUNIT_TEST by default. */
    public const TEST_PUBLIC_ADDRESS = '8.8.8.8';

    /**
     * @var string[] Ranges refused whatever PHP's filter flags say. Private,
     * loopback, link local (cloud metadata), carrier grade NAT, multicast and
     * reserved.
     */
    private const DENIED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var callable|null Test resolver: fn(string $host): string[]. */
    private static $testresolver = null;

    /**
     * Install a resolver for tests, or null to restore the default.
     *
     * @param callable|null $resolver fn(string $host): string[] of addresses.
     * @return void
     */
    public static function set_test_resolver(?callable $resolver): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('security::set_test_resolver() is for unit tests only');
        }
        self::$testresolver = $resolver;
    }

    /**
     * Whether an admin entered endpoint is safe to send a request to.
     *
     * @param string $url The endpoint.
     * @return bool
     */
    public static function is_safe_url(string $url): bool {
        $parts = self::parse($url);
        if ($parts === null) {
            return false;
        }
        [$scheme, $host, $port] = $parts;

        if (self::host_is_trusted($scheme, $host, $port)) {
            return true;
        }
        if ($scheme !== 'https') {
            return false;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '0.0.0.0') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::is_public_ip($host);
        }
        $addresses = self::resolve($host);
        if (!$addresses) {
            // DNS failed: refuse rather than guess.
            return false;
        }
        foreach ($addresses as $address) {
            if (!self::is_public_ip($address)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The curl options that pin the connection to the validated address.
     *
     * Empty when no pin applies: a proxy is configured (the proxy resolves the
     * name), the host is a literal address (nothing to rebind), or the host is
     * trusted (it may legitimately resolve to a private address).
     *
     * @param string $url An endpoint that has already passed is_safe_url().
     * @return array ['CURLOPT_RESOLVE' => ['host:port:address']] or [].
     * @throws ai_exception unsafe_endpoint when the host now resolves to a
     *     forbidden address or does not resolve at all.
     */
    public static function pin_options(string $url): array {
        global $CFG;
        if (!empty($CFG->proxyhost)) {
            return [];
        }
        $parts = self::parse($url);
        if ($parts === null) {
            throw ai_exception::for_reason(ai_exception::UNSAFE_ENDPOINT, 'Unparseable endpoint');
        }
        [$scheme, $host, $port] = $parts;
        if (filter_var($host, FILTER_VALIDATE_IP) || self::host_is_trusted($scheme, $host, $port)) {
            return [];
        }
        $addresses = self::resolve($host);
        $chosen = null;
        foreach ($addresses as $address) {
            if (!self::is_public_ip($address)) {
                $chosen = null;
                break;
            }
            // Prefer IPv4, which every curl build accepts in CURLOPT_RESOLVE.
            if ($chosen === null || (str_contains($chosen, ':') && !str_contains($address, ':'))) {
                $chosen = $address;
            }
        }
        if ($chosen === null) {
            throw ai_exception::for_reason(
                ai_exception::UNSAFE_ENDPOINT,
                'Host failed SSRF re-validation (possible DNS rebinding): ' . $host
            );
        }
        $effectiveport = $port ?? ($scheme === 'https' ? 443 : 80);
        $pinned = str_contains($chosen, ':') ? '[' . $chosen . ']' : $chosen;
        return ['CURLOPT_RESOLVE' => [$host . ':' . $effectiveport . ':' . $pinned]];
    }

    /**
     * The parsed trustedhosts setting.
     *
     * One entry per line, scheme, host and an optional port; blank lines and
     * lines starting with # are ignored. A scheme or port in an entry must
     * match exactly; a missing one matches anything.
     *
     * @return array List of ['scheme' => ?string, 'host' => string, 'port' => ?int].
     */
    public static function trusted_hosts(): array {
        $raw = trim((string) get_config('mod_presenterai', 'trustedhosts'));
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!str_contains($line, '://')) {
                $line = '//' . $line;
            }
            $parts = parse_url($line);
            if (!$parts || empty($parts['host'])) {
                continue;
            }
            $out[] = [
                'scheme' => isset($parts['scheme']) ? strtolower($parts['scheme']) : null,
                'host' => strtolower(trim($parts['host'], '[]')),
                'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            ];
        }
        return $out;
    }

    /**
     * Whether an address is public: not private, loopback, link local,
     * reserved or multicast.
     *
     * @param string $ip An IPv4 or IPv6 address.
     * @return bool
     */
    public static function is_public_ip(string $ip): bool {
        $ip = trim($ip, '[]');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // An IPv4 mapped IPv6 address is judged by the IPv4 address inside it.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            return self::is_public_ip($m[1]);
        }
        foreach (self::DENIED_CIDRS as $cidr) {
            if (self::in_cidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The scheme, host and port of a URL, safe to put in a log line.
     *
     * @param string $url A URL.
     * @return string
     */
    public static function loggable(string $url): string {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return '(unparseable endpoint)';
        }
        return ($parts['scheme'] ?? '') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Split a URL into scheme, host and port.
     *
     * @param string $url A URL.
     * @return array|null [scheme, host, port|null], host lower case and
     *     without IPv6 brackets, or null when there is no host.
     */
    private static function parse(string $url): ?array {
        $parts = parse_url(trim($url));
        if (!$parts || empty($parts['host'])) {
            return null;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '') {
            return null;
        }
        return [strtolower($parts['scheme'] ?? ''), $host, isset($parts['port']) ? (int) $parts['port'] : null];
    }

    /**
     * Whether a scheme, host and port match a trustedhosts entry.
     *
     * @param string $scheme The URL's scheme.
     * @param string $host The URL's host, lower case.
     * @param int|null $port The URL's explicit port, if any.
     * @return bool
     */
    private static function host_is_trusted(string $scheme, string $host, ?int $port): bool {
        foreach (self::trusted_hosts() as $entry) {
            if ($entry['host'] !== $host) {
                continue;
            }
            if ($entry['scheme'] !== null && $entry['scheme'] !== $scheme) {
                continue;
            }
            if ($entry['port'] !== null && $entry['port'] !== $port) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Every address a name resolves to, IPv4 and IPv6.
     *
     * @param string $host A host name.
     * @return string[] Empty when resolution failed.
     */
    private static function resolve(string $host): array {
        if (self::$testresolver !== null) {
            return array_values((array) (self::$testresolver)($host));
        }
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return [self::TEST_PUBLIC_ADDRESS];
        }
        $addresses = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }
        if (function_exists('dns_get_record')) {
            $v6 = @dns_get_record($host, DNS_AAAA);
            if (is_array($v6)) {
                foreach ($v6 as $record) {
                    if (!empty($record['ipv6'])) {
                        $addresses[] = (string) $record['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($addresses));
    }

    /**
     * Whether an address falls inside a CIDR range of the same family.
     *
     * @param string $ip An address.
     * @param string $cidr A range such as 10.0.0.0/8 or fc00::/7.
     * @return bool
     */
    private static function in_cidr(string $ip, string $cidr): bool {
        [$subnet, $bits] = explode('/', $cidr);
        $ipbin = @inet_pton($ip);
        $subnetbin = @inet_pton($subnet);
        if ($ipbin === false || $subnetbin === false || strlen($ipbin) !== strlen($subnetbin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipbin, 0, $bytes) !== substr($subnetbin, 0, $bytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainder)) & 0xff;
        return (ord($ipbin[$bytes]) & $mask) === (ord($subnetbin[$bytes]) & $mask);
    }
}
