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
 * Which AI back end serves which step, decided at call time (plan 5.3, D6).
 *
 * The airoute setting is auto, core, claude, openai, gemini or compatible.
 *
 * Scoring under auto takes the first of: a Claude key, an OpenAI key, a
 * Gemini key, a compatible endpoint with a model, Moodle core AI with
 * generate_text available. Vision, slide vision and the summary judge use the
 * same order without core, because core AI cannot take an image (plan 5.1)
 * and the judge must be the plugin's own call; when airoute is core they
 * therefore fall back to that keyed order too, which is how a core site that
 * also has a key gets body language feedback. An explicit route that is not
 * configured resolves to null: no silent fallback to another vendor that the
 * admin did not choose.
 *
 * Transcription is separate and never core: the sttendpoint setting with its
 * own key when set, otherwise OpenAI's endpoint with the OpenAI key, otherwise
 * none. A site with scoring and no transcription cannot score anything, so
 * accepts_recordings() refuses new attempts there rather than letting each
 * one fail at scoring time (plan 5.1).
 *
 * Tests replace any of this with set_test_client() and set_test_stt(); the
 * doubles drive ai_ready(), accepts_recordings() and scoring_route() too.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class route_resolver {
    /** @var string Scoring a transcript. */
    public const PURPOSE_SCORE = 'score';

    /** @var string The body language vision pass. */
    public const PURPOSE_VISION = 'vision';

    /** @var string The slide vision pass. */
    public const PURPOSE_SLIDE_VISION = 'slide_vision';

    /** @var string The visual summary judge. */
    public const PURPOSE_JUDGE = 'judge';

    /** @var string[] Every route name, auto first. */
    public const ROUTES = ['auto', 'core', 'claude', 'openai', 'gemini', 'compatible'];

    /** @var string[] The order auto tries the keyed routes in. */
    private const KEYED_ORDER = ['claude', 'openai', 'gemini', 'compatible'];

    /** @var array Purpose => client double (or null), tests only. */
    private static array $testclients = [];

    /** @var bool Whether an stt double is installed. */
    private static bool $hasteststt = false;

    /** @var stt_interface|null The stt double. */
    private static ?stt_interface $teststt = null;

    /**
     * The client for a purpose, or null when none is configured.
     *
     * @param string $purpose One of the PURPOSE_ constants.
     * @return client_interface|null
     */
    public static function client_for(string $purpose): ?client_interface {
        if (array_key_exists($purpose, self::$testclients)) {
            return self::$testclients[$purpose];
        }
        $judge = $purpose === self::PURPOSE_JUDGE;
        $route = self::configured_route();

        if ($purpose === self::PURPOSE_SCORE) {
            if ($route === 'core') {
                return core_ai_client::is_available() ? new core_ai_client() : null;
            }
            if ($route !== 'auto') {
                return self::build($route, false);
            }
            foreach (self::KEYED_ORDER as $candidate) {
                $client = self::build($candidate, false);
                if ($client !== null) {
                    return $client;
                }
            }
            return core_ai_client::is_available() ? new core_ai_client() : null;
        }

        if ($route !== 'auto' && $route !== 'core') {
            return self::build($route, $judge);
        }
        foreach (self::KEYED_ORDER as $candidate) {
            $client = self::build($candidate, $judge);
            if ($client !== null) {
                return $client;
            }
        }
        return null;
    }

    /**
     * The route scoring resolves to, or '' when none.
     *
     * @return string
     */
    public static function scoring_route(): string {
        $client = self::client_for(self::PURPOSE_SCORE);
        return $client === null ? '' : $client->route();
    }

    /**
     * The transcription client, or null when none is configured.
     *
     * @return stt_interface|null
     */
    public static function stt(): ?stt_interface {
        if (self::$hasteststt) {
            return self::$teststt;
        }
        $model = trim((string) get_config('mod_presenterai', 'sttmodel'));
        $endpoint = trim((string) get_config('mod_presenterai', 'sttendpoint'));
        if ($endpoint !== '') {
            return new stt_client($endpoint, secrets::get('sttapikey'), $model);
        }
        $openaikey = secrets::get('openaiapikey');
        if ($openaikey !== '') {
            return new stt_client(stt_client::OPENAI_ENDPOINT, $openaikey, $model);
        }
        return null;
    }

    /**
     * Whether a finished recording can be transcribed and scored.
     *
     * @return bool
     */
    public static function ai_ready(): bool {
        return self::client_for(self::PURPOSE_SCORE) !== null && self::stt() !== null;
    }

    /**
     * Whether the activity should take new recordings.
     *
     * False only in the one broken state: scoring is set up and transcription
     * is not, so every recording would fail. With no AI at all the activity
     * is a manually graded one and takes recordings as phase 2 did.
     *
     * @return bool
     */
    public static function accepts_recordings(): bool {
        return !(self::client_for(self::PURPOSE_SCORE) !== null && self::stt() === null);
    }

    /**
     * What is configured and what is missing, for the settings page.
     *
     * @return array ['scoring' => bool, 'transcription' => bool,
     *     'vision' => bool, 'route' => string, 'messages' => string[]]
     */
    public static function readiness(): array {
        $scoring = self::client_for(self::PURPOSE_SCORE);
        $stt = self::stt();
        $vision = self::client_for(self::PURPOSE_VISION);
        // Design 3.6: with scoring on core AI, visual_pipeline never analyses
        // frames, because the note would go into a prompt core keeps forever.
        // The vision client can still serve slide vision, but body language
        // feedback is off, and the page should say so.
        $corescoring = $scoring !== null && $scoring->route() === 'core';
        $configured = self::configured_route();
        $messages = [];

        if ($scoring !== null) {
            $messages[] = get_string(
                'aireadiness_scoring',
                'mod_presenterai',
                get_string('airoute_' . $scoring->route(), 'mod_presenterai')
            );
        } else if ($configured === 'auto') {
            $messages[] = get_string('aireadiness_noscoring', 'mod_presenterai');
        } else {
            $messages[] = get_string(
                'aireadiness_routeunconfigured',
                'mod_presenterai',
                get_string('airoute_' . $configured, 'mod_presenterai')
            );
        }
        $messages[] = $stt !== null
            ? get_string('aireadiness_transcription', 'mod_presenterai')
            : get_string('aireadiness_notranscription', 'mod_presenterai');
        if ($corescoring) {
            $messages[] = get_string('aireadiness_novisioncore', 'mod_presenterai');
        } else {
            $messages[] = $vision !== null
                ? get_string(
                    'aireadiness_vision',
                    'mod_presenterai',
                    get_string('airoute_' . $vision->route(), 'mod_presenterai')
                )
                : get_string('aireadiness_novision', 'mod_presenterai');
        }
        if ($scoring !== null && $stt === null) {
            $messages[] = get_string('aireadiness_blocked', 'mod_presenterai');
        }

        return [
            'scoring' => $scoring !== null,
            'transcription' => $stt !== null,
            'vision' => $vision !== null && !$corescoring,
            'route' => $scoring === null ? '' : $scoring->route(),
            'messages' => $messages,
        ];
    }

    /**
     * Install a client double for a purpose (tests only). Null makes the
     * purpose resolve to no client.
     *
     * @param string $purpose One of the PURPOSE_ constants.
     * @param client_interface|null $client The double, or null.
     * @return void
     */
    public static function set_test_client(string $purpose, ?client_interface $client): void {
        self::require_unit_test();
        self::$testclients[$purpose] = $client;
    }

    /**
     * Install a transcription double (tests only). Null makes transcription
     * resolve to none.
     *
     * @param stt_interface|null $stt The double, or null.
     * @return void
     */
    public static function set_test_stt(?stt_interface $stt): void {
        self::require_unit_test();
        self::$hasteststt = true;
        self::$teststt = $stt;
    }

    /**
     * Remove every double.
     *
     * @return void
     */
    public static function reset_test_doubles(): void {
        self::require_unit_test();
        self::$testclients = [];
        self::$hasteststt = false;
        self::$teststt = null;
    }

    /**
     * The airoute setting, defaulting to auto.
     *
     * @return string
     */
    private static function configured_route(): string {
        $route = (string) get_config('mod_presenterai', 'airoute');
        return in_array($route, self::ROUTES, true) ? $route : 'auto';
    }

    /**
     * Build a keyed route's client, or null when it is not configured.
     *
     * @param string $route claude, openai, gemini or compatible.
     * @param bool $judge Whether to use the route's judge model.
     * @return client_interface|null
     */
    private static function build(string $route, bool $judge): ?client_interface {
        switch ($route) {
            case 'claude':
                $key = secrets::get('claudeapikey');
                $model = self::model('claude', $judge, 'claude-sonnet-5-5', 'claude-haiku-4-5');
                return $key === '' ? null : new claude_client($key, $model);
            case 'openai':
                $key = secrets::get('openaiapikey');
                $model = self::model('openai', $judge, 'gpt-4o-mini', 'gpt-4o-mini');
                return $key === '' ? null : new openai_client($key, $model);
            case 'gemini':
                $key = secrets::get('geminiapikey');
                $model = self::model('gemini', $judge, 'gemini-2.5-flash', 'gemini-2.5-flash');
                return $key === '' ? null : new gemini_client($key, $model);
            case 'compatible':
                $endpoint = trim((string) get_config('mod_presenterai', 'compatibleendpoint'));
                $model = trim((string) get_config('mod_presenterai', 'compatiblemodel'));
                if ($judge) {
                    $judgemodel = trim((string) get_config('mod_presenterai', 'compatiblejudgemodel'));
                    $model = $judgemodel !== '' ? $judgemodel : $model;
                }
                if ($endpoint === '' || $model === '') {
                    return null;
                }
                return new openai_client(secrets::get('compatibleapikey'), $model, $endpoint, 'compatible');
        }
        return null;
    }

    /**
     * A route's configured model, or its default when the setting is blank.
     *
     * @param string $route claude, openai or gemini.
     * @param bool $judge Whether the judge model is wanted.
     * @param string $default The default scoring and vision model.
     * @param string $judgedefault The default judge model.
     * @return string
     */
    private static function model(string $route, bool $judge, string $default, string $judgedefault): string {
        $value = trim((string) get_config('mod_presenterai', $route . ($judge ? 'judgemodel' : 'model')));
        return $value !== '' ? $value : ($judge ? $judgedefault : $default);
    }

    /**
     * Refuse a test seam outside unit tests.
     *
     * @return void
     */
    private static function require_unit_test(): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('route_resolver test doubles are for unit tests only');
        }
    }
}
