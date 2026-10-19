# Importing Soapbox into PresenterAI: operator runbook

This is how to move a course's SOLA Soapbox work into PresenterAI with
`mod/presenterai/cli/migrate_from_local.php`. Read it all once before the first run.

The command copies Soapbox assignments, topics, every attempt (expired ones too) and every score with its
per-criterion marks, feedback and tips into new PresenterAI activities in the same course. It moves no media:
the new attempts point at the objects Soapbox already stored in S3. It never writes to a SOLA table, including
on rollback.

## Who runs this, and when

- A site administrator with shell access to the Moodle server. The command runs as the site's main admin
  whoever starts it.
- One course at a time, at a quiet hour for that course, after you've told its teachers.
- Not while SOLA's `soapbox_cleanup` task runs. It runs daily at 04:50 server time. Keep clear of roughly
  04:30 to 05:30.
- Not while Soapbox scoring tasks are queued or running. The command checks this and stops if there are any.
- Run it with the web server user, the same way as any Moodle CLI script:

```
sudo -u www-data php mod/presenterai/cli/migrate_from_local.php --course=42
```

## The command

| Option | What it does |
|---|---|
| `--course=<id>` or `--course=all` | Required. `all` means every course the Soapbox tables list. For `--report`, `all` means every course with a report. |
| (no mode) | A dry run. It prints what an import would do and changes nothing. |
| `--apply` | Imports. |
| `--verify` | Compares the import with Soapbox and stores a report row. |
| `--rollback` | Removes what the import made in the course and restores its course page links. |
| `--report` | Prints the stored verification reports. Changes nothing. One course: every report. `all`: the newest per course. |
| `--json` | Prints the same result as one JSON document on standard output, and nothing else. |
| `--help` | Prints the options. |

Use only one of `--apply`, `--rollback`, `--verify` and `--report`.

There's no storage option. `--backend=...` is refused, because the import only works on S3.

An unknown option is refused. The message never repeats what you typed.

Exit codes:

- `0`: done, or a clean dry run.
- `1`: bad usage, a refusal, a failure, or a failed verification.
- `2`: a site-wide precondition isn't met.

A verified assignment that SOLA has gained recordings since still exits `0`. Its numbers are printed.

With `--course=all`, a course that no longer exists is reported and doesn't change the exit code. Named on its
own with `--course=<id>`, it's a refusal and exits `1`.

The output holds ids, counts, codes and messages only. It never prints a transcript, feedback, a learner's
name, a storage key or a secret, so you can paste it into a ticket.

## Before you start

### Install SOLA 7.12.1 or later

SOLA 7.12.1 (build 2026101901) adds the cleanup interlock. With it, SOLA's cleanup skips every recording the
import has taken over, so it can't delete media PresenterAI now owns. The command refuses to run without it.

### Switch Soapbox off for the course in SOLA

Do this before `--apply`. Set the course's own Soapbox setting in SOLA to off (if Soapbox is on site-wide, the
course needs its own override).

SOLA keeps accepting recordings until you do. The import never re-reads an assignment it has finished. A
recording made in Soapbox after that isn't imported. The dry run and the apply report these as "Added to
Soapbox after the import" and "Scored in Soapbox after the import", and verification fails with
`source_recording_not_imported` until they're dealt with.

### Point PresenterAI at Soapbox's bucket

PresenterAI's S3 bucket, region and prefix must be exactly SOLA's Soapbox bucket, region and prefix. See
[The S3 settings rule](#the-s3-settings-rule).

### Check the bucket's lifecycle rules

See [The bucket lifecycle warning](#the-bucket-lifecycle-warning). Do this before the first import on a site.

## Preconditions and how to fix each one

The command checks these first. Any unmet one exits `2` and prints its code in brackets.

| Code | What it means | Fix |
|---|---|---|
| `sourcetables` | SOLA's Soapbox tables aren't on this site. | Nothing to import. If SOLA should be there, install it. |
| `backend` | PresenterAI stores media in Moodle files, not S3. | Set PresenterAI's storage backend to S3 in its settings. |
| `storage` | PresenterAI's S3 bucket, region or prefix differs from Soapbox's. The message prints both sides (never a key). | Set PresenterAI's bucket, region and prefix to exactly Soapbox's values. |
| `interlock` | SOLA doesn't have the cleanup interlock, or its site id formula disagrees with PresenterAI's. | Install SOLA 7.12.1 (build 2026101901) or later. If it's installed and this still fails, SOLA's `presenterai_interlock::site_id()` and PresenterAI's `ids::site_id()` give different values: both must be `sha1(get_site_identifier())`. Don't import until they agree. |
| `scoringqueued` | Soapbox scoring tasks are queued or running. | Wait for cron to finish them (or run cron), then run again. |
| `transaction` | `--apply` was started inside a database transaction. | Only seen when the command is called from other code. Run it on its own. |

Course refusals (exit `1`):

| Code | Fix |
|---|---|
| `nocourse` | The course no longer exists. Its Soapbox rows can't be imported. |
| `capability` | The admin can't add activities to the course. Check the course's role overrides. |
| `sharedscore` | One Soapbox score belongs to more than one recording. Importing would copy it into two attempts. Ask a developer to look at the course's Soapbox rows. |
| `locked` | Another import or rollback is running for the course. Wait for it. |

Assignment refusals (exit `1`, other assignments carry on):

| Code | Meaning |
|---|---|
| `duplicatestandins` | More than one course page link points at the assignment. Remove all but one, then run again. |
| `stale` | The Soapbox assignment or a topic changed after an interrupted import started. It isn't resumed. Roll the course back, then import again. |
| `targetused` | An interrupted import's hidden activity was used or changed since: a learner has an attempt in it, grading was turned on, or someone made it visible. It isn't resumed. Undo that change, or roll back and import again. |
| `targetgone` | The activity an earlier run made was deleted. It's marked failed and never made again. |
| `failed` | An earlier run marked it failed. Roll back the course to clear it. |
| `badstatus` | A recording has a status the import doesn't know. Ask a developer. |
| `scoremissing` | A recording points at a score that can't be read. Ask a developer. |

## Step 1: dry run

```
php mod/presenterai/cli/migrate_from_local.php --course=42
```

It changes nothing: no row, no event, no lock. Read the whole output. Look for:

- **Refusals and unmet preconditions.** Fix them first.
- **The fallback list.** "Assignments going into the course's last section" lists every assignment with no
  course page link to replace. Its new activity lands in the course's last section. No section is ever
  created. Tell the teacher where it'll appear.
- **Course page link decisions.** `rewrite` points the old link at the new activity and hides it from the
  course page. `leave_visible_no_stealth` points it at the new activity but leaves it visible, because the site
  or course doesn't allow activities that are available but not shown. The course page then lists both until
  the teacher removes the link. `leave_unavailable_fallback_last_section` means the link isn't on the course
  page (it may be being deleted), so it's left alone.
- **Unowned speech scores (includes freeform practice).** Speech scores no recording points at. They include
  ordinary freeform speech practice outside any assignment. They're counted and left in Soapbox. This is never a
  reason to stop.
- **Scores that can't be reconstructed.** See [Unreconstructed scores](#unreconstructed-scores).
- **Notes.** For example, a course speaking level PresenterAI doesn't have becomes the general level.

The counts are per course and per assignment: assignments, topics, recordings by status, scores and distinct
learners. A verified assignment isn't read again, so it counts nothing; its line shows what Soapbox gained since.

## Step 2: apply, one course at a time

```
php mod/presenterai/cli/migrate_from_local.php --course=42 --apply
```

Each assignment becomes one PresenterAI activity, with grading off (grade 0) and retention following the
site setting. Each attempt keeps its own deletion date from Soapbox. An expired Soapbox attempt is imported with
its transcript, score and feedback and no media.

No PresenterAI event fires, no message is sent and no grade is pushed for the imported rows.

### The activity stays hidden until it's complete

Each assignment is imported in two steps, each in its own transaction. The first makes the activity (hidden)
and its topics. The second adds every attempt and score, rewrites the course page link, and only then gives the
activity the Soapbox assignment's visibility. In a hidden section it stays hidden.

### Resume after an interruption

Run the same `--apply` again. The output's "Import state" shows where each assignment got to:

- `created`: the activity exists, its topics may not.
- `attached`: the activity and its topics exist, the attempts don't yet.
- `verified`: everything was written. It's skipped from now on.
- `failed`: the activity an earlier run made is gone. It's never made again.

A `created` or `attached` assignment is finished on the next run. It's refused instead (`targetused`) if
someone used or unhid the half-built activity, and refused (`stale`) if the Soapbox assignment or a topic
changed meanwhile.

Running `--apply` twice changes nothing the second time.

## Step 3: verify

```
php mod/presenterai/cli/migrate_from_local.php --course=42 --verify
```

It stores one `presenterai_migreport` row per run, passing or not, with the admin's user id. That row is the
evidence SOLA 8.x reads before it may drop a course's Soapbox tables. `--report` prints them later.

On a site without Soapbox's tables it stops with `sourcetables` (exit `2`) and stores nothing.

### Tier 1: counts

Assignments, topics, recordings of every status (deleted ones too), recordings with surviving media, scores and
distinct learners. Each must be exact.

Tier 1 counts every activity in the course that carries a Soapbox assignment id. That includes one restored
from another site's backup. Such a course fails verification until that activity is removed.

### Tier 2: content, per attempt and per score

The transcript (compared by hash), the deletion date, the score's criteria (count, names in order, marks,
feedback, assessed flags), the overall feedback, Soapbox's own mean, and the session data minus the visual
observation.

### Tier 3: storage

A HEAD request for every media object an imported attempt still holds, the video checked against its size. Up
to 2000 attempts with media, every one is checked. Above that, a fixed sample: the newest tenth, every attempt
Soapbox marked failed, and 2000 more.

### Mismatch codes

Each mismatch is printed with its code and ids only.

| Code | Meaning |
|---|---|
| `sourcetables` | Soapbox's tables aren't there. |
| `count_mismatch` | A tier 1 count differs (`check` names it). |
| `assignment_not_imported` | No import record for the assignment. |
| `assignment_not_verified` | The import of the assignment didn't finish (`state` says where it stopped). |
| `target_missing` | The import finished, but the activity was deleted since. |
| `source_recording_not_imported` | A Soapbox recording with no PresenterAI attempt, usually one made after the import. |
| `recording_not_in_source` | An imported attempt whose Soapbox recording is gone. |
| `transcript_mismatch` | The transcript differs. |
| `expiry_mismatch` | The deletion date differs. |
| `score_missing`, `score_unexpected` | A score on one side only, or pointing at a different Soapbox score. |
| `criteria_count`, `criteria_names`, `criteria_scores`, `criteria_feedback`, `criteria_assessed` | A per-criterion difference. |
| `feedback_mismatch` | The overall feedback differs. |
| `legacymeanscore` | The stored Soapbox mean differs from Soapbox's. |
| `mean_drift` | The rebuilt marks average more than 1 away from Soapbox's 0 to 5 mean: the maxima came from the wrong place. |
| `unreconstructed_inconsistent` | An unreconstructed score has a maximum or a percentage. |
| `rawmax_zero_not_unreconstructed` | A score with assessed criteria and no maximum isn't marked unreconstructed. |
| `legacymeta` | The session data differs (it must equal Soapbox's minus the visual observation). |
| `storage_mismatch` | An object is missing or the wrong size (`column` names the key). |
| `storage_error` | An object couldn't be checked (`status` is the HTTP status). |

### Tier 4: look at it yourself

Log in as admin and use "Log in as", or ask the teacher, to open these learners' views in the new activity:

1. A learner whose attempt still has its video and slides. The video plays, the slides show, the score and
   feedback match Soapbox.
2. A learner whose video was deleted but whose score survived. The attempt says its recording was deleted and
   still shows its score and feedback.
3. A learner whose newest attempt is `failed` in Soapbox. It shows as failed, and the learner's earlier
   attempts are still listed.

Also open one `unreconstructed` score. It shows "N / 5" for each criterion and no percentage, as Soapbox did.

Record what you saw with the report id.

## Unreconstructed scores

Older Soapbox scores didn't store each criterion's maximum. The import rebuilds it from the rubric the score
used, or from the course's speaking-level preset. When the rubric was deleted and no preset matches, the
maximum can't be recovered.

Those scores are imported `unreconstructed`. They keep Soapbox's mark for each criterion and Soapbox's 0 to 5
mean. They get no percentage, because inventing a maximum would fake one. They show "N / 5" per criterion, as
Soapbox showed them.

When the dry run reports any:

- That's expected for old data. It doesn't stop the import or fail verification.
- If the teacher needs a percentage, they can enter their own score in PresenterAI after the import. Do that
  only after you're sure you won't roll back: a rollback refuses an activity with a score the import didn't make.

## Rollback

```
php mod/presenterai/cli/migrate_from_local.php --course=42 --rollback
```

It removes each activity the import made, restores each course page link's original address and visibility,
and deletes every import record. A second rollback does nothing.

Before you roll back:

- Hide the activity, or switch the course's Soapbox and PresenterAI use off. A learner who starts an attempt in
  the instant between the rollback's last check and its delete loses it. The recheck narrows that window, it
  doesn't close it.
- Don't roll back while cron is deleting the course's activities (the `course_delete_modules` task).

What it does and doesn't do:

- It never deletes Soapbox's media. It unlinks the imported attempts from their objects before deleting the
  activity.
- It refuses an activity with grading turned on (`graded`), with attempts learners made after the import
  (`learnerattempts`), or with scores the import didn't make, such as a teacher's score or a rescore
  (`teacherscores`). Those keep their import records, so SOLA's interlock keeps holding their media.
- An activity already queued for deletion is left to that deletion, but its imported attempts are unlinked from
  the media first (`keys_released`). Nothing of Soapbox's is purged.
- An activity that's already gone is cleared: its link is restored and its records removed (`targetgone`).
- A course page link someone pointed elsewhere since is left as they set it (`changed`). One that's gone is
  reported (`missing`).
- It bypasses the recycle bin on purpose. A recycle bin copy would keep learners' transcripts and feedback
  after the rollback.
- Afterwards SOLA owns the media again, and its cleanup resumes for those recordings on its own schedule.

With `--course=all` it covers the courses the Soapbox tables list. If those tables are gone, roll back each
course by its id.

## The S3 settings rule

PresenterAI's bucket, region and prefix must equal Soapbox's exactly. The prefix is compared the way
PresenterAI reads it (no leading slash, one trailing slash).

It can't be relaxed. The import copies nothing: each attempt keeps the exact key Soapbox stored. PresenterAI
only reads and deletes objects under its own prefix in its own bucket. With any difference, every imported video
would be unreachable, and PresenterAI couldn't delete it later either.

The S3 endpoint and path-style setting aren't compared. Check them yourself: they must reach the same bucket
Soapbox uses.

## The bucket lifecycle warning

Any age-based expiry rule on the Soapbox prefix deletes objects whatever the interlock says. The interlock only
stops SOLA's own cleanup.

Before the first import, check the bucket's lifecycle rules. A rule on the Soapbox prefix must expire objects
later than PresenterAI's retention, or not at all. Otherwise imported videos vanish while PresenterAI still
lists them.

## What a dry run can't prove

The dry run reads everything and writes nothing. The real run still does things it can't show:

- Core fires `course_module_created` for each new activity. Event monitor rules and other subscribers see it.
- On a course with no gradebook yet, core creates an empty course total grade item. No activity grade item
  and no grade is made.
- Real transactions, locks and the course cache.

That's why you verify after every apply.

## After the import

- **The media belongs to PresenterAI.** Deleting an imported activity deletes its videos from the bucket. If
  SOLA must keep a working video, roll back first instead.
- **A recycle bin copy holds the keys.** A teacher who deleted an imported activity with the recycle bin on may
  have left a copy that still names Soapbox's objects. Restoring it gives an activity with no import record,
  and deleting that activity would purge Soapbox's objects. Roll back first, or purge the recycle bin item.
- **Cron.** PresenterAI's hourly cleanup now handles the imported attempts at their imported deletion dates.
  Learners whose media expires within the deletion warning window (the `deletewarndays` setting, 3 days by
  default) get a warning message, and expired media is deleted.
- **Attempt caps count differently.** Soapbox freed an attempt slot when a recording's media was deleted.
  PresenterAI counts every scored attempt, media or not. A learner near the cap may have fewer attempts left.
- **Maximum length.** An activity's maximum recording length is clamped to PresenterAI's site limit.
- **A learner's own deletion in SOLA doesn't reach PresenterAI.** Deleting data from SOLA's own settings page
  leaves PresenterAI's copy. Use Moodle's privacy tools, or delete the attempt in PresenterAI.
- **Attempts deleted before scoring** show "Deleted on <date>" with the status "Not submitted".
- **A database clone** (a staging copy) shares the site identifier, so it sees the same import records as the
  site it came from.

## What isn't imported

- Topic case PDFs.
- Each attempt's visual observation (the raw body language note). It's dropped on purpose.
- Soapbox's AI token spend.

## Privacy

The import records (`presenterai_migration_map`) hold ids and states only, plus each course page link's
original address. They name no user. The verification reports (`presenterai_migreport`) hold counts, codes,
ids and the admin who ran them. A privacy request for that admin exports their reports, and erasing them keeps
the reports and removes only their user id.
