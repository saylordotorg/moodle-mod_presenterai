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

$string['addtopics'] = 'Add another topic';
$string['aiheading'] = 'AI services';
$string['aiheading_desc'] = 'Which AI services PresenterAI uses to transcribe recordings, score them and give body language feedback. Keys entered here are stored encrypted and are never sent to a browser.';
$string['aiheading_inst'] = 'AI feedback';
$string['aireadiness'] = 'Current state';
$string['aireadiness_blocked'] = 'Scoring is set up but transcription is not, so activities will refuse new recordings until a transcription service is added.';
$string['aireadiness_noscoring'] = 'Scoring: nothing is set up, so recordings are graded by hand.';
$string['aireadiness_noslidevisioncore'] = 'Slide design feedback: off while scoring runs on Moodle core AI. Slide images aren\'t sent to any other service, so learners get their score without a slide design comment.';
$string['aireadiness_notranscription'] = 'Transcription: not set up. Add an OpenAI key or a transcription endpoint.';
$string['aireadiness_novision'] = 'Body language feedback: not available. It needs a Claude, OpenAI or Gemini key or a compatible endpoint, because Moodle core AI can\'t take images.';
$string['aireadiness_novisioncore'] = 'Body language feedback: off while scoring runs on Moodle core AI. Frames aren\'t sent to any other service, even when a provider key is set below, and core can\'t take images.';
$string['aireadiness_routeunconfigured'] = 'Scoring: {$a} is chosen but isn\'t set up, so scoring is unavailable.';
$string['aireadiness_scoring'] = 'Scoring: {$a}.';
$string['aireadiness_sttsizelimit'] = 'Transcription: OpenAI takes files of up to 25 MB, and at this site\'s recording quality a video longer than about {$a} minute(s) is bigger than that, so it can\'t be transcribed or scored by AI. Lower the recording quality, shorten the longest recording allowed, or use a self hosted transcription endpoint.';
$string['aireadiness_transcription'] = 'Transcription: set up.';
$string['aireadiness_vision'] = 'Body language feedback: available through {$a}.';
$string['airoute'] = 'AI service for scoring';
$string['airoute_auto'] = 'Automatic';
$string['airoute_claude'] = 'Claude (Anthropic)';
$string['airoute_compatible'] = 'OpenAI compatible endpoint';
$string['airoute_core'] = 'Moodle core AI (scoring only)';
$string['airoute_desc'] = 'Which service scores a transcript against the rubric. Automatic uses the first of these that is set up: a Claude key, an OpenAI key, a Gemini key, an OpenAI compatible endpoint, then Moodle core AI.

Moodle core AI covers scoring only. When scoring runs on Moodle core AI, learner content isn\'t sent to any provider key below for anything else: there\'s no body language feedback, no slide design feedback and no second model checking the feedback, and learners are scored on what they said and on their slide text. Transcription is the one exception. Core AI can\'t take audio, so recordings are still sent to the transcription service set up below. A site with only Moodle core AI set up can\'t transcribe a recording and so can\'t score one either: activities then refuse new recordings rather than let them fail.

A service chosen by name that has no key isn\'t replaced by another one. Scoring is unavailable until it is set up.';
$string['airoute_gemini'] = 'Gemini (Google)';
$string['airoute_openai'] = 'OpenAI';
$string['allowlearnerdownload'] = 'Learners may download their own recording';
$string['allowlearnerdownload_desc'] = 'Whether a learner can save a copy of a recording they made. This is the site wide switch; a learner also needs the mod/presenterai:downloadown capability.

It does not affect teachers, graders or managers, who download other people\'s recordings under a separate capability. One setting cannot express both "learners may not circulate recordings" and "a grader may not take evidence to a moderation meeting", so it only means the first.

How quickly turning this off takes effect depends on the storage backend. On Moodle file storage the check runs on every request, so it is immediate. On S3 the check runs when the download link is signed, and a signed link keeps working for about fifteen minutes after that no matter who is holding it, so turning this off leaves a window of that length.';
$string['allowvisualoptout'] = 'Let learners opt out of body language feedback';
$string['allowvisualoptout_help'] = 'When on, a learner sees a checkbox before recording. Ticking it means no still frames are taken from that recording and nothing about the learner\'s body language goes to the AI, and their score is worked out from the remaining criteria, so it costs them no marks. When off, the only way to avoid body language feedback is an audio only activity.';
$string['attempt_deleted'] = 'Deleted';
$string['attempt_deleted_on'] = 'Deleted on {$a}';
$string['attempt_deletes_due'] = 'Due to be deleted';
$string['attempt_deletes_on'] = 'Deletes on {$a}';
$string['attempt_gone_learner'] = 'You deleted this recording on {$a}';
$string['attempt_gone_manual'] = 'Removed on {$a}';
$string['attempt_gone_missing'] = 'This recording could not be found in storage';
$string['attempt_gone_notbackedup'] = 'This recording was not included in the backup this course was restored from';
$string['attempt_gone_note'] = 'No longer available to watch';
$string['attempt_gone_pruned'] = 'Replaced by a newer attempt on {$a}';
$string['attempt_kept'] = 'Kept until deleted';
$string['attempt_never_uploaded'] = 'Not uploaded';
$string['backend'] = 'Storage backend';
$string['backend_desc'] = 'Moodle file storage needs no configuration and lets Moodle do the work: backup and restore carry the media, deleting a course deletes it, and privacy requests export and delete it through the standard file handling. S3 keeps the media in a bucket you own and lets the browser upload straight to it, so the media never passes through the web server.

Switching is not a migration. No recording moves and none stops working. Do not clear the S3 settings while recordings made on S3 still exist, because the plugin would then be unable to reach them, including to delete them.';
$string['backendfs'] = 'Moodle file storage';
$string['backends3'] = 'S3 compatible bucket';
$string['cachedef_ratelimit'] = 'Per user counts of AI calls, for rate limiting';
$string['claudeapikey'] = 'Claude API key';
$string['claudeapikey_desc'] = 'An Anthropic API key. Stored encrypted.';
$string['claudejudgemodel'] = 'Claude model for checking feedback';
$string['claudejudgemodel_desc'] = 'A small, fast model that checks body language feedback before a learner sees it.';
$string['claudemodel'] = 'Claude model';
$string['claudemodel_desc'] = 'Used for scoring and for looking at video frames and slides.';
$string['cli_applied'] = 'Changed the deletion date of {$a} recordings.';
$string['cli_badfrom'] = 'Choose a basis with --from=created, --from=now or --from=none. There is no default, because each one changes what learners are told in a different way.';
$string['cli_dryrun'] = 'Dry run: nothing was changed. Run again with --execute to apply these changes.';
$string['cli_grace'] = 'Grace floor: no recording will be given a deletion date sooner than {$a} days from now.';
$string['cli_help'] = 'Change the deletion date of recordings that already exist.

Changing the retention setting only affects recordings made after the change. This tool changes recordings that already exist, and it shows what it would do before it does anything.

Options:
--from=created|now|none  Required. created: the activity\'s retention counted from when each recording was made. now: the activity\'s retention counted from now. none: remove deletion dates, so recordings are kept until someone removes them.
--execute                Apply the changes. Without it, this is a dry run.
--course=ID              Only recordings in this course.
--instance=ID            Only recordings in this PresenterAI activity.
--olderthan=DAYS         Only recordings made at least this many days ago.
--no-grace               With --from=created, allow deletion dates sooner than the grace floor. Requires --force.
--force                  Apply even when many recordings would become due for deletion within 24 hours.
-h, --help               Print this help.

Example:
php mod/presenterai/cli/apply_retention.php --from=now --course=12';
$string['cli_nogracewithoutforce'] = '--no-grace can set deletion dates in the past, so those recordings are deleted at the next cron run. Add --force to confirm that is what you want.';
$string['cli_summary'] = 'Basis: {$a->from}
Recordings in scope: {$a->inscope}
Recordings whose deletion date would change: {$a->changes}
Recordings that would become due for deletion within 24 hours: {$a->eligiblesoon}
Recordings currently kept until someone removes them (no deletion date): {$a->currentlyzero}
Recordings skipped because their activity keeps recordings until someone removes them: {$a->skippednever}';
$string['cli_toomanyeligible'] = 'Refused: more than {$a} recordings would become due for deletion within 24 hours. Check the numbers above, then run again with --force if this is what you intend.';
$string['cli_whatitmeans'] = 'This changes what learners are told about when their recordings are deleted, not only when the cleanup task deletes them. Learners whose recordings currently have no deletion date were told their recording is kept until someone removes it.';
$string['col_actions'] = 'Actions';
$string['col_attempt'] = 'Attempt';
$string['col_length'] = 'Length';
$string['col_recorded'] = 'Recorded';
$string['col_recording'] = 'Your recording';
$string['col_score'] = 'Score';
$string['col_status'] = 'Status';
$string['compatibleapikey'] = 'Compatible endpoint API key';
$string['compatibleapikey_desc'] = 'Optional. Sent as a Bearer token. Stored encrypted.';
$string['compatibleendpoint'] = 'Compatible endpoint';
$string['compatibleendpoint_desc'] = 'An OpenAI compatible chat completions API: either its base, such as https://example.com/v1, or the full URL ending in /chat/completions. It must use https and must not point at a private or reserved address, unless its host is listed under trusted hosts.';
$string['compatiblejudgemodel'] = 'Compatible endpoint model for checking feedback';
$string['compatiblejudgemodel_desc'] = 'Leave empty to use the compatible endpoint model.';
$string['compatiblemodel'] = 'Compatible endpoint model';
$string['compatiblemodel_desc'] = 'Required: the endpoint isn\'t used without a model.';
$string['completiondetail:minscore'] = 'Score at least {$a}%';
$string['completiondetail:submit'] = 'Submit recordings: {$a}';
$string['completionminscore'] = 'Minimum score';
$string['completionminscore_help'] = 'If enabled, the activity is complete once the learner\'s overall score reaches this percentage. The overall score combines the learner\'s scored attempts using the grading method, and it\'s worked out from the scores themselves, so this works even when the activity has no grade in the gradebook.';
$string['completionminscoreenabled'] = 'Learner must reach a score of at least this percentage';
$string['completionsubmit'] = 'Recordings submitted';
$string['completionsubmit_help'] = 'If enabled, the activity is complete once the learner has submitted this many recordings. Every finished recording counts, including one whose video has since been deleted.';
$string['completionsubmitenabled'] = 'Learner must submit at least this many recordings';
$string['deck_choose_pdf'] = 'Choose a PDF file for your slides.';
$string['deck_failed'] = 'Your slides could not be uploaded. Try again, or record without them.';
$string['deck_label'] = 'Slides (PDF, optional)';
$string['deck_nopreview'] = 'Your slides were saved, but they cannot be shown on this page. You can still record.';
$string['deck_preparing'] = 'Preparing your slides.';
$string['deck_ready'] = '{$a} slides are ready. Move through them with the Next slide and Previous slide buttons or the arrow keys while you record.';
$string['deck_uploading'] = 'Uploading your slides.';
$string['defaultrubric'] = 'Default speaking rubric';
$string['delete'] = 'Delete';
$string['delete_aria'] = 'Delete the recording you made on {$a}';
$string['delete_confirm'] = 'Delete the recording you made on {$a}? Your score, feedback and transcript are kept, and the attempt still counts.';
$string['delete_confirm_title'] = 'Delete recording';
$string['delete_done'] = 'The recording was deleted. The attempt is kept, with its score and feedback.';
$string['deletewarndays'] = 'Warn learners this many days ahead';
$string['deletewarndays_desc'] = 'How many days before its deletion date a learner is told that their recording is about to be deleted. 0 sends no advance message. This does nothing for a recording that has no deletion date, so on a site with automatic deletion off it has no effect at all.';
$string['download'] = 'Download';
$string['download_aria'] = 'Download the recording you made on {$a}';
$string['download_off_note'] = 'Downloading is switched off for this activity. You can watch your recordings here but cannot save a copy to your own device.';
$string['durationtooshort'] = 'This must be at least {$a}.';
$string['error:aicall'] = 'The AI service call failed ({$a}).';
$string['error:attemptsuperseded'] = 'This attempt was picked up in another tab or window, so this page will start a new one.';
$string['error:badext'] = 'This type of file cannot be uploaded here.';
$string['error:cannotdelete'] = 'You cannot delete this recording.';
$string['error:cannotdownload'] = 'You cannot download this recording.';
$string['error:capreached'] = 'You have used all the attempts this activity allows.';
$string['error:chunkoffset'] = 'This part of the upload starts at byte {$a->claimed}, but {$a->actual} bytes have been received. Resume from byte {$a->actual}.';
$string['error:chunkread'] = 'The upload was interrupted while it was being read.';
$string['error:completionminscore'] = 'Enter a percentage from 1 to 100.';
$string['error:completionsubmit'] = 'Enter a number of recordings of 1 or more.';
$string['error:deckafterrecording'] = 'Slides cannot be changed once the recording has started uploading.';
$string['error:deletefailed'] = 'The recording could not be deleted just now. Try again in a few minutes.';
$string['error:framesdisabled'] = 'This activity does not take still frames from this recording.';
$string['error:invalidscore'] = 'Each score must be a whole number from 0 up to that criterion\'s maximum.';
$string['error:judgeunavailable'] = 'The check on body language feedback could not be run just now. Scoring will be tried again.';
$string['error:nodeck'] = 'There are no slides for this recording.';
$string['error:nomedia'] = 'This recording is no longer stored, so it cannot be downloaded. Its score and feedback are kept.';
$string['error:notranscription'] = 'This activity can\'t take recordings right now because the site has AI scoring set up but no transcription service. Ask the site administrator to add one.';
$string['error:notuploading'] = 'This attempt is already finished, so nothing more can be uploaded to it.';
$string['error:recordingnotfound'] = 'That recording could not be found.';
$string['error:slidesdisabled'] = 'This activity does not use slides.';
$string['error:stagingunwritable'] = 'The upload could not be written to temporary storage. Check that the Moodle data directory is writable.';
$string['error:toomanyopen'] = 'You have started too many recordings on this activity without finishing them. Try again tomorrow.';
$string['error:topicrequired'] = 'Choose a topic for this recording, then press Retry to submit it.';
$string['error:unsafeendpoint'] = 'This endpoint isn\'t allowed. It must use https and must not resolve to a private, loopback, link local or reserved address. A self hosted server on your own network can be allowed by listing its host under trusted hosts.';
$string['error:uploadbusy'] = 'Another part of this upload is still being written. Try again in a moment.';
$string['error:uploadid'] = 'That upload id is not one this site issued.';
$string['error:uploadmissing'] = 'The recording did not finish uploading. Try uploading it again.';
$string['error:uploadtoolarge'] = 'This recording is larger than this site allows, which is {$a}. Record a shorter presentation, or ask your site administrator to raise the limit.';
$string['errorstorenotconfigured'] = 'The "{$a}" storage backend is selected but is not fully configured, so the plugin has refused to read or write media rather than quietly using the other backend. Fill in the missing settings in Site administration, or, if recordings are still stored there, restore the settings they were made with.';
$string['errorunknownbackend'] = 'Unknown storage backend "{$a}". This site is asking for a storage backend the plugin does not have, either because the setting holds a value nothing recognises or because a recording was made by a newer version of the plugin.';
$string['eventrecordingdeleted'] = 'Recording deleted';
$string['eventrecordingdownloaded'] = 'Recording downloaded';
$string['eventrecordingscored'] = 'Recording scored';
$string['eventrecordingsubmitted'] = 'Recording submitted';
$string['eventvisualsummaryjudgeunavailable'] = 'Body language feedback check unavailable';
$string['eventvisualsummaryrejected'] = 'Body language feedback withheld';
$string['feedback_criterion_notcounted'] = 'Feedback only, not part of your score';
$string['feedback_criterion_score'] = '{$a->score} out of {$a->max}';
$string['feedback_media_gone'] = 'The recording for this attempt has been deleted. Your scores, written feedback and transcript below are kept.';
$string['feedback_notassessed'] = 'Not assessed';
$string['feedback_tips'] = 'Tips for next time';
$string['feedback_toggle'] = 'Feedback';
$string['feedback_toggle_aria'] = 'Feedback on the attempt you made on {$a}';
$string['feedback_withheld'] = 'This comment was withheld by an automatic check, because it described you rather than what you did in your presentation.';
$string['fschunkbytes'] = 'Upload chunk size (bytes)';
$string['fschunkbytes_desc'] = 'With Moodle file storage a recording is uploaded in pieces, and this is the size of each piece. 0 uses 512 KB, which gets through a web server and proxy left at their defaults. Use the storage check page to measure the largest size this site really accepts and save it here. If a piece is ever refused as too large, the uploader halves it and carries on.';
$string['gatelog'] = 'Withheld body language feedback';
$string['gatelog_col_activity'] = 'Activity';
$string['gatelog_col_course'] = 'Course';
$string['gatelog_col_layer'] = 'Check';
$string['gatelog_col_rule'] = 'Rule';
$string['gatelog_col_target'] = 'Feedback';
$string['gatelog_col_text'] = 'Withheld text';
$string['gatelog_col_time'] = 'Time';
$string['gatelog_empty'] = 'Nothing has been withheld in the last 7 days.';
$string['gatelog_intro'] = 'Body language feedback the automatic check withheld from learners in the last 7 days, with the check and rule that withheld it. Rows are deleted after 7 days. The text is unreviewed AI output about a learner, so read it only to tune the checks.';
$string['gatelog_layer_anchor'] = 'Not tied to the rubric';
$string['gatelog_layer_deny'] = 'Word list';
$string['gatelog_layer_form'] = 'Length or copied note';
$string['gatelog_layer_judge'] = 'Second AI model';
$string['gatelog_layer_unavailable'] = 'Check unavailable';
$string['gatelog_link'] = 'View withheld body language feedback from the last 7 days';
$string['gatelog_target_overall'] = 'Overall comment';
$string['gatelog_target_summary'] = 'Summary';
$string['gatelog_target_tip'] = 'Tip';
$string['geminiapikey'] = 'Gemini API key';
$string['geminiapikey_desc'] = 'A Google AI Studio API key. Stored encrypted.';
$string['geminijudgemodel'] = 'Gemini model for checking feedback';
$string['geminijudgemodel_desc'] = 'A small, fast model that checks body language feedback before a learner sees it.';
$string['geminimodel'] = 'Gemini model';
$string['geminimodel_desc'] = 'Used for scoring and for looking at video frames and slides.';
$string['grade_assessed'] = 'Assess this criterion';
$string['grade_attemptlabel'] = 'Attempt {$a}';
$string['grade_feedbackonly'] = 'Feedback only: this criterion is not part of the score.';
$string['grade_notranscript'] = 'There is no transcript for this attempt.';
$string['grade_novisual'] = 'There is no visual evidence note for this attempt.';
$string['grade_overallfeedback'] = 'Overall feedback';
$string['grade_save'] = 'Save score';
$string['grade_saved'] = 'The score has been saved.';
$string['grade_scorerequired'] = 'Choose a score for this criterion, or clear Assess this criterion.';
$string['grade_staffonly'] = 'This note is shown to staff only. The learner doesn\'t see it on any page.';
$string['grade_total'] = 'Total: {$a->sum} out of {$a->max} ({$a->pct}%)';
$string['grade_total_none'] = 'Total: no scores chosen yet';
$string['grade_transcript'] = 'Transcript';
$string['grade_visualevidence'] = 'Visual evidence';
$string['grade_watch_aria'] = 'Recording by {$a->name}, made on {$a->date}';
$string['gradeattempt'] = 'Grade attempt {$a->attempt} by {$a->name}';
$string['gradingmethod'] = 'Grading method';
$string['gradingmethod_average'] = 'Average of scored attempts';
$string['gradingmethod_first'] = 'First scored attempt';
$string['gradingmethod_help'] = 'When a learner makes more than one attempt, this decides which score goes to the gradebook.

* Highest: the best scored attempt.
* Average: the mean of all scored attempts.
* First: the earliest scored attempt.
* Latest: the most recent scored attempt.

Only attempts that have a score count. An attempt still counts after its recording has been deleted, because the score is kept.';
$string['gradingmethod_highest'] = 'Highest scored attempt';
$string['gradingmethod_latest'] = 'Latest scored attempt';
$string['level_esl_advanced'] = 'English as a second language, advanced';
$string['level_esl_beginner'] = 'English as a second language, beginner';
$string['level_esl_intermediate'] = 'English as a second language, intermediate';
$string['level_general'] = 'General presentation';
$string['maxattempts'] = 'Attempts allowed';
$string['maxattempts_help'] = 'How many recordings a learner may submit for this activity. 0 means there is no limit. An attempt that was started and never finished does not count.';
$string['maxmediabytes'] = 'Largest recording';
$string['maxmediabytes_desc'] = 'The largest recording a learner can upload. The site and course maximum upload sizes also apply, and the smallest limit wins. 150 MB is room for about seven minutes at 720p. Learners see the limit before they start recording.';
$string['maxrecordingseconds'] = 'Longest recording allowed (seconds)';
$string['maxrecordingseconds_desc'] = 'The site wide ceiling on any activity\'s maximum length, in seconds. No activity can be set longer than this. The default is 720 seconds (12 minutes). Longer recordings are bigger uploads: at standard quality a 12 minute video is about 56 MB.';
$string['maxseconds'] = 'Maximum length';
$string['maxseconds_help'] = 'The longest a recording may be. It cannot be longer than the limit set for the whole site.';
$string['maxsecondsoverlimit'] = 'This is longer than this site allows, which is {$a}.';
$string['message_deletionwarning_body'] = 'Your recording in {$a->activity} is due to be deleted on {$a->date}. Your transcript, scores and feedback are kept after the recording is gone.';
$string['message_deletionwarning_bodyhtml'] = '<p>Your recording in {$a->activity} is due to be deleted on {$a->date}.</p><p>Your transcript, scores and feedback are kept after the recording is gone.</p>';
$string['message_deletionwarning_download'] = 'If you want to keep a copy, download it from the activity before then.';
$string['message_deletionwarning_nodownload'] = 'Downloading is not available for this recording, so you cannot save a copy of it.';
$string['message_deletionwarning_small'] = 'Your recording in {$a->activity} will be deleted on {$a->date}.';
$string['message_deletionwarning_subject'] = 'Your recording in {$a->activity} will be deleted soon';
$string['message_recordingscored_body'] = 'Your attempt {$a->attempt} in {$a->activity} has a score ready to read. See it here: {$a->url}';
$string['message_recordingscored_bodyhtml'] = '<p>Your attempt {$a->attempt} in {$a->activity} has a score ready to read.</p><p><a href="{$a->url}">See your score</a></p>';
$string['message_recordingscored_small'] = 'Your attempt {$a->attempt} in {$a->activity} has a score.';
$string['message_recordingscored_subject'] = 'Your score is ready: {$a->activity}';
$string['messageprovider:deletionwarning'] = 'Advance notice that one of your recordings is about to be deleted';
$string['messageprovider:recordingscored'] = 'A score is ready for one of your recordings';
$string['minseconds'] = 'Minimum length';
$string['minseconds_help'] = 'The shortest a presentation should be. Learners are shown this alongside the maximum before they start recording.';
$string['minsecondsovermax'] = 'The minimum length cannot be longer than the maximum length.';
$string['mode'] = 'Recording type';
$string['mode_help'] = 'Video records the camera and the microphone. Audio only records the microphone, which suits learners with limited bandwidth or a reason not to appear on camera, and makes much smaller files.';
$string['modeaudio'] = 'Audio only';
$string['modevideo'] = 'Video and audio';
$string['modulename'] = 'PresenterAI';
$string['modulename_help'] = 'PresenterAI asks a learner to record a spoken presentation, video or audio only, optionally alongside slides they advance while speaking. The recording is transcribed and scored against a rubric, and the learner reads written feedback on each criterion.

Recordings can be stored in Moodle\'s own file storage or in an S3-compatible bucket, and can be kept until someone removes them or deleted automatically after a set number of days.';
$string['modulenameplural'] = 'PresenterAI activities';
$string['noattempts'] = 'You have not recorded an attempt yet.';
$string['nopresenterais'] = 'There are no PresenterAI activities in this course.';
$string['nostorage'] = 'Recording is not available in this activity yet, because storage for recordings has not been set up on this site. Any attempts you have already made are listed below.';
$string['openaiapikey'] = 'OpenAI API key';
$string['openaiapikey_desc'] = 'An OpenAI API key. Also used for transcription when no transcription endpoint is set. Stored encrypted.';
$string['openaijudgemodel'] = 'OpenAI model for checking feedback';
$string['openaijudgemodel_desc'] = 'A small, fast model that checks body language feedback before a learner sees it.';
$string['openaimodel'] = 'OpenAI model';
$string['openaimodel_desc'] = 'Used for scoring and for looking at video frames and slides.';
$string['player_loading'] = 'Loading the recording.';
$string['player_media_gone'] = 'This recording can no longer be played.';
$string['pluginadministration'] = 'PresenterAI administration';
$string['pluginname'] = 'PresenterAI';
$string['presenterai:addinstance'] = 'Add a new PresenterAI activity';
$string['presenterai:deleteanyrecording'] = 'Delete any recording';
$string['presenterai:deleteownmedia'] = 'Delete the media of your own recording';
$string['presenterai:downloadany'] = 'Download other people\'s recordings';
$string['presenterai:downloadown'] = 'Download your own recording';
$string['presenterai:grade'] = 'Grade presentations';
$string['presenterai:managerubrics'] = 'Manage scoring rubrics';
$string['presenterai:setretention'] = 'Change how long an activity keeps recordings';
$string['presenterai:submit'] = 'Record and submit a presentation';
$string['presenterai:useai'] = 'Have AI feedback generated for your attempts';
$string['presenterai:view'] = 'View a PresenterAI activity';
$string['presenterai:viewallattempts'] = 'View other people\'s recordings';
$string['presenterai:viewvisualevidence'] = 'See the visual evidence note on the grading screen';
$string['presenterainame'] = 'Activity name';
$string['presenterainame_help'] = 'The name learners see in the course.';
$string['privacy:export:s3media'] = 'The media for this attempt ({$a}) is stored in an S3 bucket and is not included in this export. Ask the site administrator if you need a copy of the media itself.';
$string['privacy:export:visualevidence_note'] = 'This is the raw description an AI model produced of what was visible in still frames sampled from your recording: what your hands and arms were doing, your posture, where you were looking, and how you were framed. It was used to score the body language criteria and is not shown with your feedback. It is deleted after a number of days the site sets.';
$string['privacy:metadata:aiservice'] = 'To transcribe and score a recording and give body language feedback, the site sends data to the AI service an administrator configured.';
$string['privacy:metadata:aiservice:audio'] = 'The recording\'s audio, sent for transcription.';
$string['privacy:metadata:aiservice:feedback'] = 'Body language feedback written by the AI, sent to a second AI model to check it before it is shown.';
$string['privacy:metadata:aiservice:frames'] = 'Still frames sampled from a video recording, sent for body language feedback when the activity uses it.';
$string['privacy:metadata:aiservice:slides'] = 'The text of the learner\'s slide deck, sent with the transcript for scoring, and images of up to 12 of its pages, sent for slide design feedback when the activity uses it.';
$string['privacy:metadata:aiservice:transcript'] = 'The transcript of the recording, sent for scoring.';
$string['privacy:metadata:core_ai'] = 'When the site scores through Moodle\'s AI subsystem, the transcript, the slide text and the scoring instructions are sent through it, and the AI subsystem keeps its own record of the request and the reply.';
$string['privacy:metadata:core_files'] = 'On Moodle file storage, the recording, the slide deck and the still frames of each attempt are stored as files of the activity.';
$string['privacy:metadata:core_grades'] = 'An attempt\'s score is sent to the gradebook as the learner\'s grade for the activity.';
$string['privacy:metadata:core_message'] = 'Learners are sent messages when a score is ready and before a recording is deleted on its deletion date.';
$string['privacy:metadata:presenterai_aiusage'] = 'One row per call to an AI service made for an attempt, kept to attribute the cost of AI use.';
$string['privacy:metadata:presenterai_aiusage:action'] = 'What the AI call was for, such as transcription or scoring.';
$string['privacy:metadata:presenterai_aiusage:audioseconds'] = 'How many seconds of audio were sent.';
$string['privacy:metadata:presenterai_aiusage:completiontokens'] = 'How many tokens the AI service returned.';
$string['privacy:metadata:presenterai_aiusage:estmicrocents'] = 'The estimated cost of the call.';
$string['privacy:metadata:presenterai_aiusage:imagecount'] = 'How many images were sent.';
$string['privacy:metadata:presenterai_aiusage:model'] = 'The AI model that was called.';
$string['privacy:metadata:presenterai_aiusage:prompttokens'] = 'How many tokens were sent to the AI service.';
$string['privacy:metadata:presenterai_aiusage:provider'] = 'The AI service that was called.';
$string['privacy:metadata:presenterai_aiusage:recordingid'] = 'The attempt the call was made for.';
$string['privacy:metadata:presenterai_aiusage:timecreated'] = 'When the call was made.';
$string['privacy:metadata:presenterai_aiusage:userid'] = 'The user whose attempt the call was made for.';
$string['privacy:metadata:presenterai_gatelog'] = 'Body language feedback the automatic check withheld from the learner, kept for 7 days so site administrators can tune the check.';
$string['privacy:metadata:presenterai_gatelog:gaterule'] = 'The rule that withheld it.';
$string['privacy:metadata:presenterai_gatelog:layer'] = 'Which part of the check withheld it.';
$string['privacy:metadata:presenterai_gatelog:recordingid'] = 'The attempt the feedback was about.';
$string['privacy:metadata:presenterai_gatelog:rejectedtext'] = 'The withheld text.';
$string['privacy:metadata:presenterai_gatelog:target'] = 'Whether it was the summary or the comment on one criterion.';
$string['privacy:metadata:presenterai_gatelog:timecreated'] = 'When it was withheld.';
$string['privacy:metadata:presenterai_recording'] = 'A recorded presentation attempt: the media reference, its length, its state and the transcript produced from it.';
$string['privacy:metadata:presenterai_recording:attemptnumber'] = 'Which attempt this is for the learner.';
$string['privacy:metadata:presenterai_recording:deletewarnedat'] = 'When the learner was sent the advance message about the recording\'s deletion date.';
$string['privacy:metadata:presenterai_recording:durationseconds'] = 'How long the recording is, in seconds.';
$string['privacy:metadata:presenterai_recording:expiresat'] = 'When the recording is due to be deleted, if it has a deletion date.';
$string['privacy:metadata:presenterai_recording:mediadeletedat'] = 'When the recording\'s media was deleted.';
$string['privacy:metadata:presenterai_recording:mediagonereason'] = 'Why the recording\'s media was deleted.';
$string['privacy:metadata:presenterai_recording:mode'] = 'Whether the attempt was recorded as video or audio only.';
$string['privacy:metadata:presenterai_recording:sizebytes'] = 'The size of the recording.';
$string['privacy:metadata:presenterai_recording:slidetimeline'] = 'When the learner moved between slides during the recording.';
$string['privacy:metadata:presenterai_recording:status'] = 'Where the attempt is in its life: uploading, uploaded, scored and so on.';
$string['privacy:metadata:presenterai_recording:timecreated'] = 'When the attempt was started.';
$string['privacy:metadata:presenterai_recording:topicid'] = 'The topic the learner chose for the attempt.';
$string['privacy:metadata:presenterai_recording:transcript'] = 'The text transcribed from the recording. It is kept after the media is deleted, because it is the learner\'s record of what they said.';
$string['privacy:metadata:presenterai_recording:userid'] = 'The learner who made the recording.';
$string['privacy:metadata:presenterai_recording:visualevidence'] = 'A description an AI model produced of what was visible in still frames sampled from the recording: what the learner\'s hands and arms were doing, their posture, where they were looking, and how they were framed. It is used to score the body language criteria and is deleted after a number of days the site sets.';
$string['privacy:metadata:presenterai_recording:visualevidenceat'] = 'When the description of what was visible in the recording was written.';
$string['privacy:metadata:presenterai_recording:visualoptout'] = 'Whether the learner opted the attempt out of body language feedback.';
$string['privacy:metadata:presenterai_score'] = 'One scored judgement of one attempt, by AI or by a teacher, with the per-criterion marks and written feedback.';
$string['privacy:metadata:presenterai_score:feedback'] = 'The overall written feedback on the attempt.';
$string['privacy:metadata:presenterai_score:graderid'] = 'The teacher who entered the score by hand, if one did.';
$string['privacy:metadata:presenterai_score:legacymeanscore'] = 'For a score carried over from Soapbox, the overall score Soapbox gave the attempt.';
$string['privacy:metadata:presenterai_score:legacymeta'] = 'For a score carried over from Soapbox, the data Soapbox kept about the recording session.';
$string['privacy:metadata:presenterai_score:origin'] = 'Whether the score came from AI or was entered by a teacher.';
$string['privacy:metadata:presenterai_score:overallpct'] = 'The overall score as a percentage.';
$string['privacy:metadata:presenterai_score:rawmax'] = 'The most points the assessed criteria allow.';
$string['privacy:metadata:presenterai_score:rawsum'] = 'The points given across the assessed criteria.';
$string['privacy:metadata:presenterai_score:scores'] = 'The mark and written feedback for each criterion.';
$string['privacy:metadata:presenterai_score:timecreated'] = 'When the score was given.';
$string['privacy:metadata:presenterai_score:tips'] = 'Suggestions for the next attempt.';
$string['privacy:metadata:presenterai_score:userid'] = 'The learner whose attempt was scored.';
$string['privacy:metadata:presenterai_score:visualstatus'] = 'Which body language message the learner was shown with the score.';
$string['privacy:metadata:presenterai_score:visualsummary'] = 'The short body language summary shown to the learner with their feedback.';
$string['privacy:metadata:s3'] = 'When the site stores recordings in an S3 bucket, each attempt\'s media is uploaded to that bucket.';
$string['privacy:metadata:s3:deck'] = 'The slide deck the learner presented from.';
$string['privacy:metadata:s3:frames'] = 'Still frames sampled from the recording for body language feedback.';
$string['privacy:metadata:s3:media'] = 'The recorded video or audio.';
$string['privacy:path:aiusage'] = 'AI usage';
$string['privacy:path:attempts'] = 'Attempts';
$string['privacy:path:scoresgiven'] = 'Scores given';
$string['privacy_delete'] = 'It is deleted automatically {$a} days after you record it.';
$string['privacy_download_off'] = 'Downloading is switched off for this activity, so you cannot save a copy to your own device.';
$string['privacy_download_on'] = 'You can download a copy of your recording at any time while it is here.';
$string['privacy_frames_delete'] = 'The still frames used for body language feedback are deleted with it.';
$string['privacy_frames_keep'] = 'The still frames used for body language feedback are kept for as long as the recording is.';
$string['privacy_keep'] = 'It is not deleted automatically. It is kept until it is deleted here, or until this activity or the course is removed.';
$string['privacy_kept_after'] = 'Your transcript, scores and feedback are kept after the recording is gone.';
$string['privacy_stem'] = 'Your recording is uploaded to {$a} storage so it can be transcribed and scored. Only you and people with permission to view submissions in this course can open it.';
$string['privacy_visualnote'] = 'The note the AI writes about what it saw in those frames is deleted after {$a} days, whether or not the recording itself is still here.';
$string['probe'] = 'PresenterAI storage check';
$string['probe_chunkexplain'] = 'With Moodle file storage, recordings are uploaded in pieces. A web server or proxy in front of Moodle often refuses large requests before PHP sees them, and no PHP setting reveals that limit. This test sends pieces of 512 KB, 1 MB, 2 MB and 5 MB from your browser and saves the largest one that arrives whole.';
$string['probe_chunkheading'] = 'Upload chunk size';
$string['probe_chunknone'] = 'No chunk size has been measured on this site, so uploads use the default of 512 KB.';
$string['probe_chunksaved'] = 'Chunk size measured and saved for this site: {$a}.';
$string['probe_col_detail'] = 'Detail';
$string['probe_col_result'] = 'Result';
$string['probe_col_step'] = 'Check';
$string['probe_count'] = '{$a->label}: {$a->count} recordings';
$string['probe_countsheading'] = 'Recordings with stored media';
$string['probe_countsnone'] = 'No recordings have stored media yet.';
$string['probe_fail'] = 'Failed';
$string['probe_intro'] = 'Each storage backend is tested from this server, right now, by writing, reading back and deleting a small test file. S3 is tested when it is configured or when any recording is still stored there.';
$string['probe_measure'] = 'Measure chunk size';
$string['probe_nolifecycle'] = 'PresenterAI cannot read the bucket lifecycle configuration, so this test cannot tell you whether a lifecycle rule will delete recordings. See the bucket lifecycle setting.';
$string['probe_nonesaved'] = 'Even the smallest test piece, 512 KB, was refused, so nothing was saved. Uploads will fall back to smaller pieces, which is slower. Check the request size limit on the web server or proxy in front of Moodle.';
$string['probe_notchecked'] = 'Not checked';
$string['probe_notconfigured'] = 'This backend is not fully configured. Recordings already stored here cannot be reached until its settings are filled in.';
$string['probe_ok'] = 'Passed';
$string['probe_rungfail'] = '{$a}: refused or incomplete';
$string['probe_rungok'] = '{$a}: received in full';
$string['probe_running'] = 'Sending test pieces...';
$string['probe_saved'] = 'Saved {$a} as this site\'s upload chunk size.';
$string['probe_savefailed'] = 'The measured chunk size could not be saved. Reload the page and try again.';
$string['probe_step_byteserving'] = 'Seeking within a recording';
$string['probe_step_configuration'] = 'Configuration';
$string['probe_step_cors'] = 'Browser upload rule (CORS)';
$string['probe_step_delete'] = 'Delete';
$string['probe_step_exception'] = 'Unexpected error';
$string['probe_step_lifecycle'] = 'Bucket lifecycle rule';
$string['probe_step_lock'] = 'Locking';
$string['probe_step_phplimits'] = 'PHP upload limits';
$string['probe_step_private'] = 'Bucket is private';
$string['probe_step_read'] = 'Read back';
$string['probe_step_roundtrip'] = 'File storage round trip';
$string['probe_step_size'] = 'Size check';
$string['probe_step_staging'] = 'Temporary storage';
$string['probe_step_write'] = 'Write';
$string['probe_tablecaption'] = 'Storage check results for {$a}';
$string['probelink'] = 'Check storage and measure the upload chunk size';
$string['ptype'] = 'Presentation type';
$string['ptype_help'] = 'What the learner is asked to do. An informative presentation explains or teaches, and feedback weighs clarity, accuracy and organization. A persuasive presentation argues for a position, and feedback weighs the claim, the evidence and the call to action.';
$string['ptype_informative'] = 'Informative';
$string['ptype_persuasive'] = 'Persuasive';
$string['quality'] = 'Video quality';
$string['quality_desc'] = 'The resolution and bitrate the recorder asks the browser for. Higher quality means bigger uploads and more storage. For a 7 minute video, allowing for the browser overshooting its target, Low is about 23 MB, Standard about 33 MB and High about 75 MB. Audio only activities use only the audio part of the preset, which is small at every level.';
$string['quality_high_720p'] = 'High (720p)';
$string['quality_low_360p'] = 'Low (360p)';
$string['quality_standard_480p'] = 'Standard (480p)';
$string['rec_audio_only'] = 'Audio only: your microphone is recorded, not your camera.';
$string['rec_choosetopic'] = 'Choose a topic before you start recording.';
$string['rec_limits'] = 'Record for between {$a->min} and {$a->max}. The largest recording this site accepts is {$a->size}.';
$string['rec_mic_denied'] = 'Your browser did not let this page use your camera or microphone. Allow access in your browser\'s settings for this site, then try again.';
$string['rec_near_max'] = 'Less than 15 seconds left. Recording stops automatically at the time limit.';
$string['rec_retry'] = 'Retry upload';
$string['rec_start_failed'] = 'Recording could not start ({$a}). Try again.';
$string['rec_status_failed'] = 'Your recording could not be uploaded. It is still held in this page: select Retry upload to send it again, and do not close or reload the page until it has gone.';
$string['rec_status_finalizing'] = 'Saving your attempt.';
$string['rec_status_recording'] = 'Recording';
$string['rec_status_uploaded'] = 'Your recording has been submitted.';
$string['rec_status_uploading'] = 'Uploading your recording: {$a}%';
$string['rec_too_short'] = 'That recording was too short to submit. Record for at least {$a}.';
$string['rec_unsupported'] = 'This browser cannot record here. Use a recent version of Chrome, Edge, Firefox or Safari.';
$string['record'] = 'Record';
$string['record_delete_dl_body'] = 'This recording is deleted automatically {$a} days after you make it. The exact date is shown against every attempt in the list below. Your scores, written feedback and transcript are kept after the recording is gone. Download anything you want to keep before that date.';
$string['record_delete_dl_heading'] = 'Your recording is deleted after {$a} days.';
$string['record_delete_nodl_body'] = 'This recording is deleted automatically {$a} days after you make it, and the exact date is shown against every attempt in the list below. Downloading is switched off for this activity, so there is no way to save a copy of the recording before it goes. Your scores, written feedback and transcript are kept and stay available to you here after the recording is gone.';
$string['record_delete_nodl_heading'] = 'Your recording is deleted after {$a} days, and cannot be downloaded.';
$string['record_keep_dl_body'] = 'Nothing deletes this recording automatically. It stays on {$a} until it is deleted by you or by someone with permission to manage this activity, or until the activity or the course is removed. You can download a copy at any time from the list of your attempts below.';
$string['record_keep_dl_heading'] = 'Your recording is kept until it is deleted.';
$string['record_keep_nodl_body'] = 'Nothing deletes this recording automatically. It stays on {$a} until it is deleted by you or by someone with permission to manage this activity, or until the activity or the course is removed. Downloading is switched off for this activity, so you can watch your recording here but cannot save a copy to your own device. Your scores, written feedback and transcript are always available to you here.';
$string['record_keep_nodl_heading'] = 'Your recording is kept until it is deleted, and cannot be downloaded.';
$string['record_nodl_contact_delete'] = 'If you need a copy of your recording, ask {$a} before it\'s deleted.';
$string['record_nodl_contact_keep'] = 'If you need a copy of your recording, ask {$a}.';
$string['record_prune_warning'] = 'Recording again will delete your oldest recording, made on {$a}. Your score and feedback for it are kept.';
$string['recording_blocked_cap'] = 'You have used all {$a} attempts this activity allows, so you cannot record another. Your attempts are listed below.';
$string['recording_blocked_size'] = 'Recording is not available in this activity yet, because a recording of the length it allows could be larger than this site accepts ({$a->limit}). It becomes available once the activity\'s maximum length or the site\'s recording quality is lowered.';
$string['recordingheading'] = 'Recording';
$string['recordingheading_desc'] = 'How recordings are made: the quality the browser records at, and the longest recording any activity may ask for.';
$string['report_attempt_aria'] = 'Grade attempt {$a->n} by {$a->name}';
$string['report_attemptn'] = 'Attempt {$a}';
$string['report_caption'] = 'Learners in this activity and their attempts';
$string['report_col_actions'] = 'Grade an attempt';
$string['report_col_aipct'] = 'AI %';
$string['report_col_attempts'] = 'Attempts';
$string['report_col_finalpct'] = 'Overall %';
$string['report_col_latest'] = 'Latest attempt';
$string['report_col_learner'] = 'Learner';
$string['report_col_length'] = 'Length';
$string['report_col_spend'] = 'AI spend';
$string['report_col_status'] = 'Status';
$string['report_col_teacherpct'] = 'Teacher %';
$string['report_noattempt'] = 'No attempt';
$string['report_nolearners'] = 'There are no learners to show.';
$string['report_spendvalue'] = '${$a}';
$string['rescore'] = 'Rescore with AI';
$string['rescore_help'] = 'Scores this attempt again from its transcript, or transcribes it first if there is none. The new score replaces the current AI score. It is only offered while no teacher has scored the attempt.';
$string['rescore_notavailable'] = 'This attempt cannot be rescored with AI. A teacher may already have scored it, AI may not be set up, or there may be nothing left to score from.';
$string['rescore_queued'] = 'The attempt will be rescored in the background. Reload this page in a few minutes to see the new score.';
$string['rescore_unavailable_nomedia'] = 'This attempt cannot be rescored with AI: its recording has been deleted and it has no transcript to score from.';
$string['reset_recordings'] = 'Delete all recordings, their media and their scores';
$string['reset_recordings_done'] = 'All recordings, their media and their scores were deleted';
$string['reset_recordings_help'] = 'Removes every learner attempt in every PresenterAI activity in this course, with its media, transcript, scores and feedback. Topics, rubrics and activity settings are kept. AI usage records are kept without the learner\'s name, so cost totals still add up.';
$string['restore_s3_missing'] = 'Some PresenterAI attempts stored on S3 were restored without their media, because the bucket objects they named have been deleted. Those attempts keep their scores, feedback and transcripts.';
$string['restore_s3_shared'] = 'The restored PresenterAI attempts stored on S3 point at the same bucket objects as the attempts they were copied from. Deleting one copy does not delete media the other still uses.';
$string['restore_s3_unreachable'] = 'PresenterAI attempts stored on S3 were restored without their media, because the media is in another site\'s bucket. Those attempts keep their scores, feedback and transcripts.';
$string['restore_s3_unverified'] = 'PresenterAI couldn\'t check that the S3 media of some restored attempts still exists, because S3 isn\'t configured or didn\'t answer. Those attempts keep their media keys, so check them once S3 is reachable.';
$string['retentiondays'] = 'Delete recordings after (days)';
$string['retentiondays_desc'] = 'The number of days after a recording is finished before its media is deleted automatically. 0 means there is no automatic deletion and the media is kept until somebody removes it. There is no upper limit, and 1 day is the shortest window that deletes anything.

<strong>Changing this affects recordings made from now on. Recordings that already exist keep the deletion date they already have, and recordings that have no deletion date do not acquire one.</strong> That is deliberate and it works in both directions. Turning automatic deletion on does not put a year of existing recordings on a clock that fires at the next cron run. Turning it off does not cancel the deletion dates that learners have already been shown, so those recordings are still deleted on the dates they were promised.

To change recordings that already exist, use the retention tool at cli/apply_retention.php, which shows you what it would do before it does anything and states how many learners were told their recording would be kept.

An activity can set its own retention, which overrides this value.

A window shorter than a few days is worth thinking about twice: scoring runs on cron, and a backlog on a busy site can mean the media is deleted before it has been transcribed.';
$string['retentiondays_inst'] = 'Delete recordings after';
$string['retentiondays_inst_help'] = 'Use the site default, keep recordings until someone removes them, or set a number of days. When a number is set, learners see the deletion date against every attempt, and a recording already made keeps the date it was given, so changing this here does not move an existing deletion date.';
$string['retentiondays_locked'] = 'Recordings on this site are deleted after {$a}. Changing this per activity needs the set retention capability.';
$string['retentiondays_locked_keep'] = 'Recordings on this site are kept until someone deletes them. Changing this per activity needs the set retention capability.';
$string['retentiondaysmin'] = 'Enter a number of days of 1 or more.';
$string['retentiondaysvalue'] = 'Days before deletion';
$string['retentionheading'] = 'Retention and download';
$string['retentionheading_desc'] = 'How long a recording\'s media is kept, and whether a learner may keep a copy of their own. A recording\'s score, written feedback and transcript are kept regardless: they belong to the learner\'s record and are not deleted when the media is.';
$string['retentionheading_inst'] = 'Recording retention';
$string['retentionmode'] = 'Keep recordings';
$string['retentionmode_days'] = 'Delete recordings after a number of days';
$string['retentionmode_keep'] = 'Keep recordings until someone deletes them';
$string['retentionmode_site'] = 'Use the site setting ({$a})';
$string['retentionoverridenote'] = '{$a} activities on this site set their own retention and are not affected by changes to the value above.';
$string['retentionsitedays'] = 'deleted after {$a}';
$string['retentionsitekeep'] = 'kept until deleted';
$string['retentiontooshort_warning'] = 'Recordings in this activity are deleted after {$a}. Scoring and cron may not reach a recording before it is deleted, which would leave nothing to score. A window of at least 3 days is safer.';
$string['retentionwithoutdownloadnote'] = '<strong>Check this combination.</strong> Recordings are deleted automatically and learners cannot download their own, so a learner\'s presentation is destroyed on a schedule and they were never able to keep a copy of it. That is a reasonable policy if it is the one you meant. If it is not, either set the deletion window to 0 or allow learners to download their own recording.';
$string['rubricactive'] = 'Active';
$string['rubricactive_help'] = 'Only an active rubric is used for scoring or offered on the activity settings page. Turning a rubric off keeps it for later without deleting it.';
$string['rubricadd'] = 'Add a rubric to this activity';
$string['rubricaddcourse'] = 'Add a rubric to this course';
$string['rubricaddcriterion'] = 'Add another criterion';
$string['rubriccaption'] = 'Rubrics this activity can use, nearest first';
$string['rubriccriteriacount'] = 'Criteria';
$string['rubriccriterion'] = 'Criterion {$a}';
$string['rubriccriteriondesc'] = 'Description';
$string['rubriccriterionmax'] = 'Maximum score';
$string['rubriccriterionname'] = 'Name';
$string['rubriccriterionvisual'] = 'Judged from the video';
$string['rubriccriterionvisual_help'] = 'A criterion judged from still frames of the speaker rather than from the transcript, such as gestures or eye contact. It is only scored when the activity has body language feedback on and the frames could be read, and it only counts toward the grade when the activity says so. Otherwise it gives written feedback only.';
$string['rubricdelete'] = 'Delete';
$string['rubricdelete_aria'] = 'Delete the rubric {$a}';
$string['rubricdeleteconfirm'] = 'Delete the rubric "{$a}"? Scores already given against it keep their criteria and marks, and any activity that chose it goes back to choosing a rubric automatically.';
$string['rubricdeleted'] = 'The rubric was deleted.';
$string['rubricdetails'] = 'Rubric';
$string['rubricedit'] = 'Edit';
$string['rubricedit_aria'] = 'Edit the rubric {$a}';
$string['rubricedittitle'] = 'Edit rubric';
$string['rubricerror_duplicate'] = 'Another criterion already has this name.';
$string['rubricerror_max'] = 'Choose a maximum score from 1 to 10.';
$string['rubricerror_nocriteria'] = 'Add at least one criterion.';
$string['rubricerror_noname'] = 'Give this criterion a name, or clear its description.';
$string['rubricerror_notfound'] = 'That rubric was not found, or you cannot change it from this activity.';
$string['rubricerror_type'] = 'Choose a rubric type.';
$string['rubricerror_visualspeech'] = 'Only a video rubric can have criteria judged from the video.';
$string['rubricid'] = 'Rubric';
$string['rubricid_auto'] = 'Automatic';
$string['rubricid_help'] = 'Automatic uses the nearest active rubric defined for this activity, its course or a category above it, preferring a video rubric for a video activity. When there is none it uses the built-in criteria for the speaking level. Choosing a rubric uses that one whatever else is defined.';
$string['rubriclevel_activity'] = 'This activity';
$string['rubriclevel_course'] = 'This course';
$string['rubricnew'] = 'New rubric';
$string['rubricnew_course'] = 'This rubric will belong to the course, so every PresenterAI activity in the course can use it.';
$string['rubricnone'] = 'No rubrics are defined yet, so this activity uses the built-in criteria for its speaking level.';
$string['rubricpreset'] = 'Start from a preset';
$string['rubricpreset_apply'] = 'Use this preset';
$string['rubricpreset_help'] = 'Replaces the criteria below with a preset\'s, which you can then edit. A video rubric also gets the two criteria judged from the video. Nothing is saved until you save the rubric.';
$string['rubricreadonly'] = 'Defined elsewhere';
$string['rubrics'] = 'Rubrics';
$string['rubrics_intro'] = 'A rubric lists the criteria an attempt is scored against. Rubrics defined further out, in the course or a category, are shown so you can see what this activity would use, and can only be changed where they are defined.';
$string['rubricsaved'] = 'The rubric was saved.';
$string['rubrictitle'] = 'Title';
$string['rubrictype'] = 'Type';
$string['rubrictype_help'] = 'A speech rubric has spoken criteria only, and is used by audio and video activities. A video rubric is used by video activities and may also have criteria judged from the video.';
$string['rubrictype_speech'] = 'Speech';
$string['rubrictype_video'] = 'Video';
$string['rubricwhere'] = 'Defined in';
$string['s3bucket'] = 'Bucket';
$string['s3bucket_desc'] = 'The bucket recordings are written to. It must block public access, and it needs a CORS rule allowing PUT and GET from this site, because the browser uploads to it directly. The storage self test checks both, and says so in words: a missing CORS rule otherwise shows up only as an unexplained browser error after a learner has finished speaking.';
$string['s3endpoint'] = 'Endpoint';
$string['s3endpoint_desc'] = 'Leave this empty for Amazon S3. For another S3 compatible service, give the full endpoint URL including the scheme, for example https://minio.example.org:9000.';
$string['s3key'] = 'Access key ID';
$string['s3key_desc'] = 'The access key the plugin signs requests with. It needs permission to read, write and delete objects under the prefix below, and nothing else.';
$string['s3lifecycledays'] = 'Bucket lifecycle rule age (days)';
$string['s3lifecycledays_desc'] = 'If the bucket has a lifecycle rule that expires objects under the prefix above, enter the rule\'s age in days. 0 means there is no rule, or that you do not know of one.

This is a declaration by you, not something the plugin has checked. Reading a lifecycle rule needs a permission this plugin does not ask for, and the rule can be changed in the AWS console at any time without the plugin knowing, so a value read once and shown here would look like a guarantee and would not be one.

It matters because a rule shorter than the retention setting below deletes recordings that the database still believes exist. When this is set, learners are not told that a recording is kept until someone removes it, because the bucket is going to remove it.';
$string['s3pathstyle'] = 'Use path style addressing';
$string['s3pathstyle_desc'] = 'Address an object as endpoint/bucket/key instead of bucket.endpoint/key. Amazon S3 does not need this. Most self hosted S3 compatible services, including MinIO, do.';
$string['s3prefix'] = 'Key prefix';
$string['s3prefix_desc'] = 'Every object this plugin writes goes under this prefix, and the plugin will only read and delete objects under it. A site moving from the Soapbox activity in the AI Course Assistant plugin sets this to the prefix its existing objects are already under, so the migration stays metadata only and no bytes are copied.';
$string['s3region'] = 'Region';
$string['s3region_desc'] = 'The region the bucket is in, for example us-east-1. It forms part of the request signature, so a wrong region fails every upload rather than being slow.';
$string['s3secret'] = 'Secret access key';
$string['s3secret_desc'] = 'The secret for the access key above. It is stored in the Moodle database and is not written to the configuration change log.';
$string['scoring_confidence_high'] = 'high';
$string['scoring_confidence_low'] = 'low';
$string['scoring_confidence_medium'] = 'medium';
$string['scoring_evidencedetail'] = 'Confidence: {$a->confidence}. Frames that could not be read: {$a->unusable} of 6.';
$string['scoring_task'] = 'Score a PresenterAI recording';
$string['selftest:byteservingoff'] = 'Byte serving is off ($CFG->disablebyteserving), so a learner cannot seek within a recording and the whole file is sent before playback starts.';
$string['selftest:byteservingon'] = 'Byte serving is on, so learners can seek within a recording. X-Sendfile handler: {$a}.';
$string['selftest:lock'] = 'Locking is available, using {$a}.';
$string['selftest:phplimits'] = 'Measured under {$a->sapi}: upload_max_filesize {$a->uploadmax}, post_max_size {$a->postmax}, memory_limit {$a->memory}, max_input_time {$a->inputtime}, max_execution_time {$a->exectime}. Chunk size offered: {$a->chunk}. Run this from a browser rather than the command line to see the numbers a learner actually meets.';
$string['selftest:roundtrip'] = 'Wrote a file to Moodle file storage, read the same bytes back and deleted it.';
$string['selftest:staging'] = 'Wrote and read back a staging file in {$a}.';
$string['setting_combination_warning'] = 'Recordings on this site are deleted after {$a} days and learners cannot download them, so a learner has no way to keep their own presentation. The activity tells them this in plain words before they record, because they would otherwise find out after the recording had gone. Scores, written feedback and transcripts are not affected and are kept. If you want the short retention window but not that outcome, turn learner download back on.';
$string['slide_counter'] = 'Slide {$a->current} of {$a->total}';
$string['slide_design_note'] = 'Slide design:';
$string['slide_next'] = 'Next slide';
$string['slide_prev'] = 'Previous slide';
$string['slidesenabled'] = 'Slides';
$string['slidesenabled_help'] = 'Learners may present a PDF slide deck and advance it while recording. Playback re-syncs the slides with the recording. A deck is optional: a learner can still record without one.';
$string['slidevision'] = 'Slide design feedback';
$string['slidevision_help'] = 'Sends images of the learner\'s slides to the AI service for one short comment on their visual design, added to the overall feedback. Only used when the activity has slides. It\'s off on a site that scores with Moodle core AI, because slide images are never sent to another service there.';
$string['speakinglevel'] = 'Speaking level';
$string['speakinglevel_help'] = 'Sets the built-in criteria and the tone of the feedback. The English as a second language levels weigh pronunciation, fluency, grammar and vocabulary for that level and do not mark down an accent. A rubric chosen below, or defined for this activity or course, replaces the built-in criteria but keeps the tone.';
$string['status_abandoned'] = 'Not submitted';
$string['status_failed'] = 'Could not be scored';
$string['status_scored'] = 'Scored';
$string['status_scoring'] = 'Being scored';
$string['status_uploaded'] = 'Submitted';
$string['status_uploading'] = 'Uploading';
$string['stop'] = 'Stop';
$string['storageheading'] = 'Storage';
$string['storageheading_desc'] = 'Where recordings are kept. Each recording remembers the storage it was written to, so changing the backend applies to recordings made from now on and moves nothing that already exists. Recordings on the other backend keep working, which also means the settings for a backend must stay filled in for as long as any recording still uses it.';
$string['storedattempts'] = 'Recordings kept per learner';
$string['storedattempts_help'] = '0 keeps the recording of every attempt. Any other number keeps only that many of a learner\'s newest recordings and deletes the media of older ones. Scores and feedback for the older attempts are kept. When this applies, learners are warned before they record that recording again will delete their oldest recording.';
$string['storevisualevidence'] = 'Keep the AI\'s raw body language note for staff';
$string['storevisualevidence_desc'] = 'When on, the AI\'s raw description of what was visible is stored on the attempt, can be read by staff with the view visual evidence capability, and is included in a data request. When off, it is used to score the criteria and then discarded. Turn it off on a site with no staff who grade, where nothing would ever read it.';
$string['sttapikey'] = 'Transcription API key';
$string['sttapikey_desc'] = 'Sent as a Bearer token to the transcription endpoint. Leave empty for a server that needs no key. When the endpoint is empty the OpenAI key is used instead. Stored encrypted.';
$string['sttendpoint'] = 'Transcription endpoint';
$string['sttendpoint_desc'] = 'A Whisper compatible transcription URL, such as https://example.com/v1/audio/transcriptions. Leave empty to use OpenAI\'s own transcription with the OpenAI key. Moodle core AI can\'t transcribe.';
$string['sttheading'] = 'Transcription';
$string['sttheading_desc'] = 'Every recording is transcribed before it is scored. Transcription always uses the plugin\'s own service, never Moodle core AI.';
$string['sttmodel'] = 'Transcription model';
$string['sttmodel_desc'] = 'whisper-1 for OpenAI; whatever your server expects otherwise.';
$string['sttwarm'] = 'Wake the transcription server when recording starts';
$string['sttwarm_desc'] = 'For a self hosted server that scales to zero. A short request is sent when a learner starts recording, so the server is awake by the time they finish.';
$string['submissions'] = 'Submissions';
$string['taskcleanup'] = 'Delete abandoned uploads and expired recordings';
$string['taskdeleteorphans'] = 'Delete stored recordings left behind by a deleted activity';
$string['taskexpirevisualdata'] = 'Delete expired AI body language notes and withheld feedback';
$string['topic_choose'] = 'Choose a topic';
$string['topic_label'] = 'Topic';
$string['topicfile'] = 'Topic brief (PDF)';
$string['topicfile_help'] = 'An optional PDF for this topic, such as a case study, that learners can open while they prepare. One file per topic.';
$string['topicinstructions'] = 'Topic instructions';
$string['topicsheading'] = 'Topics';
$string['topictitle'] = 'Topic title';
$string['topictitle_help'] = 'When an activity has topics, a learner picks one before recording, and the topic is shown against their attempt. Leave every title empty for an activity with no topics. Clearing the title of an existing topic deletes that topic and its file when you save; attempts already made on it are kept, without the topic name.';
$string['trustedhosts'] = 'Trusted hosts';
$string['trustedhosts_desc'] = 'Hosts allowed to use http or a private address, one per line, for a self hosted service on your own network: for example http://whisper.internal:8000. A scheme or port given must match exactly. Moodle\'s own cURL blocked hosts setting still applies, so a private address may need allowing there too.';
$string['valuenotnegative'] = 'Enter 0 or a positive number.';
$string['videovision'] = 'Body language feedback';
$string['videovision_help'] = 'When on, six still frames are taken from each video recording in the browser and sent to an AI model, which describes the speaker\'s hands, posture, gaze and framing. Learners are told this before they record. The two body language criteria are scored from that description, and a short summary, checked before it is shown, is added to the learner\'s feedback. Not available for audio only activities. On a site that scores with Moodle core AI the frames aren\'t analyzed, so learners get no body language feedback there.';
$string['viewsubmissions'] = 'View submissions';
$string['visual_criterion_withheld'] = 'The written comment for this criterion was not shown, because an automatic check found it did not meet the standard for feedback about a person. This criterion has been left out of your score rather than counted against you.';
$string['visual_disclosure'] = 'Six still frames from this recording are sent to an AI model to give you feedback on your body language and camera presence.';
$string['visual_fallback_bothstrong'] = 'Your gestures and your camera presence both came through clearly in this recording. The comments on those two criteria say what worked.';
$string['visual_fallback_bothweak'] = 'Both visual criteria scored in the lower part of the range for this recording. The two comments below say what to change before your next take.';
$string['visual_fallback_mixed'] = 'Your gestures and your camera presence were both assessed for this recording. The comments on those two criteria say what to keep and what to change.';
$string['visual_fallback_onestrong'] = 'Your {$a->strong} came through clearly. {$a->weak} was the weaker of the two visual criteria here, and the comment on that criterion says what to change next time.';
$string['visual_fallback_partial'] = 'Part of the visual feedback could not be judged from this recording.';
$string['visual_not_analysed'] = 'Body language and camera presence could not be analysed for this attempt. Nothing you did caused this and there is nothing to fix. Those criteria were left out of your score rather than marked down, and your score is worked out from the other criteria.';
$string['visual_not_assessed'] = 'Body language and camera presence were not assessed for this attempt, because no video was recorded or the camera view could not be read. Those criteria were left out of your score rather than marked down. Record with your camera on, with your head, shoulders and hands in the picture, to get feedback on them.';
$string['visual_optedout'] = 'You chose not to have body language feedback for this attempt, so no still frames were taken. Your score is worked out from the remaining criteria.';
$string['visual_summary_heading'] = 'Body language and camera presence';
$string['visual_summary_note'] = 'This summary was written by the AI from six still frames taken from your recording. It describes what those frames showed, so you can see what the body language feedback for this attempt is based on.';
$string['visualdatadays'] = 'Delete AI body language notes after (days)';
$string['visualdatadays_desc'] = 'The AI writes a short note describing what it saw in the still frames, which is what the body language criteria are scored from. The AI\'s raw description of what was visible in a learner\'s recording is deleted after this many days even when recordings are kept, because it is unreviewed model output about a person and nothing reads it once the learner\'s feedback has been written. There is no option to keep it forever and the minimum is 1 day; 0 or less is read as 1. The short summary shown to the learner is feedback and is kept with their score. Default 30.';
$string['visualheading'] = 'Body language feedback';
$string['visualheading_desc'] = 'Each activity chooses whether to give body language feedback. These settings decide what happens to the evidence and to the words a learner is shown. On the core AI scoring route there is no body language feedback at all, because core AI keeps every prompt it is sent and the raw note must not outlive its own clock. The word list checks exist in English only; in every other language the second AI model is the only check.';
$string['visualoptout_label'] = 'Don\'t give me body language feedback on this recording';
$string['visualoptout_note'] = 'If you tick this, no still frames are taken from this recording and nothing about your body language goes to the AI. Your score is then worked out from the remaining criteria, so ticking it doesn\'t cost you any marks.';
$string['visualscored'] = 'Count body language in the score';
$string['visualscored_help'] = 'Off by default, so the two body language criteria are feedback only and add nothing to the score or the grade. Turning this on means an AI model judges a learner\'s body from six still frames and counts that judgement in their grade. A learner who uses a wheelchair, has a tremor, does not make eye contact for reasons of disability or culture, or cannot move their face or hands as the criteria describe can be marked down for who they are rather than for what they did. The scoring prompt tells the model to leave a criterion out rather than score it 0 when the behaviour could not have been shown, but a model cannot reliably tell. Consider also letting learners opt out.';
$string['visualsummaryjudge'] = 'Check body language feedback with a second AI model';
$string['visualsummaryjudge_desc'] = 'Before body language feedback is shown to a learner, a small model checks that it describes what the speaker did and not what the speaker is like. The word list checks that run alongside it exist for English only, so in every other language this is the only check there is. If the model cannot be reached, scoring is tried again later. Leave it on.';
$string['watch'] = 'Watch';
$string['watch_aria'] = 'Watch the recording you made on {$a}';
$string['yourattempts'] = 'Your attempts';
