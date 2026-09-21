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
 * English strings for mod_presenterai.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'PresenterAI';
$string['modulename'] = 'PresenterAI';
$string['modulenameplural'] = 'PresenterAI activities';
$string['modulename_help'] = 'PresenterAI asks a learner to record a spoken presentation, video or audio only, optionally alongside slides they advance while speaking. The recording is transcribed and scored against a rubric, and the learner reads written feedback on each criterion.

Recordings can be stored in Moodle\'s own file storage or in an S3-compatible bucket, and can be kept until someone removes them or deleted automatically after a set number of days.';
$string['pluginadministration'] = 'PresenterAI administration';

// Instance form.
$string['presenterainame'] = 'Activity name';
$string['presenterainame_help'] = 'The name learners see in the course.';

// Capabilities.
$string['presenterai:addinstance'] = 'Add a new PresenterAI activity';
$string['presenterai:view'] = 'View a PresenterAI activity';
$string['presenterai:submit'] = 'Record and submit a presentation';
$string['presenterai:downloadown'] = 'Download your own recording';
$string['presenterai:viewallattempts'] = 'View other people\'s recordings';
$string['presenterai:grade'] = 'Grade presentations';
$string['presenterai:deleteanyrecording'] = 'Delete any recording';
$string['presenterai:managerubrics'] = 'Manage scoring rubrics';
$string['presenterai:useai'] = 'Have AI feedback generated for your attempts';

// Index page.
$string['nopresenterais'] = 'There are no PresenterAI activities in this course.';

// Privacy. Declared now so the strings exist before the provider is written,
// and so nobody is tempted to ship a provider whose reasons are only in code.
$string['privacy:metadata:presenterai_recording'] = 'A recorded presentation attempt: the media reference, its length, its state and the transcript produced from it.';
$string['privacy:metadata:presenterai_recording:userid'] = 'The learner who made the recording.';
$string['privacy:metadata:presenterai_recording:transcript'] = 'The text transcribed from the recording. It is kept after the media is deleted, because it is the learner\'s record of what they said.';
$string['privacy:metadata:presenterai_score'] = 'One scored judgement of one attempt, by AI or by a teacher, with the per-criterion marks and written feedback.';
$string['privacy:metadata:presenterai_score:userid'] = 'The learner whose attempt was scored.';

// Site settings: storage.
$string['storageheading'] = 'Storage';
$string['storageheading_desc'] = 'Where recordings are kept. Each recording remembers the storage it was written to, so changing the backend applies to recordings made from now on and moves nothing that already exists. Recordings on the other backend keep working, which also means the settings for a backend must stay filled in for as long as any recording still uses it.';
$string['backend'] = 'Storage backend';
$string['backend_desc'] = 'Moodle file storage needs no configuration and lets Moodle do the work: backup and restore carry the media, deleting a course deletes it, and privacy requests export and delete it through the standard file handling. S3 keeps the media in a bucket you own and lets the browser upload straight to it, so the media never passes through the web server.

Switching is not a migration. No recording moves and none stops working. Do not clear the S3 settings while recordings made on S3 still exist, because the plugin would then be unable to reach them, including to delete them.';
$string['backendfs'] = 'Moodle file storage';
$string['backends3'] = 'S3 compatible bucket';
$string['s3bucket'] = 'Bucket';
$string['s3bucket_desc'] = 'The bucket recordings are written to. It must block public access, and it needs a CORS rule allowing PUT and GET from this site, because the browser uploads to it directly. The storage self test checks both, and says so in words: a missing CORS rule otherwise shows up only as an unexplained browser error after a learner has finished speaking.';
$string['s3region'] = 'Region';
$string['s3region_desc'] = 'The region the bucket is in, for example us-east-1. It forms part of the request signature, so a wrong region fails every upload rather than being slow.';
$string['s3key'] = 'Access key ID';
$string['s3key_desc'] = 'The access key the plugin signs requests with. It needs permission to read, write and delete objects under the prefix below, and nothing else.';
$string['s3secret'] = 'Secret access key';
$string['s3secret_desc'] = 'The secret for the access key above. It is stored in the Moodle database and is not written to the configuration change log.';
$string['s3prefix'] = 'Key prefix';
$string['s3prefix_desc'] = 'Every object this plugin writes goes under this prefix, and the plugin will only read and delete objects under it. A site moving from the Soapbox activity in the AI Course Assistant plugin sets this to the prefix its existing objects are already under, so the migration stays metadata only and no bytes are copied.';
$string['s3endpoint'] = 'Endpoint';
$string['s3endpoint_desc'] = 'Leave this empty for Amazon S3. For another S3 compatible service, give the full endpoint URL including the scheme, for example https://minio.example.org:9000.';
$string['s3pathstyle'] = 'Use path style addressing';
$string['s3pathstyle_desc'] = 'Address an object as endpoint/bucket/key instead of bucket.endpoint/key. Amazon S3 does not need this. Most self hosted S3 compatible services, including MinIO, do.';
$string['s3lifecycledays'] = 'Bucket lifecycle rule age (days)';
$string['s3lifecycledays_desc'] = 'If the bucket has a lifecycle rule that expires objects under the prefix above, enter the rule\'s age in days. 0 means there is no rule, or that you do not know of one.

This is a declaration by you, not something the plugin has checked. Reading a lifecycle rule needs a permission this plugin does not ask for, and the rule can be changed in the AWS console at any time without the plugin knowing, so a value read once and shown here would look like a guarantee and would not be one.

It matters because a rule shorter than the retention setting below deletes recordings that the database still believes exist. When this is set, learners are not told that a recording is kept until someone removes it, because the bucket is going to remove it.';

// Site settings: retention and download.
$string['retentionheading'] = 'Retention and download';
$string['retentionheading_desc'] = 'How long a recording\'s media is kept, and whether a learner may keep a copy of their own. A recording\'s score, written feedback and transcript are kept regardless: they belong to the learner\'s record and are not deleted when the media is.';
$string['retentiondays'] = 'Delete recordings after (days)';
$string['retentiondays_desc'] = 'The number of days after a recording is finished before its media is deleted automatically. 0 means there is no automatic deletion and the media is kept until somebody removes it. There is no upper limit, and 1 day is the shortest window that deletes anything.

<strong>Changing this affects recordings made from now on. Recordings that already exist keep the deletion date they already have, and recordings that have no deletion date do not acquire one.</strong> That is deliberate and it works in both directions. Turning automatic deletion on does not put a year of existing recordings on a clock that fires at the next cron run. Turning it off does not cancel the deletion dates that learners have already been shown, so those recordings are still deleted on the dates they were promised.

To change recordings that already exist, use the retention tool at cli/apply_retention.php, which shows you what it would do before it does anything and states how many learners were told their recording would be kept.

An activity can set its own retention, which overrides this value.

A window shorter than a few days is worth thinking about twice: scoring runs on cron, and a backlog on a busy site can mean the media is deleted before it has been transcribed.';
$string['retentionoverridenote'] = '{$a} activities on this site set their own retention and are not affected by changes to the value above.';
$string['deletewarndays'] = 'Warn learners this many days ahead';
$string['deletewarndays_desc'] = 'How many days before its deletion date a learner is told that their recording is about to be deleted. 0 sends no advance message. This does nothing for a recording that has no deletion date, so on a site with automatic deletion off it has no effect at all.';
$string['allowlearnerdownload'] = 'Learners may download their own recording';
$string['allowlearnerdownload_desc'] = 'Whether a learner can save a copy of a recording they made. This is the site wide switch; a learner also needs the mod/presenterai:downloadown capability.

It does not affect teachers, graders or managers, who download other people\'s recordings under a separate capability. One setting cannot express both "learners may not circulate recordings" and "a grader may not take evidence to a moderation meeting", so it only means the first.

How quickly turning this off takes effect depends on the storage backend. On Moodle file storage the check runs on every request, so it is immediate. On S3 the check runs when the download link is signed, and a signed link keeps working for about fifteen minutes after that no matter who is holding it, so turning this off leaves a window of that length.';
$string['retentionwithoutdownloadnote'] = '<strong>Check this combination.</strong> Recordings are deleted automatically and learners cannot download their own, so a learner\'s presentation is destroyed on a schedule and they were never able to keep a copy of it. That is a reasonable policy if it is the one you meant. If it is not, either set the deletion window to 0 or allow learners to download their own recording.';

// Storage errors.
$string['errorunknownbackend'] = 'Unknown storage backend "{$a}". This site is asking for a storage backend the plugin does not have, either because the setting holds a value nothing recognises or because a recording was made by a newer version of the plugin.';
$string['errorstorenotconfigured'] = 'The "{$a}" storage backend is selected but is not fully configured, so the plugin has refused to read or write media rather than quietly using the other backend. Fill in the missing settings in Site administration, or, if recordings are still stored there, restore the settings they were made with.';

// Storage: chunked upload through the Moodle File API.
$string['error:stagingunwritable'] = 'The upload could not be written to temporary storage. Check that the Moodle data directory is writable.';
$string['error:uploadid'] = 'That upload id is not one this site issued.';
$string['error:uploadbusy'] = 'Another part of this upload is still being written. Try again in a moment.';
$string['error:chunkoffset'] = 'This part of the upload starts at byte {$a->claimed}, but {$a->actual} bytes have been received. Resume from byte {$a->actual}.';
$string['error:chunkread'] = 'The upload was interrupted while it was being read.';

// Storage self test.
$string['selftest:staging'] = 'Wrote and read back a staging file in {$a}.';
$string['selftest:lock'] = 'Locking is available, using {$a}.';
$string['selftest:roundtrip'] = 'Wrote a file to Moodle file storage, read the same bytes back and deleted it.';
$string['selftest:phplimits'] = 'Measured under {$a->sapi}: upload_max_filesize {$a->uploadmax}, post_max_size {$a->postmax}, memory_limit {$a->memory}, max_input_time {$a->inputtime}, max_execution_time {$a->exectime}. Chunk size offered: {$a->chunk}. Run this from a browser rather than the command line to see the numbers a learner actually meets.';
$string['selftest:byteservingon'] = 'Byte serving is on, so learners can seek within a recording. X-Sendfile handler: {$a}.';
$string['selftest:byteservingoff'] = 'Byte serving is off ($CFG->disablebyteserving), so a learner cannot seek within a recording and the whole file is sent before playback starts.';
$string['error:uploadtoolarge'] = 'This recording is larger than this site allows, which is {$a}. Record a shorter presentation, or ask your site administrator to raise the limit.';
