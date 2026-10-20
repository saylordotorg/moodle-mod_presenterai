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

namespace mod_presenterai\local\migration;

use mod_presenterai\local\storage\s3_store;

/**
 * The storage check against the site's own S3 settings, one signed HEAD per object.
 *
 * Thin on purpose: it asks s3_store::head() and turns the answer into a result. A 200 is there (and the size is
 * compared when both sides know it), a 404 is missing, and anything else is an error that carries the HTTP status
 * alone. The import's preconditions already made PresenterAI's bucket, region and prefix equal SOLA's, so the
 * site's own store reads the objects SOLA wrote. Exercised for real only on dev; the HEAD is a seam so the mapping
 * is tested without the network.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class s3_object_checker implements object_checker {
    /** @var \Closure Key to ['status' => int, 'bytes' => int|null]. */
    private \Closure $head;

    /**
     * A checker over the site's S3 store, or over a HEAD a test supplies.
     *
     * @param \Closure|null $head Takes a key and returns what s3_store::head() returns; the site's store when null.
     */
    public function __construct(?\Closure $head = null) {
        if ($head === null) {
            $store = new s3_store();
            $head = static fn(string $key): array => $store->head($key);
        }
        $this->head = $head;
    }

    /**
     * Whether the object exists and, when a size is expected and S3 reports one, has that size.
     *
     * @param string $key The key read from the destination row.
     * @param int $bytes The size expected; 0 or less means existence alone.
     * @return bool
     * @throws \RuntimeException For any answer but 200 or 404, with the status as its code.
     */
    public function exists_with_size(string $key, int $bytes): bool {
        $head = ($this->head)($key);
        $status = (int) ($head['status'] ?? 0);
        if ($status === 404) {
            return false;
        }
        if ($status !== 200) {
            throw new \RuntimeException(\mod_presenterai\local\prompt_text::get('error:storagecheckstatus', $status), $status);
        }
        $reported = $head['bytes'] ?? null;

        return $bytes <= 0 || $reported === null || (int) $reported === $bytes;
    }
}
