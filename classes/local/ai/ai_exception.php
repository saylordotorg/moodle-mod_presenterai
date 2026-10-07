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
 * A failed AI call, with a machine readable reason and a transient flag.
 *
 * The scoring task decides whether to retry from $transient alone, so the flag
 * is set here, in one place, from the reason, and no caller can disagree with
 * it. Transient means "the same request may well work later": a rate limit,
 * a 429, a 5xx, a timeout or a network failure. Everything else, a refusal, a
 * malformed reply, a 4xx, an unsafe endpoint, a missing key, will fail the
 * same way on every retry and so is not transient.
 *
 * debuginfo carries the provider's own error message, truncated, and never a
 * key: the clients redact their key from anything they put here.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_exception extends \moodle_exception {
    /** @var string No key, model or endpoint is configured for the route. */
    public const NOT_CONFIGURED = 'not_configured';

    /** @var string The endpoint failed the SSRF check. */
    public const UNSAFE_ENDPOINT = 'unsafe_endpoint';

    /** @var string PresenterAI's own rate limiter refused the call. */
    public const RATE_LIMITED = 'rate_limited';

    /** @var string The provider answered 429. */
    public const HTTP_429 = 'http_429';

    /** @var string The provider answered 5xx or 529. */
    public const HTTP_5XX = 'http_5xx';

    /** @var string The provider answered some other 4xx. */
    public const HTTP_4XX = 'http_4xx';

    /** @var string The request timed out. */
    public const TIMEOUT = 'timeout';

    /** @var string The connection failed for any other transport reason. */
    public const NETWORK = 'network';

    /** @var string The model declined the request. */
    public const REFUSAL = 'refusal';

    /** @var string The reply could not be understood. */
    public const BAD_RESPONSE = 'bad_response';

    /** @var string Moodle core AI is missing, disabled, or has no provider. */
    public const CORE_DISABLED = 'core_disabled';

    /** @var string[] The reasons worth retrying. */
    public const TRANSIENT_REASONS = [
        self::RATE_LIMITED, self::HTTP_429, self::HTTP_5XX, self::TIMEOUT, self::NETWORK,
    ];

    /** @var string Why the call failed, one of the constants above. */
    public readonly string $reason;

    /** @var bool Whether the same call may succeed later. */
    public readonly bool $transient;

    /**
     * Build the exception.
     *
     * @param string $reason One of the reason constants.
     * @param bool $transient Whether a retry may succeed. Callers pass
     *     self::is_transient_reason($reason) unless they know better.
     * @param string $debuginfo Diagnostic detail for developers; never a key.
     */
    public function __construct(string $reason, bool $transient, string $debuginfo = '') {
        $this->reason = $reason;
        $this->transient = $transient;
        parent::__construct('error:aicall', 'mod_presenterai', '', $reason, $debuginfo);
    }

    /**
     * Build an exception whose transient flag follows from its reason.
     *
     * @param string $reason One of the reason constants.
     * @param string $debuginfo Diagnostic detail; never a key.
     * @return self
     */
    public static function for_reason(string $reason, string $debuginfo = ''): self {
        return new self($reason, self::is_transient_reason($reason), $debuginfo);
    }

    /**
     * Whether a reason is one worth retrying.
     *
     * @param string $reason One of the reason constants.
     * @return bool
     */
    public static function is_transient_reason(string $reason): bool {
        return in_array($reason, self::TRANSIENT_REASONS, true);
    }
}
