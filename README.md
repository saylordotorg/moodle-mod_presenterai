# PresenterAI for Moodle (`mod_presenterai`)

An activity module in which a learner records a video or audio presentation in the browser, optionally presents a PDF slide deck while speaking, and receives a transcript, a rubric score and written per criterion feedback.

> **Status: in development. Not released. Do not install this on a production site.**
>
> There is no tagged release, no `install.xml` you should trust to be stable, and no upgrade path between commits. The plan in [`docs/IMPLEMENTATION-PLAN.md`](docs/IMPLEMENTATION-PLAN.md) describes what is being built and in what order. This README describes the plugin as it is intended to work at version 1.0, so that a Moodle administrator can decide early whether it is worth watching. Where something is not built yet, this file says so.

>
> **Built so far (phase 1, on branch `phase1-complete`):** recording in the browser with optional PDF slides, chunked upload to Moodle file storage or a direct upload to an S3 compatible bucket, synced playback, learner download and delete governed by capabilities, the deletion date shown against every attempt, topics with an optional PDF brief, a storage check page that measures the upload chunk size, the hourly cleanup task, `cli/apply_retention.php`, and backup and restore of the activity and its topics. **Phase 2 (on branch `phase2-complete`)** adds teacher scoring against a rubric, the gradebook, outcomes, completion rules, the submissions report, learner score display, messages, the privacy provider, course reset and learner work in course backups; see the section below. **Not built yet:** transcription, scoring and every other AI feature (phase 3).

## Demo video

[![PresenterAI demo video](https://img.youtube.com/vi/Apr4pe9IVxI/maxresdefault.jpg)](https://youtu.be/Apr4pe9IVxI)

[Watch the three-minute demo on YouTube](https://youtu.be/Apr4pe9IVxI): an instructor sets up an assignment, a learner presents with slides, and the learner gets rubric scores, written feedback and synced playback. It was recorded on Saylor University's development site, where the presentation feature still runs inside the SOLA course assistant while this standalone activity is built. The narration is AI-generated.

## What it does

1. The learner opens the activity and picks a topic, if the teacher offered a choice.
2. They optionally upload a PDF slide deck, which is rendered in the page.
3. They record, in the browser, with no plugin or app, using `MediaRecorder`. A timer shows the minimum and maximum length the teacher set. If slides are in use, each advance is recorded against the elapsed time.
4. On stop, the recording uploads. On video attempts the browser also samples a handful of still frames into a single small contact sheet.
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

## Grading, completion and privacy (phase 2)

Phase 2 has no AI. Every score is entered by a teacher.

- **Manual teacher scoring.** The Submissions page (`report.php`, reached from the activity's settings menu) lists every learner with the status, length and scores of their latest attempt, filtered by group in separate groups mode. From it a teacher opens one attempt on the grading page (`grade.php`), watches it with the slides synced, reads the transcript, and scores each criterion of the rubric, with a live total. A criterion can be left unassessed, and it then drops out of the denominator. Until rubrics can be edited, the rubric is the activity's own, then the nearest one up the context tree, then a built-in five criterion speaking rubric. The visual evidence note is shown only to roles with `mod/presenterai:viewvisualevidence`.
- **Grading method.** When a learner has several scored attempts, the activity's grading method decides what reaches the gradebook: highest, average, first or latest. An attempt still counts after its recording is deleted, because the score is kept. Points and scales both work, a grade of None creates no grade item, and a gradebook override is never overwritten.
- **Outcomes.** If outcomes are enabled on the site, the teacher sets each one by hand on the grading page. Nothing is derived automatically.
- **Completion.** Besides viewing, an activity can require a number of submitted recordings, or an overall score of at least a percentage. The score rule reads the activity's own aggregate, so it works with no grade item, and a gradebook override doesn't change it.
- **Messages.** A learner is told when an attempt has a score, and is warned a set number of days (the `deletewarndays` setting) before a recording reaches its deletion date, with a note on whether they can download a copy first. Both are ordinary message providers, so learners can change how they receive them.
- **Privacy.** The privacy provider exports each attempt's details, transcript, visual evidence note and every score, plus the media itself on Moodle file storage. For media in an S3 bucket the export carries a note rather than the bytes. Deleting a learner's data deletes the storage objects before the rows. A grader's identity is removed from the scores they gave, which belong to the learner.
- **Course reset.** An option on the reset form deletes every recording, its media and its scores in the course, and resets grades. Topics, rubrics and settings stay. AI spend rows are kept without the learner's name so totals still add up.
- **Backup of learner work.** A backup with user data carries attempts, scores and the activity's rubrics. On Moodle file storage the media bytes travel with it. On S3 only the object keys travel, and only a restore on the same site keeps them; elsewhere the attempts arrive without media. When two attempts name the same S3 object after a same-site restore, the object is deleted only when the last attempt naming it goes.

## AI back ends

You can point the scoring step at Moodle core's AI subsystem, or at Claude, OpenAI or Gemini configured directly in the plugin.

**Read this part before choosing core AI.** Moodle core's AI subsystem, on every version from 4.5 through the current development branch, provides text actions only. It cannot transcribe audio and it cannot accept an image. That is not a configuration gap, there is no action class that takes a file or an image, and a plugin cannot add one without patching core.

So: choosing Moodle core AI chooses the back end for **rubric scoring and written feedback**. Transcription always goes to a Whisper compatible speech to text endpoint you configure in this plugin, and body language feedback always goes to a vision capable model you configure in this plugin. If you configure core AI and nothing else, the activity has no way to produce a transcript, so it has no way to produce a score, and it will tell you that on the settings page rather than failing when a learner submits.

Other things worth knowing:

- Core AI's rate limits are set per provider and shared with every other AI consumer on your site. This plugin cannot raise or bypass them, and it reports a rate limit as a retryable condition rather than a failed attempt.
- Core AI's per user policy acceptance is not enforced by core on the plugin's behalf. If you want that consent step, enable it in this plugin's settings.
- All AI work runs in a scheduled or adhoc task, never in the learner's web request.

Every AI call is logged with the route, provider, model, token counts, image count, audio seconds and an estimated cost, with a CSV export and an optional monthly spend cap.

## Privacy

The plugin stores, per attempt: the media (until deleted), the slide deck, the still frame sheet on video attempts, the transcript, the per criterion scores and feedback, and the model's observation about body language. All of it is covered by the privacy API: exported on a subject access request, deleted on an erasure request, and removed with the course or the activity. Deleting a learner's data purges the storage objects before the database rows, in that order, so a deleted row never leaves an orphaned object.

Where the media leaves your site: to your chosen storage backend, to your chosen speech to text endpoint, and to your chosen AI provider. Nothing is sent to Saylor.

## Relationship to the SOLA plugin

PresenterAI replaces "Soapbox", a feature inside `local_ai_course_assistant` (the SOLA course assistant plugin). Soapbox is deprecated as of SOLA v7.5.2, its code is removed in v8.0, and its database tables are dropped later, per course, only once a migration has been verified for that course.

This plugin ships a migrator that imports existing Soapbox activities, topics, recordings, transcripts, scores and feedback, including attempts whose video has already been deleted. It is dry run by default, idempotent, reversible, and it writes nothing to the SOLA tables. It is metadata only for sites that keep their recordings in the same S3 bucket. PresenterAI has no runtime dependency on SOLA: it does not call its code and does not require it to be installed.

## Installation

Not yet. There is no release. When there is, the usual two routes will work: unpack into `mod/presenterai` and visit the notifications page, or install the ZIP through Site administration.

## Contributing

Issues and pull requests are welcome. CI runs `moodle-plugin-ci` (PHPUnit, Behat, phpcs, phpdoc, grunt) against Moodle 4.5 and main on PHP 8.1 through 8.3, and a pull request that does not pass it will not be merged. New behaviour needs a test; the test suite is the acceptance gate for every phase in the implementation plan, not a formality after it.

## Licence

GNU GPL v3 or later, the same as Moodle.
