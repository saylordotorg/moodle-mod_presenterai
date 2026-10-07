# PresenterAI for Moodle (`mod_presenterai`)

An activity module in which a learner records a video or audio presentation in the browser, optionally presents a PDF slide deck while speaking, and receives a transcript, a rubric score and written per criterion feedback.

> **Status: first release, v0.4.0 (beta).** It's ready to try on a test or staging site. It isn't in the Moodle plugins directory yet, the migrator from SOLA's Soapbox feature isn't built yet, and it's English only for now. Releases, each with a ZIP and notes, are on the [releases page](https://github.com/saylordotorg/moodle-mod_presenterai/releases).

## What's new in 0.4.0

- **Teacher review before release** (new, off by default). An activity can hold the AI's score and feedback until a teacher has checked them. See "Teacher review before release" below. DECISIONS.md D28.
- **Long videos transcribe.** OpenAI's transcription service takes files of up to 25 MB, and a long video used to fail as too large. The browser now records a second, audio only track beside every video recording, at about 32 kbps (roughly 100 minutes fits under 25 MB), and transcription uses that. For older recordings, or a browser that couldn't record both, an optional path to ffmpeg lets the server take the audio out of the video, and cut a very long recording into parts that each fit.
- **Moodle core AI keeps learner content in core** (DECISIONS.md D26). When scoring runs on core AI, slide design feedback, body language feedback and the feedback judge are off, even if the site also holds a vendor key. No still frames are taken from recordings, and learners aren't shown the frames disclosure or the body language opt out. Transcription is the one thing still sent out, because core AI can't take audio.
- **Failed attempts** (DECISIONS.md D27, confirmed rather than changed). When AI scoring fails for good, the attempt doesn't use up one of the learner's attempts, and a teacher can still grade it by hand or rescore it from the grading page.
- **Automated code review.** Pull requests get the same Claude code review SOLA uses, and `@claude` in a comment asks for help.

## Demo video

[![PresenterAI demo video](https://img.youtube.com/vi/mg5zABu1o4I/maxresdefault.jpg)](https://youtu.be/mg5zABu1o4I)

[Watch the three-minute demo on YouTube](https://youtu.be/mg5zABu1o4I). It shows 0.4.1 on Saylor University's development site: a teacher adds the activity, picks a rubric preset or builds a custom course rubric, and sets attempt limits and review before release; a learner presents with slides; the teacher reviews the AI's score, changes one criterion and releases it; and the learner reads their feedback and replays the attempt with the slides in sync. It also covers Moodle or S3 storage with automatic deletion, speaking levels for English learners, and the gradebook. The narration is AI-generated.

## What it does

1. The learner opens the activity and picks a topic, if the teacher offered a choice.
2. They optionally upload a PDF slide deck, which is rendered in the page.
3. They record, in the browser, with no plugin or app, using `MediaRecorder`. A timer shows the minimum and maximum length the teacher set. If slides are in use, each advance is recorded against the elapsed time.
4. On stop, the recording uploads. On video attempts with body language feedback on, the browser also samples a handful of still frames into a single small contact sheet (never on a site that scores with Moodle core AI).
5. In the background the recording is transcribed and scored against a rubric. The learner gets an overall comment, a score and written feedback for each criterion, and a few concrete things to do differently next time. On video attempts they also get feedback on body language and camera presence, judged from the sampled frames and from nothing else.
6. Playback shows the recording with the slides re-synced beside it, including when seeking.
7. The score reaches the Moodle gradebook, and a teacher can override it per criterion.

## What makes it different from a generic assignment

- **The feedback is the product.** It is written for a learner who may have nobody to ask, so every criterion names something specific that was observed and one concrete action, rather than a grade and a adjective.
- **Slides stay in sync.** The timeline of slide advances is captured while recording, so playback is a real reconstruction of the presentation rather than a video of a face.
- **Nothing is assessed from evidence that does not exist.** If the visual evidence is too weak to judge body language, those criteria are removed from the prompt entirely and from the denominator, and the learner is told why that part of their feedback is missing. A learner is never scored zero for something that was never looked at.

## Requirements

- Moodle 4.5 or later (`$plugin->requires = 2024100700`).
- PHP 8.1, 8.2 or 8.3.
- Ghostscript, for rendering PDF slide decks. This is core's existing `$CFG->pathtogs` setting, which `mod_assign`'s annotate PDF feature already depends on. Without it, slide decks are unavailable and everything else works.
- A modern browser with `MediaRecorder` support, for recording.
- For AI features, at least one of: Moodle's core AI subsystem configured with a provider, or an API key for Claude, OpenAI or Gemini, plus a Whisper compatible speech to text endpoint. See the AI section below, which explains why those are not interchangeable.
- Optional: ffmpeg on the server (the "Path to ffmpeg" setting). Without it everything works; with it, older or unusual video recordings that are over OpenAI's 25 MB transcription limit can still be transcribed. See "Transcribing long recordings" below.

## Storage

Choose per site:

- **Moodle file storage (the default).** Works with no configuration. Files live in your moodledata, are carried by course backup, are removed when the course or the activity is deleted, and are exported and deleted by the standard privacy tooling. Recordings are uploaded in chunks, because a single POST of a seven minute video exceeds `post_max_size` and the default request body limit of most reverse proxies. The plugin measures the largest chunk your site actually accepts and uses that. If you want object storage economics without changing this setting, `tool_objectfs` works underneath the file API and the plugin neither knows nor cares.
- **An S3 compatible bucket.** Uploads go from the browser straight to the bucket with a presigned URL, so the media never passes through your web servers. You provide bucket, region, key, secret and prefix, plus an endpoint and path style setting for non AWS targets. The bucket must block public access and needs a CORS rule allowing PUT from your Moodle origin. There is a built in self test that checks all of this and reports what it could not check.

One honest difference between the two: a presigned URL is a bearer token, so for its lifetime it works for anyone holding it, whatever happens to the learner's enrolment in the meantime. The plugin keeps playback URLs short lived (300 seconds) to bound that. A Moodle file URL is checked on every request, so access ends immediately.

## Retention

Automatic deletion is an option, not a requirement.

- Set a retention period in days, per site and per activity, and media is deleted after it while the transcript, score and feedback are kept. The learner sees the deletion date on their attempt.
- Or set it to zero and nothing is deleted automatically. The learner is told the recording is kept until removed.

Two things an administrator should know before choosing zero. On Moodle file storage the bytes are real disk in your moodledata, and every course backup that includes user data carries another copy. On an S3 bucket, any lifecycle rule you have on the prefix keeps deleting whatever this plugin's setting says, and the plugin cannot read your lifecycle configuration to warn you accurately, so it warns you generically.

Model written observations about a learner's body language expire on their own clock, which defaults to 30 days and applies even when media retention is off.

## Grading, completion and privacy

A teacher can score any attempt by hand, and can override the AI's score on any criterion.

- **Manual teacher scoring.** The Submissions page (`report.php`, reached from the activity's settings menu) lists every learner with the status, length and scores of their latest attempt, filtered by group in separate groups mode. From it a teacher opens one attempt on the grading page (`grade.php`), watches it with the slides synced, reads the transcript, and scores each criterion of the rubric, with a live total. A criterion can be left unassessed, and it then drops out of the denominator. The rubric is the activity's own, then the nearest one up the context tree, then a built-in five criterion speaking rubric. The visual evidence note is shown only to roles with `mod/presenterai:viewvisualevidence`.
- **Grading method.** When a learner has several scored attempts, the activity's grading method decides what reaches the gradebook: highest, average, first or latest. An attempt still counts after its recording is deleted, because the score is kept. Points and scales both work, a grade of None creates no grade item, and a gradebook override is never overwritten.
- **Outcomes.** If outcomes are enabled on the site, the teacher sets each one by hand on the grading page. Nothing is derived automatically.
- **Completion.** Besides viewing, an activity can require a number of submitted recordings, or an overall score of at least a percentage. The score rule reads the activity's own aggregate, so it works with no grade item, and a gradebook override doesn't change it.
- **Messages.** A learner is told when an attempt has a score, and is warned a set number of days (the `deletewarndays` setting) before a recording reaches its deletion date, with a note on whether they can download a copy first. Both are ordinary message providers, so learners can change how they receive them.
- **Privacy.** The privacy provider exports each attempt's details, transcript, visual evidence note and every score, plus the media itself on Moodle file storage. For media in an S3 bucket the export carries a note rather than the bytes. Deleting a learner's data deletes the storage objects before the rows. A grader's identity is removed from the scores they gave, which belong to the learner.
- **Course reset.** An option on the reset form deletes every recording, its media and its scores in the course, and resets grades. Topics, rubrics and settings stay. AI spend rows are kept without the learner's name so totals still add up.
- **Backup of learner work.** A backup with user data carries attempts, scores and the activity's rubrics. On Moodle file storage the media bytes travel with it. On S3 only the object keys travel, and only a restore on the same site keeps them; elsewhere the attempts arrive without media. When two attempts name the same S3 object after a same-site restore, the object is deleted only when the last attempt naming it goes.

## Teacher review before release

Off by default, and switched on per activity with "Review AI feedback before learners see it" under the AI feedback heading. Sites with no teacher in the loop, Saylor among them, leave it off.

When it's on, the AI still scores each recording, but the score and all of its feedback, including the body language summary and the tips, are held. Until a teacher releases the attempt:

- the learner sees "Awaiting review" and the sentence "Your feedback is being reviewed by your teacher." in place of their score and feedback;
- nothing goes to the gradebook, completion that needs a grade, a passing grade or a minimum score waits, and the learner isn't sent a message.

On the Submissions page each held attempt is marked "awaiting review", with a count above the table and a "Release all awaiting review" button that asks for confirmation and releases every held attempt of the learners you can see in the current group. On an attempt's grading page you can release the AI's score and feedback as they are with "Release to learner", or change them in the usual form, whose button reads "Save and release": a teacher's own grade is always released when it's saved. Releasing pushes the grade, updates completion, sends the learner their message and logs a "Feedback released" event.

Rescoring an attempt puts it back in review, and takes its grade out of the gradebook until it's released again, as `mod_assign` does with marking workflow. Turning the setting off releases everything that's held straight away. Releasing needs the existing `mod/presenterai:grade` capability; there's no separate one, because a grader can already release by saving their own grade. A held score is included in a privacy export, marked as not yet released.

## Transcribing long recordings

OpenAI's transcription endpoint accepts files of up to 25 MB. To stay under it, the recorder runs a second, audio only recorder on the same microphone during every video recording, at about 32 kbps (Opus where the browser supports it), and uploads it beside the video. Transcription then uses, in order:

1. the separate audio track, when the attempt has one;
2. the recording itself, for an audio only activity, which is small already and isn't recorded twice;
3. for a video with no audio track (recorded before 0.4.0, or by a browser that couldn't run two recorders): the audio taken out of the video by ffmpeg, when an administrator has set "Path to ffmpeg";
4. the video itself, when it's under 25 MB;
5. otherwise the attempt fails as too large, which the settings page warns about in advance.

When the file chosen is still over 25 MB (a very long recording) and ffmpeg is available, it's cut into parts that each fit, transcribed in order and joined. Without ffmpeg the attempt fails as too large rather than being sent. A self hosted transcription endpoint has no such limit, so files go to it whole.

The audio track is stored, deleted, pruned, exported, backed up and restored with the video, on both storage backends. It's never offered for playback or download.

## AI back ends

You can point the scoring step at Moodle core's AI subsystem, or at Claude, OpenAI or Gemini configured directly in the plugin.

**Read this part before choosing core AI.** Moodle core's AI subsystem, on every version from 4.5 through the current development branch, provides text actions only. It cannot transcribe audio and it cannot accept an image. That is not a configuration gap, there is no action class that takes a file or an image, and a plugin cannot add one without patching core.

So: choosing Moodle core AI chooses the back end for **rubric scoring and written feedback**, and it also keeps everything else inside core. On the core route there's no body language feedback, no slide design feedback and no second model checking the feedback, even when a Claude, OpenAI or Gemini key is also set: learners are scored on what they said and on the text of their slides (DECISIONS.md D26). The browser takes no still frames there, and nothing on the learner's page mentions frames or body language analysis. This applies whenever scoring ends up on core AI, including "Automatic" on a site with no vendor key for scoring. The settings page lists what's off. Transcription is the one exception: it always goes to a Whisper compatible speech to text endpoint you configure in this plugin, because core can't take audio. If you configure core AI and nothing else, the activity has no way to produce a transcript, so it has no way to produce a score, and it will tell you that on the settings page rather than failing when a learner submits.

On the other routes, body language and slide design feedback use a vision capable model you configure in this plugin.

Other things worth knowing:

- Core AI's rate limits are set per provider and shared with every other AI consumer on your site. This plugin cannot raise or bypass them, and it reports a rate limit as a retryable condition rather than a failed attempt.
- Core AI's per user policy acceptance is not enforced by core on the plugin's behalf. If you want that consent step, enable it in this plugin's settings.
- All AI work runs in a scheduled or adhoc task, never in the learner's web request.

Every AI call is logged with the route, provider, model, token counts, image count, audio seconds and an estimated cost, with a CSV export and an optional monthly spend cap.

## Privacy

The plugin stores, per attempt: the media (until deleted), the separate audio track on video attempts, the slide deck, the still frame sheet on video attempts, the transcript, the per criterion scores and feedback, and the model's observation about body language. All of it is covered by the privacy API: exported on a subject access request, deleted on an erasure request, and removed with the course or the activity. Deleting a learner's data purges the storage objects before the database rows, in that order, so a deleted row never leaves an orphaned object.

Where the media leaves your site: to your chosen storage backend, to your chosen speech to text endpoint, and to your chosen AI provider. Nothing is sent to Saylor.

## Relationship to the SOLA plugin

PresenterAI replaces "Soapbox", a feature inside `local_ai_course_assistant` (the SOLA course assistant plugin). Soapbox is deprecated as of SOLA v7.5.2, its code is removed in v8.0, and its database tables are dropped later, per course, only once a migration has been verified for that course.

A migrator is planned for the next release. It will import existing Soapbox activities, topics, recordings, transcripts, scores and feedback, including attempts whose video has already been deleted. It will be dry run by default, idempotent and reversible, and it won't write anything to the SOLA tables. It will be metadata only for sites that keep their recordings in the same S3 bucket. PresenterAI has no runtime dependency on SOLA: it does not call its code and does not require it to be installed.

## Installation

Download `mod_presenterai.zip` from the [latest release](https://github.com/saylordotorg/moodle-mod_presenterai/releases/latest), then either unzip it into `mod/presenterai` and visit Site administration > Notifications, or install the ZIP through Site administration > Plugins > Install plugins. Then set up transcription and an AI back end under Site administration > Plugins > Activity modules > PresenterAI; the settings page lists anything still missing.

## Contributing

Issues and pull requests are welcome. CI runs `moodle-plugin-ci` (PHPUnit, Behat, phpcs, phpdoc, grunt) against Moodle 4.5 and main on PHP 8.1 through 8.3, and a pull request that does not pass it will not be merged. Every pull request that changes code also gets an automated Claude code review, and mentioning `@claude` in a comment asks Claude about the change; both need the repository's `ANTHROPIC_API_KEY` secret. New behavior needs a test; the test suite is the acceptance gate for every phase in the implementation plan, not a formality after it.

## Licence

GNU GPL v3 or later, the same as Moodle.
