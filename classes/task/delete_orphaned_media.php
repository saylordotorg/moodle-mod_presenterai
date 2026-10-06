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

namespace mod_presenterai\task;

use mod_presenterai\local\storage\store_factory;

/**
 * Retry deleting S3 objects whose activity has already been deleted.
 *
 * presenterai_delete_instance() deletes the media first and the rows second,
 * and a teacher deleting an activity must not be stopped by a bucket that is
 * briefly unreachable or a credential somebody cleared. So an object that
 * could not be deleted then is handed to this task, which holds the only
 * remaining record that the object exists (design 8.6, point 5). On the File
 * API core removes the context's files, so only S3 keys are ever queued.
 *
 * Custom data is {backend, keys}. A run that deletes some keys and not others
 * keeps only the ones still outstanding and fails, so core retries it with its
 * usual backoff and an administrator can see it on the ad hoc tasks page.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_orphaned_media extends \core\task\adhoc_task {
    /** @var int Most keys carried by one task, which keeps each run's custom data small. */
    public const KEYS_PER_TASK = 200;

    /**
     * Queue the keys that could not be deleted, a batch per task.
     *
     * @param string $backend A store_factory BACKEND_* name.
     * @param string[] $keys Stored keys.
     * @return void
     */
    public static function queue(string $backend, array $keys): void {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), fn($k) => $k !== '')));
        foreach (array_chunk($keys, self::KEYS_PER_TASK) as $batch) {
            $task = new self();
            $task->set_component('mod_presenterai');
            $task->set_custom_data((object) ['backend' => $backend, 'keys' => $batch]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * Name shown on the ad hoc tasks page.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskdeleteorphans', 'mod_presenterai');
    }

    /**
     * Delete what can be deleted, and fail with the rest so core tries again.
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();
        $backend = (string) ($data->backend ?? '');
        $keys = array_map('strval', (array) ($data->keys ?? []));
        if (empty($keys)) {
            return;
        }

        // Throws while the backend is unconfigured, which fails the run and
        // keeps every key for the next one.
        $store = store_factory::for_backend($backend);

        $left = [];
        foreach ($keys as $key) {
            if (!$store->delete($key)) {
                $left[] = $key;
            }
        }
        mtrace('PresenterAI orphaned media: ' . (count($keys) - count($left)) . ' deleted, ' . count($left) . ' still to do.');
        if ($left) {
            $this->set_custom_data((object) ['backend' => $backend, 'keys' => $left]);
            throw new \moodle_exception('error:deletefailed', 'mod_presenterai');
        }
    }
}
