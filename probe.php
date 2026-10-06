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

/**
 * Storage check: both backends' self tests and the upload chunk ladder.
 *
 * The page runs selftest() for Moodle file storage always, and for S3 when it
 * is configured or when any recording still lives there, because a site that
 * switched away from S3 still has to reach those recordings (plan 4.7). It
 * says in words that the bucket's lifecycle rule cannot be read.
 *
 * The chunk ladder (D19) runs in the browser, because the limit that bites is
 * usually a reverse proxy in front of PHP that no ini_get() can see. It POSTs
 * real bodies of increasing size to action=chunk and saves the largest that
 * came back whole as fschunkbytes. The uploader halves its chunk on a 413, so
 * a site that never runs this still works, only with more requests.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_presenterai\local\storage\store_factory;

/** @var int Largest body action=chunk counts before it stops, a little above the top rung. */
const MOD_PRESENTERAI_PROBE_READ_LIMIT = 6291456;

/** @var int The rungs a chunk size may be saved from, smallest. */
const MOD_PRESENTERAI_PROBE_MIN = 65536;

/** @var int The rungs a chunk size may be saved from, largest. This is fs_store's own cap. */
const MOD_PRESENTERAI_PROBE_MAX = 5242880;

require_login();
require_admin();

$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/mod/presenterai/probe.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('probe', 'mod_presenterai'));
$PAGE->set_heading(get_string('probe', 'mod_presenterai'));

if ($action === 'chunk' || $action === 'save') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'method']);
        die();
    }
    require_sesskey();
    \core\session\manager::write_close();

    if ($action === 'chunk') {
        // Count, never keep. Reading in small pieces means a body far larger
        // than memory_limit costs nothing, and stopping past the top rung means
        // a hostile body costs no more than one large chunk.
        $received = 0;
        $in = fopen('php://input', 'rb');
        if ($in !== false) {
            while (!feof($in) && $received <= MOD_PRESENTERAI_PROBE_READ_LIMIT) {
                $piece = fread($in, 8192);
                if ($piece === false || $piece === '') {
                    break;
                }
                $received += strlen($piece);
            }
            fclose($in);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['received' => $received]);
        die();
    }

    $chunkbytes = required_param('chunkbytes', PARAM_INT);
    $valid = $chunkbytes >= MOD_PRESENTERAI_PROBE_MIN && $chunkbytes <= MOD_PRESENTERAI_PROBE_MAX
        && $chunkbytes % MOD_PRESENTERAI_PROBE_MIN === 0;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$valid) {
        http_response_code(400);
        echo json_encode(['error' => 'badrequest']);
        die();
    }
    set_config('fschunkbytes', $chunkbytes, 'mod_presenterai');
    echo json_encode(['saved' => $chunkbytes]);
    die();
}

/**
 * Template context for one backend's self test.
 *
 * @param string $name Backend machine name.
 * @param array $steps Ordered ['step', 'ok', 'detail'] entries from selftest(), optionally with 'info'
 *                     for a step that was not tested.
 * @param bool $configured Whether the backend reports itself configured.
 * @return array
 */
function mod_presenterai_probe_arm(string $name, array $steps, bool $configured): array {
    $rows = [];
    foreach ($steps as $step) {
        $key = 'probe_step_' . preg_replace('/[^a-z0-9]/', '', (string) $step['step']);
        $rows[] = [
            'step' => (string) $step['step'],
            'label' => get_string_manager()->string_exists($key, 'mod_presenterai')
                ? get_string($key, 'mod_presenterai') : (string) $step['step'],
            'ok' => (bool) $step['ok'],
            'info' => !empty($step['info']),
            'detail' => (string) $step['detail'],
        ];
    }

    return [
        'name' => $name,
        'label' => get_string('backend' . $name, 'mod_presenterai'),
        'configured' => $configured,
        'steps' => $rows,
    ];
}

$arms = [];
$fs = store_factory::unchecked_store(store_factory::BACKEND_FS);
$arms[] = mod_presenterai_probe_arm(store_factory::BACKEND_FS, $fs->selftest(), true);

$s3 = store_factory::unchecked_store(store_factory::BACKEND_S3);
$s3rows = $DB->record_exists('presenterai_recording', ['backend' => store_factory::BACKEND_S3]);
if ($s3->is_configured() || $s3rows) {
    $steps = $s3->selftest();
    // Always last and always in words: a green self test is not evidence that
    // the bucket keeps what the plugin believes it keeps (design 8.6).
    // Marked info, so it reads "Not checked" rather than "Passed" beside a
    // sentence saying it could not be checked.
    $steps[] = [
        'step' => 'lifecycle',
        'ok' => true,
        'info' => true,
        'detail' => get_string('probe_nolifecycle', 'mod_presenterai'),
    ];
    $arms[] = mod_presenterai_probe_arm(store_factory::BACKEND_S3, $steps, $s3->is_configured());
}

$counts = [];
$rows = $DB->get_records_sql(
    "SELECT backend, COUNT(1) AS total
       FROM {presenterai_recording}
      WHERE storagekey IS NOT NULL
   GROUP BY backend
   ORDER BY backend"
);
foreach ($rows as $row) {
    $label = get_string_manager()->string_exists('backend' . $row->backend, 'mod_presenterai')
        ? get_string('backend' . $row->backend, 'mod_presenterai') : (string) $row->backend;
    $counts[] = [
        'text' => get_string('probe_count', 'mod_presenterai', (object) ['label' => $label, 'count' => (int) $row->total]),
    ];
}

$saved = (int) get_config('mod_presenterai', 'fschunkbytes');
$rungs = [];
foreach ([524288, 1048576, 2097152, MOD_PRESENTERAI_PROBE_MAX] as $bytes) {
    $rungs[] = ['bytes' => $bytes, 'label' => display_size($bytes)];
}

$context = [
    'arms' => $arms,
    'hascounts' => !empty($counts),
    'counts' => $counts,
    'chunksaved' => $saved > 0 ? display_size($saved) : '',
    'hassaved' => $saved > 0,
    'probeurl' => (new moodle_url('/mod/presenterai/probe.php'))->out(false),
    'rungs' => json_encode($rungs),
];

$PAGE->requires->js_call_amd('mod_presenterai/probe', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_presenterai/probe', $context);
echo $OUTPUT->footer();
