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

namespace mod_presenterai\local\vision;

/**
 * The deterministic summary shown when the model's summary can't be (design 6 b).
 *
 * Evidence existed and the visual criteria were scored, but the summary was
 * empty, would not parse, or the gate rejected it. The learner must not see a
 * hole and must not be told their camera failed, because it did not. So the
 * panel says something built only from the scores already in hand, banded on
 * each criterion's own max_score: at or above 80 percent is strong, below 50
 * percent is weak.
 *
 * None of these sentences claims to have seen anything, which is what makes a
 * template safe as the fallback and useless as the primary (D21).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fallback_template {
    /** @var float At or above this fraction of max_score a criterion is strong. */
    public const STRONG = 0.8;

    /** @var float Below this fraction of max_score a criterion is weak. */
    public const WEAK = 0.5;

    /**
     * The fallback text for one attempt.
     *
     * @param array $criteria The attempt's criteria in the B2 shape. Only those with
     *     'visual' true are read.
     * @return string Plain text.
     */
    public static function build(array $criteria): string {
        $visual = [];
        foreach ($criteria as $criterion) {
            if (is_array($criterion) && !empty($criterion['visual'])) {
                $visual[] = $criterion;
            }
        }

        $assessed = [];
        $partial = false;
        foreach ($visual as $criterion) {
            if (self::is_assessed($criterion)) {
                $assessed[] = $criterion;
            } else {
                $partial = true;
            }
        }

        $parts = [];
        if ($partial) {
            $parts[] = get_string('visual_fallback_partial', 'mod_presenterai');
        }
        // The bands each speak about two criteria. With fewer than two judged,
        // none of them is true, and the partial sentence says all there is.
        if (count($assessed) >= 2) {
            $parts[] = self::band($assessed[0], $assessed[1]);
        } else if (!$partial) {
            // No visual criteria at all reached here. Still never an empty panel.
            $parts[] = get_string('visual_fallback_partial', 'mod_presenterai');
        }

        return implode(' ', $parts);
    }

    /**
     * The band sentence for two assessed visual criteria.
     *
     * @param array $first The first visual criterion.
     * @param array $second The second visual criterion.
     * @return string
     */
    private static function band(array $first, array $second): string {
        $a = self::fraction($first);
        $b = self::fraction($second);

        if ($a >= self::STRONG && $b >= self::STRONG) {
            return get_string('visual_fallback_bothstrong', 'mod_presenterai');
        }
        if ($a < self::WEAK && $b < self::WEAK) {
            return get_string('visual_fallback_bothweak', 'mod_presenterai');
        }
        if (($a >= self::STRONG && $b < self::WEAK) || ($b >= self::STRONG && $a < self::WEAK)) {
            [$strong, $weak] = $a >= self::STRONG ? [$first, $second] : [$second, $first];
            return get_string('visual_fallback_onestrong', 'mod_presenterai', (object) [
                // The strong name sits mid sentence after "Your", the weak one
                // starts a sentence.
                'strong' => \core_text::strtolower(self::name($strong)),
                'weak' => self::name($weak),
            ]);
        }

        return get_string('visual_fallback_mixed', 'mod_presenterai');
    }

    /**
     * A criterion's score as a fraction of its own maximum.
     *
     * @param array $criterion A criterion.
     * @return float 0 when the maximum is not positive.
     */
    private static function fraction(array $criterion): float {
        $max = (int) ($criterion['max_score'] ?? 0);
        if ($max <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, ((int) ($criterion['score'] ?? 0)) / $max));
    }

    /**
     * Whether a criterion was assessed; an absent flag means assessed (B2).
     *
     * @param array $criterion A criterion.
     * @return bool
     */
    private static function is_assessed(array $criterion): bool {
        return !array_key_exists('assessed', $criterion) || !empty($criterion['assessed']);
    }

    /**
     * A criterion's name as plain text.
     *
     * @param array $criterion A criterion.
     * @return string
     */
    private static function name(array $criterion): string {
        return trim(strip_tags((string) ($criterion['name'] ?? '')));
    }
}
