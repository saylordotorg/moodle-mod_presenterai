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

namespace mod_presenterai\local\storage;

/**
 * Everything a storage backend needs to locate one piece of media.
 *
 * A bare string key is enough for S3 and not enough for Moodle's File API,
 * which addresses a file by six coordinates rather than one. Passing a ref
 * rather than a key means fs_store never has to reverse engineer a context out
 * of a string, which is the shape that would have quietly forced the interface
 * to be S3's interface with the File API bent to fit.
 *
 * Immutable on purpose. A ref is a question about where something lives, and a
 * caller that could edit one mid-flight would be asking two different questions
 * with the same object.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class media_ref {
    /** @var string The learner's recording itself. */
    public const KIND_RECORDING = 'recording';

    /** @var string An uploaded PDF slide deck. */
    public const KIND_DECK = 'deck';

    /** @var string The contact sheet of stills sampled for visual feedback. */
    public const KIND_FRAMES = 'frames';

    /**
     * Build a reference to one piece of media.
     *
     * @param int $recordingid The attempt this belongs to. Zero only during
     *        begin_upload, before the row exists.
     * @param int $contextid The module context id.
     * @param int $courseid The course id.
     * @param int $userid The learner who owns the media.
     * @param string $kind One of the KIND_* constants.
     * @param string $ext File extension without the dot, e.g. webm, mp4, m4a, pdf, jpg.
     * @param string $key Backend-defined and opaque to callers. Empty until the
     *        backend mints one. Stored on the recording row.
     */
    public function __construct(
        /** @var int */
        public readonly int $recordingid,
        /** @var int */
        public readonly int $contextid,
        /** @var int */
        public readonly int $courseid,
        /** @var int */
        public readonly int $userid,
        /** @var string */
        public readonly string $kind,
        /** @var string */
        public readonly string $ext,
        /** @var string */
        public readonly string $key = '',
    ) {
    }

    /**
     * The same ref with a key attached.
     *
     * Used once, when a backend mints a key during begin_upload. Returns a new
     * instance rather than mutating, so a ref handed to two callers cannot
     * change under one of them.
     *
     * @param string $key The minted key.
     * @return self
     */
    public function with_key(string $key): self {
        return new self(
            $this->recordingid,
            $this->contextid,
            $this->courseid,
            $this->userid,
            $this->kind,
            $this->ext,
            $key,
        );
    }

    /**
     * The same ref bound to a recording id.
     *
     * begin_upload runs before the row exists, so the ref it is given carries
     * recordingid 0. This attaches the id once the row is created.
     *
     * @param int $recordingid The new recording id.
     * @return self
     */
    public function with_recording(int $recordingid): self {
        return new self(
            $recordingid,
            $this->contextid,
            $this->courseid,
            $this->userid,
            $this->kind,
            $this->ext,
            $this->key,
        );
    }

    /**
     * Whether this ref names a kind the plugin knows.
     *
     * Called by the backends before minting a key, so a typo becomes an
     * exception at the boundary rather than an object filed under a directory
     * nothing will ever look in.
     *
     * @return bool
     */
    public function has_valid_kind(): bool {
        return in_array($this->kind, [self::KIND_RECORDING, self::KIND_DECK, self::KIND_FRAMES], true);
    }
}
