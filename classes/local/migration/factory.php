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

/**
 * Builds everything the migration command uses: the SOLA reader, the runner, the rollback, the verifier and the
 * storage check.
 *
 * It's the command's only seam. The script passes this class; a test passes a subclass that hands out a source
 * double and a storage double. There's no command line option that swaps the reader, so nothing an operator types
 * can point the import at anything but the site's own SOLA tables.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class factory {
    /**
     * The reader of this site's SOLA tables.
     *
     * @return source_reader
     */
    public function reader(): source_reader {
        return new local_aica_reader();
    }

    /**
     * The importer.
     *
     * @param source_reader $reader The source.
     * @return runner
     */
    public function runner(source_reader $reader): runner {
        return new runner($reader);
    }

    /**
     * The rollback.
     *
     * @param source_reader $reader The source.
     * @return rollback
     */
    public function rollback(source_reader $reader): rollback {
        return new rollback($reader);
    }

    /**
     * The verifier, over this factory's storage check.
     *
     * @param source_reader $reader The source.
     * @return verifier
     */
    public function verifier(source_reader $reader): verifier {
        return new verifier($reader, $this->checker());
    }

    /**
     * The storage check: one HEAD per object against the site's S3 settings.
     *
     * @return object_checker
     */
    public function checker(): object_checker {
        return new s3_object_checker();
    }
}
