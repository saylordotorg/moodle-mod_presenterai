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
use mod_presenterai\local\storage\store_factory;

/**
 * The site-wide preconditions of a Soapbox import (IMPLEMENTATION-PLAN 7.1), checked into a plan. Reads only.
 *
 * Split out of runner so the runner keeps the order of operations and this keeps the checks.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preconditions {
    /**
     * Check every site-wide precondition into a plan. With no Soapbox tables nothing else is asked of the reader.
     *
     * Codes, in order: sourcetables, backend, storage, interlock, scoringqueued. A met check carries no message.
     *
     * @param source_reader $reader The source.
     * @param plan $plan The plan.
     */
    public static function check(source_reader $reader, plan $plan): void {
        if (!$reader->tables_present()) {
            $plan->precondition('sourcetables', false, self::str('precondition_sourcetables'));
            return;
        }
        $plan->precondition('sourcetables', true);

        $backend = store_factory::default_backend();
        $met = $backend === store_factory::BACKEND_S3;
        $plan->precondition('backend', $met, $met ? '' : self::str('precondition_backend', $backend));

        $sola = $reader->storage_settings();
        $sola = ['bucket' => trim((string) ($sola['bucket'] ?? '')), 'region' => trim((string) ($sola['region'] ?? '')),
            'prefix' => trim((string) ($sola['prefix'] ?? ''))];
        $own = self::own_storage();
        $plan->precondition('storage', $sola === $own, $sola === $own ? '' : self::str('precondition_storage', (object) [
            'sola' => self::str('storagevalues', (object) $sola),
            'presenterai' => self::str('storagevalues', (object) $own),
        ]));

        $met = $reader->interlock_present();
        $plan->precondition('interlock', $met, $met ? '' : self::str('precondition_interlock'));
        $queued = $reader->queued_scoring_task_count();
        $plan->precondition('scoringqueued', $queued === 0, $queued === 0 ? '' : self::str('precondition_scoringqueued', $queued));
    }

    /**
     * This plugin's S3 bucket, region and prefix, read raw and normalised as s3_store reads them. Never a key.
     *
     * @return array With bucket, region and prefix.
     */
    public static function own_storage(): array {
        $setting = static fn(string $name): string => trim((string) get_config('mod_presenterai', 's3' . $name));
        $region = $setting('region');

        return [
            'bucket' => $setting('bucket'),
            'region' => $region !== '' ? $region : s3_store::DEFAULT_REGION,
            'prefix' => s3_store::normalise_prefix($setting('prefix')),
        ];
    }

    /**
     * One of the migration strings.
     *
     * @param string $name The name after "migration:".
     * @param mixed $a The string's argument.
     * @return string
     */
    private static function str(string $name, $a = null): string {
        return get_string('migration:' . $name, 'mod_presenterai', $a);
    }
}
