# PresenterAI (`mod_presenterai`) implementation plan

**Status:** founding plan, nothing built yet. Repository `https://github.com/saylordotorg/moodle-mod_presenterai`, empty at the time of writing.
**Written:** 20 September 2026, against SOLA `local_ai_course_assistant` v7.5.2 (`version.php:28,32`: `$plugin->version = 2026091900`, `$plugin->release = '7.5.2'`) and the Moodle 4.5 checkout at `/Users/tom.caswell/Sites/moodle`.
**Supersedes:** `moodle-mod_soapbox/docs/IMPLEMENTATION-PLAN.md`, which was written for a plugin called `mod_soapbox` against SOLA 7.5.0, before the rename and before the two product requirements in section 1.3.

## How to read this document

Everything asserted about existing code carries a `file:line` reference and was read, not remembered. Where a fact could not be established it appears in section 9 as an open question rather than as a plausible guess.

Four parallel investigations fed this plan: what carries from the prior design (A), what Moodle core AI can actually do (B), the dual storage backend (C), and the migration (D). Where two of them disagreed, the disagreement is named inline and resolved, and section 10 indexes every one of those resolutions so nobody has to take the resolution on trust.

---

## 1. What PresenterAI is

A learner opens the activity, records a video or audio presentation in the browser, optionally uploads a PDF slide deck and advances it while speaking, and the plugin transcribes the recording, scores it against a rubric, and gives back per criterion written feedback. On video attempts it also samples still frames from the recording and gives feedback on body language and camera presence.

That is what Soapbox does today inside SOLA (`local_ai_course_assistant`). PresenterAI is the same product as a standalone Moodle activity module, and it is different from Soapbox in three ways that shape almost every decision below.

### 1.1 It is an activity, not a feature inside a chat plugin

Soapbox is a set of pages hanging off a `local` plugin, reachable from a course only through a `mod_url` stand in whose `externalurl` is pattern matched back to an assignment id (`classes/soapbox_course_link.php:76-82`). It has no course module, no module context, no gradebook entry, no backup of learner work, and no place in the activity chooser.

That has a specific and serious consequence: a learner's transcript, score and written feedback exist in `local_ai_course_assistant_sbx_rec` and `local_ai_course_assistant_practice_scores` and nowhere else in Moodle. I verified this rather than taking the prior plan's word for it. A grep across the plugin's `classes/` and `lib.php` for `grade_update`, `grade_item` and `gradebook` returns two hits, both unrelated to Soapbox: `classes/analytics.php:1216` joins `{grade_items}` for reporting, and `classes/external/generate_quiz.php:594` fetches grade items to build quiz context. There is no `grade_update()` call anywhere in the Soapbox path.

As an activity, PresenterAI gets a module context, capabilities, backup and restore, the privacy API, completion, events, and a real gradebook item. Section 6 covers the grade; it is the single largest functional gain.

### 1.2 Storage is pluggable and retention is optional

Soapbox assumes an S3 bucket (`classes/soapbox_storage.php:38,41`: default bucket `archive-course`, default prefix `soapbox/`) and hard wires a retention clock (`classes/soapbox_config.php:38,41`: `DEFAULT_RETENTION_DAYS = 7`, `RETENTION_MAX_DAYS = 28`, with `clamp_retention_days()` at `:103-118` forcing any value into the range 1 to 28).

PresenterAI supports either an S3 compatible bucket or Moodle's own file storage, chosen per site, and automatic deletion is an option rather than a requirement. Section 4 is the abstraction; section 3 shows why that costs almost nothing in the schema and rather more everywhere else.

### 1.3 The AI backend is chooseable

Soapbox calls one OpenAI compatible chat endpoint and one Whisper compatible transcription endpoint. PresenterAI lets a site choose Moodle core's AI subsystem, or Claude, OpenAI or Gemini configured directly.

Section 5 is that abstraction, and it is honest about the part that is easy to get wrong: **Moodle core's AI subsystem cannot transcribe audio and cannot accept an image**, on any branch from 4.5 through 5.3beta. Core AI is a back end for the scoring step only. Everything else always runs through the plugin's own client.

---

## 2. Scope for v1.0

### 2.1 What ships

**The learner experience**

- Record video or audio in the browser, with a minimum and maximum length, a live timer, a near maximum warning, and a quality preset chosen by the site.
- Upload a PDF slide deck, advance it while recording, and have the advances captured as a timeline so playback re-syncs the slides to the media.
- Playback of an attempt with the slides beside the video, seeking included.
- Automatic transcription, rubric scoring, per criterion written feedback, overall comment, and next time tips.
- Body language and camera presence feedback on video attempts, from still frames sampled in the browser.
- An explicit "this part was not assessed and here is why" message when the visual criteria could not be judged, because a self paced learner with nobody to ask reads silence as a mark they cannot locate.
- Download of their own recording while it still exists.
- A visible deletion date per attempt when the site deletes, and a plain "kept until removed" statement when it does not.
- A message when scoring finishes, instead of reloading the page hopefully.

**The teacher and admin experience**

- A real activity in the chooser, with the standard settings form, grade section and completion section.
- A gradebook item, with `highest`, `latest`, `average` or `first` across attempts, teacher override, and core's `overridden` flag respected.
- A submissions report and a per attempt grading screen with the slide synced player.
- A context scoped rubric editor for both the speech rubric and the video rubric.
- Backup, restore, course reset, and a full privacy provider.
- Storage backend choice, retention choice, AI route choice, a storage self test, and a spend table.
- The migrator from Soapbox, with a dry run, a per course verification report, and a rollback.

### 2.2 What does not ship in v1.0, and why

| Not in v1.0 | Why |
|---|---|
| Server side ffmpeg frame sampling | ffmpeg is not on the Saylor dev fleet (DECISIONS.md, "On question 10"), and the browser sampler shipped in SOLA v7.5.1 and works (`amd/src/soapbox_frames.js`). A second sampler that almost nobody can run is maintenance with no user. Revisit if a site asks. |
| `FEATURE_ADVANCED_GRADING` (core `gradingform_rubric`) | Our rubric carries per criterion prompt text the model reads. Mapping it onto core's grading form definitions loses that text. Obvious v2. |
| Moodle mobile app support (`db/mobile.php`) | The app cannot send a session cookie to `pluginfile.php`, so the filesystem backend would need token URLs, which are bearer URLs and undo part of the revocation advantage in 4.5. Needs a decision first (section 9.10). |
| Migration into the Moodle file storage backend | The S3 to S3 migration moves no bytes. A migration into file storage is a byte copy of every surviving object into moodledata, a different operation with different failure modes. v1 refuses `--backend=fs` with a clear message. Both investigation A and investigation D reached this independently. |
| S3 multipart upload with resume | The current single presigned PUT with client retry works (`amd/src/soapbox_uploader.js:51-73`). Resume is built for the filesystem backend because it has no alternative; adding it to S3 is a later improvement, not a correctness fix. |
| Joining PresenterAI spend into SOLA's `token_analytics.php` | SOLA v8.0.0 removes the Soapbox code, so that bucket disappears regardless. PresenterAI ships its own spend table and CSV export. |
| A per learner consent gate for visual analysis | Recorded decision, see DECISIONS.md. Disclosure text ships; a blocking gate does not. Note that in core AI mode, core imposes its own policy acceptance, so "no consent gate" is only achievable on the direct route (section 5.6). |
| Global search over transcripts | Nice, not load bearing, and it widens the privacy surface. Deferred. |

---

## 3. Database schema

Eight tables. Moodle caps a table name at 53 characters (`lib/xmldb/xmldb_table.php:41,50`: `PREFIX_MAX_LENGTH = 10`, `NAME_MAX_LENGTH = 63 - 10`), so the long names below are legal, but generated index names are truncated at 30 (`lib/ddl/sql_generator.php:128`), which is why key names are kept short.

### 3.1 `presenterai`, the activity instance

Replaces `local_ai_course_assistant_sbx_assign` (`db/install.xml:709-739`).

```xml
<TABLE NAME="presenterai" COMMENT="One PresenterAI activity instance.">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="course" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="name" TYPE="char" LENGTH="255" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="intro" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="introformat" TYPE="int" LENGTH="4" NOTNULL="true" DEFAULT="1" SEQUENCE="false"/>
    <FIELD NAME="ptype" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="informative" SEQUENCE="false"/>
    <FIELD NAME="mode" TYPE="char" LENGTH="10" NOTNULL="true" DEFAULT="video" SEQUENCE="false"/>
    <FIELD NAME="minseconds" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="300" SEQUENCE="false"/>
    <FIELD NAME="maxseconds" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="420" SEQUENCE="false"/>
    <FIELD NAME="maxattempts" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="storedattempts" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="2" SEQUENCE="false"/>
    <FIELD NAME="rubricid" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="speakinglevel" TYPE="char" LENGTH="20" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="slidesenabled" TYPE="int" LENGTH="1" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="slidevision" TYPE="int" LENGTH="1" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="videovision" TYPE="int" LENGTH="1" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="retentiondays" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="-1" SEQUENCE="false"/>
    <FIELD NAME="grade" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="gradingmethod" TYPE="char" LENGTH="10" NOTNULL="true" DEFAULT="highest" SEQUENCE="false"/>
    <FIELD NAME="completionsubmit" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="completionminscore" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="legacyassignid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="course" TYPE="foreign" FIELDS="course" REFTABLE="course" REFFIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="legacyassign" UNIQUE="false" FIELDS="legacyassignid"/>
  </INDEXES>
</TABLE>
```

Reasoning:

- `course`, not `courseid`, because that is the core convention for an activity instance and `create_module()` expects it.
- `visible` is gone. Module visibility is `course_modules.visible` now, and so is everything `classes/soapbox_course_link.php` existed to fake.
- `usermodified` is gone. It exists at `db/install.xml:727` and nothing reads it. Do not carry a column with no reader into a new schema.
- `rubricid` and `speakinglevel` are carried because PresenterAI actually reads them. In SOLA the stored video rubric path is partly dead: `classes/rubric_manager.php:270-289` documents that a stored rubric of `type = 'video'` is never read in live behaviour, so the shipped behaviour is always the speech criteria plus the hard coded `VISUAL_CRITERIA` at `classes/rubric_manager.php:80-100`. Making the video rubric a real, stored, editable row is a genuine improvement, and it needs a test that the `visual` flag survives a save and reload.
- `videovision` is a per instance opt in. **This reverses a decision SOLA took deliberately**: `classes/soapbox_gesture_vision.php:91-107` has no per assignment opt in, on the reasoning that "requiring every assignment to opt in would mean most learners never receive the feedback this release exists to give". In an activity a teacher configures the instance anyway, so the objection does not transfer, but the reversal is recorded rather than slipped in. Forced off when `mode = audio`.
- `retentiondays` at instance level, where `-1` means "use the site setting", `0` means keep forever, and N means N days. See section 4.5.
- `grade` follows the core convention: 0 means ungraded and no grade item is created at all, positive is a point maximum, negative is a scale id. Every migrated instance is created with 0. That is the single most important safety choice in section 7.
- `legacyassignid` is indexed. The prior plan had the column and no index, and every idempotency and verification query filters on it.

### 3.2 `presenterai_topic`

```xml
<TABLE NAME="presenterai_topic" COMMENT="Instructor-authored topic choices for one instance.">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="presenteraiid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="title" TYPE="char" LENGTH="255" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="instructions" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="instructionsformat" TYPE="int" LENGTH="4" NOTNULL="true" DEFAULT="1" SEQUENCE="false"/>
    <FIELD NAME="sortorder" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="legacytopicid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="presenteraiid" TYPE="foreign" FIELDS="presenteraiid" REFTABLE="presenterai" REFFIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="instsort" UNIQUE="false" FIELDS="presenteraiid, sortorder"/>
    <INDEX NAME="legacytopic" UNIQUE="false" FIELDS="legacytopicid"/>
  </INDEXES>
</TABLE>
```

Reasoning:

- `legacytopicid` is new relative to the prior plan and it is required. Without it a re-run of the migrator cannot remap a recording's `topicid`, and topic titles are not unique enough to match on.
- The case study PDF becomes a real file in a `mod_presenterai/topicfile` area, itemid = topic id, which is what makes backup, restore and course copy carry it. **It is a new feature, not a migration.** `sbx_topic.pdf_itemid` (`db/install.xml:745`) is written by `classes/soapbox_assignment_manager.php:304,316` and read by nothing: a repo wide grep returns exactly three hits, those two writes and the column creation at `db/upgrade.php:1236`. There is no `get_file_storage()` call anywhere in the Soapbox code, no file manager element in `soapbox_assign_edit.php`, and `lib.php:46-49` serves only the `customavatars` area. Building the file area is fine. Writing migration code for it would migrate zero files. Subject to one production query (section 9.13).

### 3.3 `presenterai_recording`, one attempt

Replaces `local_ai_course_assistant_sbx_rec` (`db/install.xml:760-790`).

```xml
<TABLE NAME="presenterai_recording" COMMENT="One attempt: its media, its lifecycle and its transcript.">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="presenteraiid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="topicid" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="attemptnumber" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="1" SEQUENCE="false"/>
    <FIELD NAME="mode" TYPE="char" LENGTH="10" NOTNULL="true" DEFAULT="video" SEQUENCE="false"/>
    <FIELD NAME="backend" TYPE="char" LENGTH="10" NOTNULL="true" DEFAULT="fs" SEQUENCE="false"/>
    <FIELD NAME="storagekey" TYPE="char" LENGTH="255" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="deckkey" TYPE="char" LENGTH="255" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="frameskey" TYPE="char" LENGTH="255" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="uploadid" TYPE="char" LENGTH="64" NOTNULL="true" DEFAULT="" SEQUENCE="false"/>
    <FIELD NAME="slidetimeline" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="visualevidence" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="visualevidenceat" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="durationseconds" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="sizebytes" TYPE="int" LENGTH="19" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="status" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="uploading" SEQUENCE="false"/>
    <FIELD NAME="transcript" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="scoreid" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="expiresat" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="mediadeletedat" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="legacyrecid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="legacyscoreid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="presenteraiid" TYPE="foreign" FIELDS="presenteraiid" REFTABLE="presenterai" REFFIELDS="id"/>
    <KEY NAME="userid" TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="instuser" UNIQUE="false" FIELDS="presenteraiid, userid"/>
    <INDEX NAME="statusexp" UNIQUE="false" FIELDS="status, expiresat"/>
    <INDEX NAME="storagekey" UNIQUE="false" FIELDS="storagekey"/>
    <INDEX NAME="legacyrec" UNIQUE="false" FIELDS="legacyrecid"/>
    <INDEX NAME="visualat" UNIQUE="false" FIELDS="visualevidenceat"/>
  </INDEXES>
</TABLE>
```

Four design choices here need their reasoning spelled out, because three of them are corrections to the prior plan.

**`status` no longer carries `deleted`, and that is a bug fix, not a tidy up.** Today `status` conflates two lifecycles: the scoring pipeline and the media lifecycle. `classes/task/soapbox_cleanup.php:131-138` overwrites a `scored` row with `deleted` when retention fires. The prior plan then says grades are computed over `status = 'scored'` rows only "so a failed transcription never drags a grade down and a retention-deleted-but-scored attempt still counts". The second half of that sentence is false against the status model it ports. Concretely: a learner submits, scores 82 percent, the grade lands, seven days pass, cleanup flips the row to `deleted`, and the next `presenterai_update_grades()` run recomputes over zero `scored` rows and the learner's grade goes to null. On a site with retention off it never happens. At Saylor, with a seven day clock and the gradebook finally real, it happens to every learner in week two.

So `status` keeps only scoring states: `uploading`, `uploaded`, `scoring`, `scored`, `failed`, `abandoned`. "The bytes are gone" is `storagekey IS NULL`, which is already true after a retention run, plus `mediadeletedat` if you want to know when. `abandoned` is for rows that will never be scored: a staged upload that was never finished, and a migrated row whose media expired before it was ever scored. Grades are computed over score rows, never over media existence (section 6).

**`frameskey` is required.** `sbx_rec.frames_key` exists today (`db/install.xml:772`), is minted by `classes/soapbox_storage.php:141` `make_frames_key()`, and holds the composited still frame sheet the body language pass reads. The prior plan has no destination for it, because it assumed ffmpeg produced frames server side into a temp dir and threw them away. The browser sampler uploads the sheet, so it needs a key, a retention rule and a privacy declaration. Without the column, the migration carries a pointer nowhere and the frames outlive the promise made at `classes/task/soapbox_cleanup.php:126-132` that they go on the same clock as the video.

**`visualevidence` gets its own clock.** SOLA v7.5.2 ships `forget_visual_observation()` (`classes/task/soapbox_cleanup.php:169-199`), which strips the model's prose about a named learner's body out of the score row when the video is deleted. That scrub only runs inside `drop_object()` (`:139`). With retention switched off it would never run, and the prose would live forever, which is exactly the state the fix existed to abolish. Hence `visualevidenceat`, set when the prose is written, and a site setting `visualdatadays` (default 30) that expires it regardless of whether media retention is on. This is why the column lives on the recording rather than inside a JSON meta blob on the score, as SOLA stores it at `classes/external/score_speech.php:367-369`: a clock needs an indexable column.

**`expiresat = 0` already means "never".** No schema change is needed for optional retention. The existing cleanup query is `status <> :deleted AND expires_at > 0 AND expires_at <= :now` (`classes/task/soapbox_cleanup.php:56-60`), so zero is already excluded. Nothing writes zero today only because `clamp_retention_days()` forces 1 to 28 (`classes/soapbox_config.php:103-118`) and finalize always writes `now + days * DAYSECS`. Optional retention is one clamp change and one settings change, plus everything in section 4.5 that is not the schema.

On `storagekey` for the filesystem backend, investigations A and C disagreed and the disagreement matters. The prior plan said "the File API pathname hash". That is wrong: `pathnamehash` is a derived SHA1 of the six tuple (contextid, component, filearea, itemid, filepath, filename), it changes when the context changes, and no core activity stores one in its own table. Investigation C proposed `fs:{contextid}/{filearea}/{itemid}/{filename}`, self describing in a support ticket. Investigation A proposed the filename alone. **Resolved in favour of the filename alone**, for a reason neither stated: a course restore gives the module a new context id and a new instance id, so a stored contextid is stale the moment the course is copied, and a key that embeds it becomes a second source of truth that can disagree with the row it sits on. The filename is the only part that is not derivable, so it is the only part stored.

| backend | `storagekey` holds | Lookup |
|---|---|---|
| `s3` | the object key, byte identical to today's `prefix/courseid/userid/token.ext` (`classes/soapbox_storage.php:110-113`) | `presign_get()` |
| `fs` | the filename only, for example `a7f3c1....webm` | `get_file($modulecontextid, 'mod_presenterai', 'recording', $recordingid, '/', $storagekey)` |

Same shape for `deckkey` (area `deck`) and `frameskey` (area `frames`), with `itemid` always the recording id. A null key still means "the media is gone", which is the invariant the retention task, the player and the backup rule all depend on.

### 3.4 `presenterai_rubric`

```xml
<TABLE NAME="presenterai_rubric" COMMENT="Scoring rubric definitions, scoped by context.">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="contextid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="type" TYPE="char" LENGTH="20" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="title" TYPE="char" LENGTH="255" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="criteria" TYPE="text" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="active" TYPE="int" LENGTH="1" NOTNULL="true" DEFAULT="1" SEQUENCE="false"/>
    <FIELD NAME="legacyrubricid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="contextid" TYPE="foreign" FIELDS="contextid" REFTABLE="context" REFFIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="ctxtypeactive" UNIQUE="false" FIELDS="contextid, type, active"/>
    <INDEX NAME="legacyrubric" UNIQUE="false" FIELDS="legacyrubricid"/>
  </INDEXES>
</TABLE>
```

`criteria` is a JSON array of `{name, description, max_score, visual, objectiveid}`. Scoping by `contextid` rather than `courseid` is what removes the shadowing bug in SOLA's `resolve_speech_criteria()`, where `courseid = 0` means "global" and a course row silently wins over a more specific one. Resolution order: an explicit `presenterai.rubricid` wins, otherwise walk `context::get_parent_context_ids($modulecontext, true)` for the nearest active rubric of type `video`, then of type `speech`, otherwise the level preset in code.

The two visual criteria seed from the shipped text at `classes/rubric_manager.php:80-100`, not from the prior plan's draft. The shipped wording names failure modes a learner can act on ("fidgeting, rocking or pacing, hands in pockets or folded, playing with an object, hair or clothing") where the draft described an ideal. Use the shipped text.

### 3.5 `presenterai_score`

```xml
<TABLE NAME="presenterai_score" COMMENT="One scored judgement of one recording, by AI or by a teacher.">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="recordingid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="rubricid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="origin" TYPE="char" LENGTH="10" NOTNULL="true" DEFAULT="ai" SEQUENCE="false"/>
    <FIELD NAME="scores" TYPE="text" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="rawsum" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="rawmax" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="overallpct" TYPE="number" LENGTH="5" DECIMALS="2" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="scoreprovenance" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="exact" SEQUENCE="false"/>
    <FIELD NAME="feedback" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="tips" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="legacymeanscore" TYPE="int" LENGTH="3" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="legacymeta" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="graderid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="recordingid" TYPE="foreign" FIELDS="recordingid" REFTABLE="presenterai_recording" REFFIELDS="id"/>
    <KEY NAME="userid" TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="recorigin" UNIQUE="false" FIELDS="recordingid, origin"/>
    <INDEX NAME="usertime" UNIQUE="false" FIELDS="userid, timecreated"/>
  </INDEXES>
</TABLE>
```

A score is its own table, not columns on the recording, because an AI grade and a teacher grade are two different assertions about the same artefact and both must survive. Collapsing them into `aigrade` and `teachergrade` loses per criterion teacher edits and loses the audit trail on a regrade.

Three columns exist only because of the migration, and they are worth the space:

- **`overallpct` is nullable.** The prior plan had it NOT NULL default 0, and 0 is indistinguishable from "scored zero". Some historical rows have no recoverable denominator (section 7.4) and the honest representation is null.
- **`legacymeanscore`** preserves the source `practice_scores.overall_score` verbatim. That number is the mean of the per criterion scores on the 0 to 5 criterion scale, not a percentage: `classes/rubric_manager.php:369` returns `round($sum / $assessed)`. Copying it into a percentage column turns a learner who scored 4 out of 5 into 4 percent. It is also the only number that is definitely true about a historical row, so it is what makes the reconstruction auditable later.
- **`scoreprovenance`** records how `rawmax` was obtained: `exact`, `rubric_backfill`, `preset_backfill`, `unreconstructed`. Investigation D put this on the recording; it belongs on the score, because it describes the score's derivation and a recording can carry more than one score row over time.

`scores` is JSON `[{name, score, max_score, feedback, assessed}]`. `assessed = false` means "not assessed", which is not zero, and the reader must treat an absent flag as true, matching `classes/rubric_manager.php:320-327`.

### 3.6 `presenterai_aiusage`

```xml
<TABLE NAME="presenterai_aiusage" COMMENT="One row per AI call, for spend attribution.">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="presenteraiid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="recordingid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="action" TYPE="char" LENGTH="32" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="route" TYPE="char" LENGTH="16" NOTNULL="true" DEFAULT="direct" SEQUENCE="false"/>
    <FIELD NAME="provider" TYPE="char" LENGTH="64" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="model" TYPE="char" LENGTH="64" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="prompttokens" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="completiontokens" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="imagecount" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="audioseconds" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="estmicrocents" TYPE="int" LENGTH="19" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
  </FIELDS>
  <KEYS><KEY NAME="primary" TYPE="primary" FIELDS="id"/></KEYS>
  <INDEXES>
    <INDEX NAME="insttime" UNIQUE="false" FIELDS="presenteraiid, timecreated"/>
    <INDEX NAME="usertime" UNIQUE="false" FIELDS="userid, timecreated"/>
  </INDEXES>
</TABLE>
```

`action` is `transcribe`, `score`, `slide_vision` or `video_vision`. SOLA's shipped name for the last one is `gesture_vision` (`classes/soapbox_gesture_vision.php:176`); `video_vision` is the name PresenterAI uses everywhere, and the migrator maps it.

`route` replaces the prior plan's `tier`, whose domain `core | direct` is under specified once there are four AI routes. Values: `core`, `claude`, `openai`, `gemini`, `compatible`. `userid` is always an explicit parameter and never a `$USER` read, because this runs under cron and cron is not the spender.

### 3.7 `presenterai_migration_map` and `presenterai_migreport`

```xml
<TABLE NAME="presenterai_migration_map" COMMENT="One row per migrated source row: idempotency, resume and rollback.">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="runid" TYPE="char" LENGTH="32" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="sourcetable" TYPE="char" LENGTH="64" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="sourceid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="sourcesiteid" TYPE="char" LENGTH="64" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="targetid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="targetcmid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="state" TYPE="char" LENGTH="16" NOTNULL="true" DEFAULT="planned" SEQUENCE="false"/>
    <FIELD NAME="payload" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
  </FIELDS>
  <KEYS><KEY NAME="primary" TYPE="primary" FIELDS="id"/></KEYS>
  <INDEXES>
    <INDEX NAME="srcunique" UNIQUE="true" FIELDS="sourcetable, sourceid, sourcesiteid"/>
    <INDEX NAME="runstate" UNIQUE="false" FIELDS="runid, state"/>
  </INDEXES>
</TABLE>

<TABLE NAME="presenterai_migreport" COMMENT="One row per course per migration run: the verification evidence SOLA 8.0 reads.">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="runid" TYPE="char" LENGTH="32" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="courseid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="counts" TYPE="text" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="mismatches" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
    <FIELD NAME="mismatchcount" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="storagesampled" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="storagefailures" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="passed" TYPE="int" LENGTH="1" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="operatorid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
  </FIELDS>
  <KEYS><KEY NAME="primary" TYPE="primary" FIELDS="id"/></KEYS>
  <INDEXES>
    <INDEX NAME="coursepassed" UNIQUE="false" FIELDS="courseid, passed"/>
  </INDEXES>
</TABLE>
```

The map exists because `legacyassignid` alone is not sufficient, for a reason that will actually happen. `sbx_assign` and `sbx_topic` are carried in course backups (`backup/moodle2/backup_local_ai_course_assistant_plugin.class.php:180,189`) while `sbx_rec` deliberately is not (`:39-42`). So restoring or duplicating a course after migration produces fresh `sbx_assign` rows with new ids, no recordings, and no `presenterai` instance, and a `legacyassignid` only check would see them as unmigrated and create duplicate empty activities in the copy. `sourcesiteid` (`$CFG->wwwroot` plus the site identifier) is what makes a course restored from degrees onto learn recognisable as foreign data rather than as a completed migration.

`presenterai_migreport` is deliberately short of `presenterai_migration_report` (28 characters). The limit is 53 so the long name is legal, but generated key names are truncated at 30 (`lib/ddl/sql_generator.php:128`) and there is no reason to sit near a ceiling.

---

## 4. The storage abstraction

Namespace `mod_presenterai\local\storage`. One interface, two implementations (`fs_store`, `s3_store`), one factory `store_factory::for_backend(string $backend)` plus `store_factory::default_store()`.

### 4.1 The reference object

A bare string key is enough for S3 and not enough for the File API, which addresses a file by six coordinates (`lib/filestorage/file_storage.php:533`). Rather than making `fs_store` reverse engineer a context out of a string, every call carries a reference:

```
media_ref {
    int    $recordingid;   // 0 only during begin_upload
    int    $contextid;     // module context
    int    $courseid;
    int    $userid;
    string $kind;          // recording | deck | frames
    string $ext;           // mp4 | webm | m4a | pdf | jpg
    string $key;           // backend-defined, opaque to callers, stored in the row
}
```

### 4.2 The interface

| Method | `s3_store` | `fs_store` |
|---|---|---|
| `name()` | `'s3'` | `'fs'` |
| `is_configured()` | key, secret, bucket, region present (mirrors `classes/soapbox_storage.php:88-92`) | always true |
| `supports_direct_upload()` | true | false |
| `begin_upload(media_ref)` | mint key, presigned PUT, TTL 900 | mint key, return `upload.php` target with a negotiated `chunkbytes` and an `uploadid` |
| `resume_offset(string $uploadid)` | 0, not supported | bytes already appended to the staging file |
| `accept_chunk($uploadid, $offset, $stream)` | not used | locked stream append, returns the new length |
| `commit_upload(media_ref, $uploadid = '')` | signed HEAD, returns size or null | move the staging file in with `create_file_from_pathname`, returns size |
| `owns($key, $courseid, $userid, $contextid)` | prefix compare, exactly today's check | contextid and itemid compared against the attempt row |
| `size($key)` | signed HEAD | `stored_file::get_filesize()` (`lib/filestorage/stored_file.php:746`) |
| `read_url($key, $ttl, $downloadname = '')` | presigned GET, with `response-content-disposition` signed inside the canonical query | `url::make_pluginfile_url(...)` (`lib/classes/url.php:685`) |
| `fetch_to_file($key, $ext)` | presigned GET plus `curl::download_one` into `make_request_directory()`, as `classes/soapbox_scorer.php:58-65` does today | `file_system::get_local_path_from_storedfile()` when the file system is local (`lib/filestorage/file_system_filedir.php:122`), otherwise `stored_file::copy_content_to_temp()` |
| `read_bytes($key, $maxbytes)` | presigned GET with `CURLOPT_MAXFILESIZE`, as `classes/soapbox_gesture_vision.php:251-262` does | size check then `stored_file::get_content()` |
| `delete($key)` | signed DELETE with the blanked `Authorization` header | `stored_file::delete()` |
| `selftest()` | PUT, HEAD, GET byte compare, DELETE round trip, plus a CORS preflight check and an unsigned GET that must fail | write, read, range read, delete round trip, plus the effective PHP limits measured inside a real HTTP request |

Two notes. `fetch_to_file` on S3 is a real download, so a 65 MB video is pulled to the cron box on every scoring run; on the filesystem it is free when the site uses the stock `file_system_filedir`, and callers must never write to the path it returns. The download variant of `read_url` is a method the prior plan did not have, and it is not the same call on the two backends: on S3 `response-content-disposition` has to be signed inside the canonical query string (`classes/soapbox_storage.php:178-186` is the working precedent for exactly that), and on the File API it is `send_stored_file($file, 0, 0, true)`.

Everything that touches bytes goes through these methods and nothing else: the transcription download (`classes/soapbox_scorer.php:58-65`), the deck fetch in the scorer (`:225-232`) and in playback (`classes/external/soapbox_get_playback.php:90-100`), the frame sheet read (`classes/soapbox_gesture_vision.php:251-262`), and the three deletes in cleanup (`classes/task/soapbox_cleanup.php:116-131`).

### 4.3 One structural change: the row is created before the bytes

Today the `sbx_rec` row is created at finalize (`classes/external/soapbox_finalize_recording.php:219`) and idempotency is keyed on the S3 object key (`:206-218`). The File API needs an `itemid` before the first byte arrives, and an itemid that is not the attempt id would mean a second mapping table.

So `get_upload_url` becomes `begin_attempt`: it checks the attempt cap, inserts the row with `status = 'uploading'`, and returns the recording id plus the upload target. `finalize` updates that row rather than inserting one, which also removes the duplicated cap check finalize needs today (`:108-118`).

The cost is a new failure mode: an abandoned upload leaves an `uploading` row. Two rules handle it. The cap count excludes `uploading` rows, as it already excludes `failed` (`:113`), and the cleanup task moves `uploading` rows older than 24 hours to `abandoned` and deletes any staged bytes.

### 4.4 Upload, and what the PHP limits really cost

Sizes, from the quality presets at `classes/soapbox_config.php:56-69` and the 720 second admin ceiling at `:32`:

| Preset | Total bitrate | 5 min | 7 min | 12 min |
|---|---|---|---|---|
| low_360p | 382 kbps | 14.3 MB | 20.1 MB | 34.4 MB |
| standard_480p (the default, `:44`) | 540 kbps | 20.3 MB | 28.4 MB | 48.6 MB |
| high_720p | 1248 kbps | 46.8 MB | 65.5 MB | 112.3 MB |
| audio only | 40 kbps | 1.5 MB | 2.1 MB | 3.6 MB |

Those are targets, not guarantees: `amd/src/soapbox_recorder.js:287-289` passes them to `MediaRecorder`, and VP8 and VP9 overshoot on high motion. Plan for 15 percent headroom. So the worst configured case is a single body of roughly 130 MB and the default case is about 33 MB.

The binding constraints, in the order they bite: `post_max_size` (8M in `php.ini-production`, applies to the whole body including a raw `php://input` body, and exceeding it empties `$_POST` with a warning rather than a clean error); `upload_max_filesize` (2M, per file part, avoidable by sending a raw body); the reverse proxy limit (nginx `client_max_body_size` defaults to 1m and returns 413 with no PHP involvement, and PHP cannot read it); `max_input_time` (60s, and a learner on a 1 Mbps uplink needs 224 seconds to push 28 MB); `max_execution_time` (30s, applies to the script, so to hashing and moving, not to receiving); and the site, course and module `maxbytes` settings via `get_max_upload_file_size()` (`lib/moodlelib.php:6389`), which a plugin that wants to pass Moodle plugin review must honour. `memory_limit` is not binding as long as no code ever holds the media in a PHP string, which `create_file_from_pathname` does not do.

So chunking is not optional for the filesystem backend:

- **A dedicated `upload.php`, not a web service.** Moodle's AJAX transport is JSON, so binary through it means base64 and a third more bytes. `upload.php` does `require_login($course, false, $cm)`, `require_sesskey()`, `require_capability('mod/presenterai:submit', $context)`, then streams `php://input` to the staging file in 8 KiB reads.
- **Chunk size 512 KiB by default**, which clears nginx's 1m, PHP's 2M and PHP's 8M with room for overhead. On first use per site the probe walks 512 KiB, 1 MiB, 2 MiB, 5 MiB, 10 MiB with a real POST at each rung and caches the largest that returned 200. At 512 KiB a 28.4 MB recording is 56 requests; at 5 MiB it is 6. Three requests in flight. The prior plan's fixed 5 MB chunks fail on stock PHP and on stock nginx.
- **Staging in `$CFG->tempdir . '/presenterai/{uploadid}'`**, which is under `$CFG->dataroot` and therefore shared across cluster nodes (`lib/setup.php:216-218`), so a chunk that lands on another web node still finds its file.
- **Ordering and races.** Every chunk carries `uploadid`, `offset` and `Content-Length`. The handler takes `flock`, compares `filesize()` against the claimed offset, and rejects a mismatch with 409 and the true offset, which is also the resume path after a dropped connection.
- **Commit** verifies the total against the client's declared byte count, runs `\core\antivirus\manager::scan_file()` when an antivirus plugin is enabled (the File API does not scan on its own), then `create_file_from_pathname` into area `recording`, itemid = recording id, and unlinks the staging file.
- **A published ceiling.** `min(get_max_upload_file_size(...), site maxrecordingbytes)`, default 150 MB, shown on the recorder before the learner starts, with a recording blocked from starting when its preset and length ceiling could exceed it. A learner must never get a failure after speaking for seven minutes.

### 4.5 Retention becomes optional

- Site setting `retentiondays`: 0 means keep forever. See section 9.2 for the default.
- Per instance `retentiondays`: -1 means use the site value, 0 means keep forever, N means N days.
- `storedattempts`: 0 means keep every attempt. Today it is forced to at least 1 (`classes/task/soapbox_cleanup.php:93`), so pruning is unconditional.
- `RETENTION_MAX_DAYS = 28` is removed as a hard ceiling. It made sense when the video was a practice artefact. It does not for a site keeping presentations as assessment evidence, which is a use case PresenterAI is explicitly for.

Three consequences that are easy to miss:

1. **Learner facing text is conditional.** The deletion date callout and the privacy notice must not promise deletion on a site that keeps forever. A notice that promises deletion and does not deliver it is a false statement to a learner.
2. **Changing the setting does not rewrite existing rows.** If an admin turns retention on a year later, every existing row has `expiresat = 0`. Backfilling from `timecreated` would delete a year of media on the next cron run; backfilling from now would silently extend. Neither happens implicitly. `cli/apply_retention.php --from=created|now --dry-run` makes the admin choose.
3. **The visual evidence scrub needs its own clock**, per section 3.3.

What "keep forever" costs, honestly. On the filesystem backend the bytes live in `$CFG->dataroot/filedir`, content addressed and deduplicated, removed only on context deletion (`lib/classes/context.php:608-610`), course deletion, a privacy deletion request, or an explicit plugin action. At the default preset, 1,000 learners keeping 2 attempts each is about 57 GB, and every course backup that includes user data multiplies it. Deletion is also not instant: `stored_file::delete()` removes the row immediately (`lib/filestorage/stored_file.php:375-399`) and moves content to trash only when no other file shares the hash (`lib/filestorage/file_system_filedir.php:287-296`), with the trash emptied by `core\task\file_trash_cleanup_task` at most once per `$CFG->filescleanupperiod`, default 86400 (`lib/filestorage/file_storage.php:2314-2316`). The accurate statement is: unreachable within seconds, gone from disk within about 24 hours.

On S3 two hazards are outside the plugin's sight. A bucket lifecycle rule on the prefix keeps deleting whatever PresenterAI's database says: the code asserts one exists as the backstop four times (`classes/soapbox_storage.php:31-32,242`, `classes/task/soapbox_cleanup.php:26-27,121`) and nothing reads it. And on a versioned bucket a delete writes a delete marker and the bytes remain as a noncurrent version. So with the filesystem backend the plugin can say honestly what deletion means; with S3 it can only say what it did, and the bucket policy decides the rest. The settings page says this in plain words when `retentiondays = 0` and the backend is `s3`, and the probe states that it cannot read lifecycle configuration.

### 4.6 Playback, download, and where the backends genuinely differ

On the filesystem backend, `mod_presenterai_pluginfile()` follows `folder_pluginfile()` (`mod/folder/lib.php:261-294`): `require_login($course, false, $cm)`, load the recording row, require `mod/presenterai:viewallattempts` when the requester is not the owner, then `send_stored_file($file, 0, 0, $forcedownload, ['cacheability' => 'private'])`. The `private` cacheability matters: `send_stored_file` defaults to `public` (`lib/filelib.php:2712`, header set at `:2601-2613`), which would let a shared proxy cache a learner's video. Seeking works because `readfile_accel()` sets `Accept-Ranges: bytes` and handles `HTTP_RANGE` (`lib/filelib.php:2255-2258,2286-2300`) unless `$CFG->disablebyteserving` is on, which the probe reports because a site with it on gets a visibly worse player. `$CFG->xsendfile` (`lib/filelib.php:2264-2274`) is worth recommending in the install doc.

On S3, `read_url()` returns a presigned GET with the capability checked at issue time, as `classes/external/soapbox_get_playback.php:76-82` does today.

They are not equivalent and the plugin should not pretend they are. **A presigned URL is a bearer token: once issued it works for its whole lifetime for anyone holding it, regardless of logout, unenrolment, a revoked capability, or the row saying the recording was deleted. A pluginfile URL stops working the instant any of those change.** Four changes narrow the gap:

1. Playback TTL drops to 300 seconds (today it is 3600 at `classes/external/soapbox_finalize_recording.php:216,230` and `classes/external/soapbox_get_playback.php:85`), with the player re-requesting on a 403. Download keeps 900 because the browser has to finish the transfer.
2. `response-cache-control: private, no-store` is signed into the query, merged before the sort and the signature the way `presign_url()` already handles `extraquery` (`classes/soapbox_storage.php:280-292`).
3. The signed URL goes into the web service response and nowhere else. No debug log, no event payload, no analytics row.
4. The bucket must block public access, and the probe fails loudly if an unsigned GET to a known key succeeds.

Recorded rather than hidden: with S3, revocation is eventual with a bound of the TTL. With the filesystem, revocation is immediate.

### 4.7 Switching backends after recordings exist

The `backend` column per row is what makes this survivable, and it has to be respected everywhere:

- The store is resolved **per row**, never once per process. Today the cleanup task builds one storage object up front (`classes/task/soapbox_cleanup.php:50`); the new task calls `store_factory::for_backend($rec->backend)` inside the loop.
- Changing the site setting affects new recordings only. No bytes move, no downtime.
- S3 settings must stay valid and visible while any `backend = 's3'` row survives, and the health check shows a count of rows on the other backend. Clearing the credentials would orphan them silently, because `is_configured()` goes false and the cleanup task's early return (`:47-49`) would skip them forever.
- Backup and restore: a recording restores as media-less (null keys, `mediadeletedat` set, status `scored` or `abandoned`) unless the backup came from the same site with the same backend and, for S3, the same bucket. A filesystem backup carries the bytes inside the mbz; an S3 backup carries only keys.
- `cli/migrate_storage.php --to=fs|s3 [--dry-run] [--limit=N]` exists for a site that decides to leave S3: fetch, put, verify size, update `backend` and `storagekey` in one transaction, then delete the source. Idempotent, resumable, never deletes before the target verifies. It is not part of the Soapbox migration and it is not for Saylor.

### 4.8 The default for a fresh install is `fs`

Because it works with no configuration, keeps no secret at rest in `mdl_config_plugins`, needs no bucket CORS rule whose failure mode is an opaque browser error after the learner has finished speaking, and lets core do the compliance work (context deletion removes the files with no plugin code, backup and restore carry them, the privacy API exports and deletes them through the standard file provider). A site that wants object storage economics can have them without the plugin knowing, through `tool_objectfs` and `$CFG->alternative_file_system_class`, which `file_storage` honours (`lib/filestorage/file_storage.php:74-75`).

Saylor stays on `s3`, because identical bucket, region and prefix is what keeps the migration metadata only. The default and the Saylor setting simply differ, which is what the setting is for.

---

## 5. The AI abstraction

### 5.1 The one thing a reader must not get wrong

**Moodle core's AI subsystem cannot transcribe audio and cannot accept an image.** This is not a 4.5 limitation that goes away on upgrade. On 4.5 the action classes are `base`, `generate_image`, `generate_text` and `summarise_text` (`/Users/tom.caswell/Sites/moodle/ai/classes/aiactions/`), 5.0 through 5.3beta add only `explain_text`, and a `git grep -iE "transcri|whisper|vision|image_url|multimodal|base64_image"` over `public/ai` on the development branch returns zero matches. The action constructors are the entire input surface and all three text actions take exactly `(int $contextid, int $userid, string $prompttext)` (`ai/classes/aiactions/generate_text.php:39-47`). There is no parameter for a file, an image, a model, a temperature, a token ceiling, structured output, or a system prompt. `core_ai\ai_image` is a GD wrapper for watermarking a **generated** image (`ai/classes/ai_image.php:28-60`), not a path for sending one.

A plugin also cannot add its own action: `manager::process_action()` builds the response class by string concatenation from a hardcoded `core_ai\aiactions\responses\response_` prefix (`ai/classes/manager.php:117-121`), and on the development branch the set is pinned in a private constant. A third party `transcribe_audio` action would also require every provider plugin to ship a matching processor. Adding an action means patching core.

So, in plain terms: **choosing "Moodle core AI" in PresenterAI chooses the back end for the scoring step only. Transcription and body language feedback always go through the plugin's own client.** If a site selects core AI and configures no direct provider, it gets neither a transcript nor body language feedback, so it gets no score either, because the score is computed from the transcript. The settings page states this at the point of choosing, and the activity refuses to accept a recording rather than failing at scoring time. Every core AI site will otherwise file the same bug.

### 5.2 The four routes

`mod_presenterai\local\ai\client_interface`: `generate_text(string $system, string $user, array $opts): string`, `supports_images(): bool`, `supports_json_schema(): bool`.

| Route | Class | Scoring | Vision | Transcription |
|---|---|---|---|---|
| `core` | `core_ai_client` | yes | no | no |
| `claude` | `claude_client` | yes | yes | no |
| `openai` | `openai_client` | yes | yes | yes, via `stt_client` |
| `gemini` | `gemini_client` | yes | yes | no |
| `compatible` | `openai_client` with an endpoint override | yes | yes | yes |

The prior plan had two clients, `core_ai_client` plus one OpenAI compatible client said to work "unchanged against OpenAI, Azure, OpenRouter, Ollama, vLLM". That does not cover the requirement: Claude and Gemini are not OpenAI compatible at the wire level (different request shape, different image content parts, different structured output mechanics) and neither is reachable through `\core_ai` on 4.5, where `ai/provider/` holds only `azureai` and `openai`. SOLA already has all four shapes behind `provider_interface.php` in `classes/provider/`. The clean room rule says do not depend on that code at runtime, which stands, but reading it is free and the shapes are known good.

Transcription is always `stt_client`: a multipart POST to a Whisper compatible endpoint with Moodle's own `\curl`, mirroring `soapbox_transcribe.php:142-151` (model `whisper-1` or `grok-stt`, `:121-126`) with a 300 second timeout rather than the chat path's 30.

### 5.3 The default is `auto`, resolved at call time

SOLA's shipped pattern, documented in `.wiki/Roadmap-Moodle-AI-Provider.md`, is worth copying exactly: prefer a configured direct key, fall back to `\core_ai` when it is available and configured, otherwise tell the admin what is missing. The same document records that `\core_ai`'s response data key names shifted across 4.5 to 5.3 and that the adapter tries the known variants (`generatedcontent`, `content`, `response`). PresenterAI will hit the same drift and should copy the defensive parse rather than rediscover it.

### 5.4 Core AI on 4.5 and on 5.x is not one code path

- 4.5: `manager::get_providers_for_actions()` and `is_action_enabled()` are static (`ai/classes/manager.php:70,262`) and `manager` has no constructor. Providers are `get_config()` configured singletons.
- 5.1: `manager` takes a `\moodle_database` and those methods are instance methods; providers are DB rows in `ai_providers` created through `manager::create_provider_instance()`, several instances per plugin, ordered for failover.

`$plugin->requires = 2024100700` (Moodle 4.5), matching SOLA (`version.php:29`), and `core_ai_client` carries one version branch. It is roughly thirty lines and it is cheaper than telling a 4.5 site that the core route does not exist, which would remove the point of the requirement on the only sites we run today. See section 9.4 if that trade is wrong.

### 5.5 PresenterAI does not need to be an AI placement

I checked for a caller allowlist and there is none: `process_action()` never inspects who called it, on 4.5, 5.1 or the development branch, and core itself calls it from non placement code. `is_action_enabled($plugin, $actionclass)` falls through to `get_config($plugin, $basename)` for any non `aiprovider` component name, so `'mod_presenterai'` works as the plugin string.

What PresenterAI gives up by not shipping a companion `aiplacement_presenterai`: it will not appear in the admin AI placements table with the standard per placement action toggles, and on 5.1 and later it will not appear in the per activity "AI tools" section of the module settings form, which is built purely from `manager::get_placement_actions_available()`. The mitigation is cheap and PresenterAI does it anyway: define `mod/presenterai:useai`, and voluntarily honour `manager::is_action_enabled_in_context()` on 5.1 and later so course level and activity level AI switches are respected. A companion placement plugin would have to be installed inside core's `ai/placement/` directory, because `lib/components.json:3` fixes the mapping. That is a policy question (section 9.5).

### 5.6 Rate limiting, policy, and why scoring belongs in a task

**Core's rate limiter is per provider, not per consumer.** `\core_ai\rate_limiter` is a cache backed counter with a fixed one hour window (`ai/classes/rate_limiter.php:30`) enforced in `process_base::process()` before the API call, returning HTTP 429. Both limiters are off by default in 4.5 and, when enabled, default to 100 global and 10 per user per hour. PresenterAI cannot raise or bypass them and shares the budget with every other AI consumer on the site, so it must surface a 429 as a retryable condition rather than a scoring failure.

**PresenterAI needs its own limiter regardless**, and the prior plan had none. SOLA has one on the vision pass (`classes/soapbox_gesture_vision.php:85,88,161`: 10 per 600 seconds per user) and one on transcription (`soapbox_transcribe.php:52`: 12 long form transcriptions per 10 minutes per user). A public activity plugin that presigns uploads and calls a paid model needs this.

**Core does not enforce policy acceptance for you.** `process_action()` does not check it on any branch; `aiplacement_courseassist` gates it client side in its own JS. Core does ship the machinery (`ai/classes/manager.php:206-232` `user_policy_accepted`, capabilities in `lib/db/access.php:2790,2798,2806`). The consequence for the recorded "no consent gate" decision is specific: a site running PresenterAI in core AI mode on a placement style flow gets a consent gate imposed by core; on the direct route there is none unless PresenterAI builds one. "No consent gate" is a direct route property, not a plugin property.

**No timeout is set on the core AI request path.** `abstract_processor::query_ai_api()` passes only `base_uri` and `HTTP_ERRORS => false` to `\core\http_client` and `lib/classes/http_client.php` sets no default. A core AI scoring call is therefore a synchronous request with no deadline, which settles where scoring runs: the adhoc task, never a web request. That is where SOLA already puts it, with the rule worth keeping verbatim: retry transient failures while `get_fail_delay() < 960`, roughly 30 minutes, then fail the row.

### 5.7 Security on admin entered endpoints

Requirement 3 makes an admin typed endpoint URL normal, so the two guards SOLA already has are not optional: `security::is_safe_provider_url()` (called at `soapbox_transcribe.php:133`) and `security::resolve_pin_options()` (`:150`). Shipping a plugin that will POST a learner's audio to whatever host an admin pastes, with no SSRF validation, is not acceptable for the Moodle plugin directory.

### 5.8 Body language: a port, not a design

The prior plan's section 5 is written as new work. It is not. SOLA v7.5.1 shipped it and v7.5.2 runs it in production:

- `classes/soapbox_gesture_vision.php` (300 lines): one descriptive vision pass, `observe(int $recid, int $courseid, int $userid)` at `:116`, prompt at `:196`, 900 character clamp (`MAX_NOTE_CHARS`, `:82`), 8 MB frame ceiling (`MAX_FRAMES_BYTES`, `:79`), rate limit 10 per 600 seconds (`:85,88`), MIME sniff on the fetched bytes (`:255-262`), best effort `\Throwable` swallow.
- `amd/src/soapbox_frames.js` (282 lines): the browser sampler. Six frames, three columns by two rows, 426 by 240 cells, JPEG quality 0.72, composited into one contact sheet, 12 second timeout, sampling the middle 80 percent (`:48-56`, `sampleTimes` at `:73`).
- `classes/external/score_speech.php:144` resolves the video criteria only when there is evidence, `:200-212` builds the VISUAL EVIDENCE prompt block, `:306` is the allowlist that drops a criterion the model invented, `:347-349` tells the learner when the visual criteria were not assessed.

Port all of it, including the allowlist, which the prior plan's manifest is missing. Three things change:

1. The prior plan's numbers are wrong and must be replaced with the shipped ones: 6 frames on one contact sheet, not 9 separate images at 512 px. The cost estimate changes with them, and the plan's own advice to measure the first 50 runs stands.
2. The shipped prompt returns plain prose, four to six sentences (`:198-224`), where the prior plan specified JSON with `confidence` and `unusable_frames`. Those two fields were what drove "drop the visual criteria when the evidence is weak". Today the only gate is `trim($note) !== ''` (`:139`). This is a real choice: keep prose and lose the confidence gate, or ask for JSON and get a machine readable handle back. **Recommendation: ask for JSON with a `note` string plus `confidence` and `unusable_frames`, and render the `note`.** The gate is worth more than the simplicity, and JSON costs one parse that `json_parser` already does for scoring.
3. The prior plan and DECISIONS.md both assert that the evidence is "shown to the learner", and DECISIONS.md rests the whole no consent decision on that visibility. **It was never built.** The class docblock says so explicitly (`classes/soapbox_gesture_vision.php:47-54`), including the warning not to cite it as evidence that the guarantee exists, and gives the reason: it is raw unreviewed model prose about a named learner's body, and nothing between the prompt and the store enforces the appearance bar the prompt asks for. Either PresenterAI builds the display and handles that risk, or the no consent decision needs re-taking on different grounds. This is section 9.1 and it blocks phase 3.

---

## 6. Gradebook integration

This is the reason Soapbox's data is fragile, and it is the largest functional gain in PresenterAI.

**Features.** `FEATURE_GRADE_HAS_GRADE` and `FEATURE_GRADE_OUTCOMES` true. `FEATURE_ADVANCED_GRADING` deliberately false in v1, for the reason in section 2.2.

**The four functions** in `lib.php`, in the standard shape: `presenterai_grade_item_update()`, `presenterai_update_grades()`, `presenterai_get_user_grades()`, `presenterai_grade_item_delete()`. `presenterai.grade` follows the core convention, so 0 creates no grade item at all.

**The number.** For one attempt, `pct = rawsum / rawmax` over assessed criteria only, then `grade = pct * grademax`. For a scale, `index = clamp(ceil(pct * count), 1, count)`. `rawsum` and `rawmax` are stored so the denominator is auditable, which is the same rule the shipped prompt already enforces: a criterion the model could not judge is stored with `assessed = false` and excluded from both sums, and a visual criterion is omitted from the prompt entirely when there is no evidence (`classes/external/score_speech.php:144`).

**Across attempts.** `gradingmethod` mirrors mod_quiz: `highest` (default), `latest`, `average`, `first, computed over attempts that have a score row**, not over attempts whose media still exists.** This is the fix for the defect in section 3.3. A migrated attempt whose video expired in 2026 still counts, and SOLA's public commitment in `.wiki/Migration-to-PresenterAI.md` to import "the transcripts, scores and feedback for every attempt, including attempts whose video has already been deleted" is honoured rather than quietly undone by the grade query. Test it explicitly.

**Precedence, in order.** A gradebook override (core's `overridden` flag) beats everything and is never clobbered by a rescore. Otherwise a `teacher` score row for an attempt beats the `ai` row for that attempt. Otherwise the AI row. A rescore only rewrites attempts with no teacher row.

**Teacher workflow.** `report.php` lists submissions per learner with status, attempt number, length, AI percentage, teacher percentage and spend. `grade.php` opens one attempt with the slide synced player, the transcript, the visual evidence and editable per criterion fields prefilled from the AI row; saving writes an `origin = 'teacher'` row, updates `presenterai_recording.scoreid`, fires `recording_scored` and re-pushes the grade.

**One correction the prior plan has not absorbed.** DECISIONS.md is unambiguous: at Saylor "there is no teacher, no human evaluation, no moderation and no appeal path. Every word of feedback a learner gets is the entire product." That is not a reason to cut the teacher screens, because PresenterAI is a public plugin and most sites do have teachers. It is a reason to stop treating teacher review as a safeguard anywhere in the design, and specifically to **strike the learner facing sentence in the rubric help text promising that a teacher's override always wins**, because at Saylor that sentence is false. DECISIONS.md asked for this re-read before phase 3 and this is it.

**Outcomes, flagged rather than asserted.** The prior plan says a criterion carrying an `objectiveid` maps to a course outcome and is graded through core `grade_outcomes`, replacing the bespoke `objective_manager::record_attempt()` partial credit path in `score_speech.php`, with the `>= 0.5` mastery threshold preserved. I did not verify that core's outcomes API accepts that normalised partial credit shape, or that the threshold survives the translation. Treat outcomes as a phase 2 spike with a written answer before any code, not as a settled design.

**Completion.** `completionsubmit` (N recordings submitted) and `completionminscore` (overall percentage at or above X), plus `FEATURE_COMPLETION_TRACKS_VIEWS`.

**Events and notification.** `course_module_viewed`, `recording_submitted`, `recording_scored`, `recording_deleted`, plus a `recordingscored` message provider so a learner is told when scoring finishes.

---

## 7. The migration from Soapbox

This is what gates SOLA 8.0. The public commitment is explicit: v8.0.0 will not remove Soapbox until PresenterAI can import existing recordings and scores (`.wiki/Migration-to-PresenterAI.md`). Everything in phases 0 to 3 is optional from SOLA's point of view; this is not.

The migration is non destructive, idempotent, opt in, dry run by default, and it moves no byte of media.

### 7.1 Preconditions, refused if unmet

1. `mod_presenterai` installed, `local_ai_course_assistant` present with the three `sbx_*` tables.
2. Target `storagebackend` is `s3`, and its bucket, region and prefix match the local plugin's `soapbox_storage_*` settings exactly. `--backend=fs` is refused in v1 with a message explaining that it is a byte copy, not a re-point.
3. **SOLA's retention task cannot run on the same prefix.** The prior plan says "disable the scheduled task", and a disabled task can be re-enabled by any admin on the settings page with no warning. Stronger and cheap: SOLA v7.5.2's `soapbox_cleanup::execute()` returns early when a `presenterai` install is detected and site config says migration has begun. A code level interlock cannot be undone by a click. Two retention tasks pruning one prefix is the single way this loses a learner's video.
4. No `score_recording` adhoc task is queued or running for any row being migrated.
5. The migrator runs as a real user with `moodle/course:manageactivities`. `create_module()` requires it via `can_add_moduleinfo()` (`course/modlib.php:514`), so a CLI script must call `\core\session\manager::set_user(get_admin())` before the first course or it throws on the first call.

### 7.2 What migrates, and the three places the prior plan is wrong

Full field mappings live in the migrator's docblock; the four that matter are here.

**`overall_score` is not a percentage.** `practice_scores.overall_score` is the mean of the per criterion scores on the 0 to 5 scale (`classes/rubric_manager.php:369`). The percentage is `pct`, it is not in a column at all, it lives in `session_meta['pct']`, and only on rows written since v7.5.1 (`classes/external/score_speech.php:366`). Mapping `overall_score` onto `overallpct` turns a learner who scored 4 out of 5 into 4 percent. This is a computed migration, not a column copy.

**`rawmax` cannot be reconstructed for every historical row.** `max_score` and `assessed` were added to the stored per criterion JSON in commit `cf7506ef` (2026-09-18, `classes/external/score_speech.php:313,327`). Before that the stored shape was `name`, `score`, `feedback` only. The Soapbox feature shipped 2026-06-15, so three months of rows carry no maximum and no assessed flag. The backfill rule, in order:

1. Use the entry's `max_score` if present. (`scoreprovenance = 'exact'`)
2. Otherwise normalise the criterion name the way `score_speech::normalise_name()` does and look it up in the rubric `practice_scores.rubricid` points at. (`rubric_backfill`)
3. Otherwise, if `rubricid` is 0, look it up in the level preset for the assignment's `speaking_level`. (`preset_backfill`)
4. Otherwise `rawmax = 0`, `overallpct = null`, `scoreprovenance = 'unreconstructed'`.

Rule 4 is not a failure, it is the honest answer for a row scored against a rubric an admin has since edited or deleted, and it is why `legacymeanscore` exists. Such a row still migrates, still shows its per criterion scores and feedback, and simply does not claim a percentage it cannot prove. Since every migrated instance has `grade = 0`, no gradebook depends on it. Note also that there was no criterion allowlist before v7.5.1 (`:306` was added in the same commit), so older rows can contain criterion names in no rubric, including visual criteria awarded from a transcript alone.

`assessed` is absent on every pre v7.5.1 row, and absent must be treated as assessed, matching `classes/rubric_manager.php:320-327`. Treating absent as not assessed would render every historical score as "not assessed".

**`session_type` cannot select Soapbox rows.** `TYPE_SPEECH = 'speech'` is written by `score_speech::execute()` at `:375` for both callers: the standalone practice page `soapbox.php:62` and the assignment scorer `classes/soapbox_scorer.php:180`. A `speech` row is as likely to be someone's freeform practice. The only correct selector is the pointer from the recording, `sbx_rec.scoreid`, which is indexed by the foreign key at `db/install.xml:783`:

```sql
SELECT ps.*, r.id AS recid
  FROM {local_ai_course_assistant_sbx_rec} r
  JOIN {local_ai_course_assistant_practice_scores} ps ON ps.id = r.scoreid
  JOIN {local_ai_course_assistant_sbx_assign} a ON a.id = r.assignid
 WHERE a.courseid = :courseid AND r.scoreid IS NOT NULL
```

The migration performs no `UPDATE` and no `DELETE` on `practice_scores`, ever, including on rollback. That is a constraint enforced in code and in the test suite, because that table is also read by `outcomes_report.php`, `classes/analytics.php`, the privacy provider (`classes/privacy/provider.php:409,478,716`) and the course backup (`backup/moodle2/backup_local_ai_course_assistant_plugin.class.php:344-346`).

Two edge cases the join produces. *Orphans*: `score_speech` inserts the score at `:371` and `soapbox_scorer` writes `scoreid` back at `:209`, so a crash between those two points leaves an orphan and the adhoc task, guarded only on `status !== 'uploaded'` (`classes/soapbox_scorer.php:104`), writes a second row. Migrate the row the recording points at, leave the orphan, and count orphans in the report. A count in the hundreds means something worse is happening and should stop the rollout. *Duplicate recordings sharing a `scoreid`*: nothing forbids it and no constraint exists. Detect it, refuse the course, report it. Creating two score rows from one source row would double a learner's history.

**`visual_observation` is not imported.** Investigations A and D disagreed here: D said migrate it where still present and expect almost none (the v7.5.2 scrub removes it when the video goes, and the retention default is 7 days), A said do not import it at all. **Resolved in A's favour**, because D's own finding makes the cost of not importing close to zero while the risk of importing is real: importing that prose into a site that has turned retention off would undo the fix that exists to stop it outliving the video. `visual_assessed` is imported; the prose is not.

### 7.3 The structural problem: an assignment is not a course module

`create_module()` (`course/lib.php:2958`) requires `modulename`, `course`, `section`, `visible` and `introeditor`, checks the capability, **creates the section if it does not exist** (`course/modlib.php:519-523`), refuses if the module is not permitted in the course, and then opens a delegated transaction (`course/modlib.php:127`) that inserts `course_modules`, calls `presenterai_add_instance()`, saves the intro file area, adds the module to the section, triggers `course_module_created` and runs `edit_module_post_actions()`.

Three consequences:

- **Do not wrap the run in an outer transaction.** Moodle's delegated transactions cannot be partially rolled back, so one outer transaction means course 400 failing rolls back courses 1 to 399. The granularity is one transaction per source assignment, inside which `create_module()` nests its own.
- A miscomputed section number does not error, it silently adds sections to a live course.
- A dry run and a real run are meaningfully different operations, because the real run fires events into the logstore and touches gradelib. The dry run cannot prove the real run will succeed, and the runbook should say so.

**Which section.** Find the `mod_url` stand in by re-implementing the regex at `classes/soapbox_course_link.php:76-82`, with two corrections: `placed_in_course()` returns an array keyed by assign id (`:106-111`), which silently collapses two url activities pointing at the same assignment, so the migrator must keep the full list and either refuse or pick deterministically; and the regex also matches absolute URLs carrying the originating site's wwwroot (`:70-72`), so do not assume the stored form. The fallback when no stand in exists should be the **last section, not section 0**: a migrated activity appearing above the course introduction in a course nobody asked to be changed is a support ticket. See section 9.9.

**The stand in rewrite, which as written breaks the thing it protects.** The prior plan says rewrite `externalurl` to the new activity and set `course_modules.visible = 0`. That makes the module unavailable to students entirely, so every bookmark and announcement the step exists to preserve starts returning "not available", which is the opposite of the goal. The correct field is `visibleoncoursepage` (`lib/db/install.xml:334`), but it only works when stealth availability is on: `lib/modinfolib.php:1009-1012` forces it back to 1 unless `$CFG->allowstealth` is set and the course format allows it (`course/format/classes/base.php:1948`). So the migrator checks both before writing anything, and if stealth is unavailable it **leaves the stand in fully visible and says so in the report**, accepting one term of duplication rather than choosing silent breakage.

One more thing the prior plan misses: once `externalurl` is rewritten, `assign_id_from_url()` no longer matches it, so rollback cannot find what it changed. The original `externalurl` and visibility go into the map row's `payload` **before** the rewrite.

### 7.4 Attempts whose video is already gone

This is the case that matters most and the prior plan barely mentions it. `drop_object()` deletes the object, deck and frames and nulls all three keys, keeping the row, the transcript, the `scoreid`, the score, the feedback and the tips (`classes/task/soapbox_cleanup.php:114-140`). With a 7 day default since 2026-06-15, these are probably the majority.

They migrate normally. Every field maps except the three keys. `status` becomes `scored` when a score row exists and `abandoned` when it does not, with `storagekey` null and `mediadeletedat` set from `expires_at`. `expiresat` copies verbatim, because recomputing it shifts every learner's deletion date. There is no special case and no second code path.

Migrating them is an improvement, not just preservation. Today the learner's page filters them out: `soapbox_present.php:235` selects `assignid = :a AND userid = :u AND status <> :d` with `d = 'deleted'`, so an attempt whose video expired disappears from the learner's list entirely, taking its score and its written feedback with it. That feedback was generated, paid for and stored. PresenterAI renders such an attempt as a scored attempt with the player replaced by "this recording was deleted on <date>", which costs one template branch.

A consequence for verification: the per course count that must match is the count of `sbx_rec` rows **including** the deleted ones, not the count the learner page shows. Anyone writing a verification query from that page's SQL validates against a number that is already wrong.

### 7.5 Re-runnability and interruption

With one transaction per assignment plus the map table, an interruption leaves some assignments `verified`, at most one at `created` or `attached`, and the rest untouched. Nothing in the source tables has changed. The worst visible artefact is a course page with a real activity next to a stand in that has not been rewritten yet.

Re-run rules, in order:

1. A `verified` map entry for this site is skipped, no queries run.
2. A `created` or `attached` entry is **resumed**, not recreated: look up `targetcmid`, confirm the module and instance still exist and still carry the right `legacyassignid`, then continue with topics, recordings and scores.
3. If `targetcmid` no longer resolves, the entry moves to `failed` with a reason and is reported, not recreated. Recreating it would resurrect an activity a teacher deliberately deleted.
4. Child rows are keyed independently, which is what makes a half finished assignment safe to continue.
5. `--rollback` deletes only modules whose `legacyassignid` matches a `created` entry from this site, restores each stand in's recorded `externalurl` and visibility from the map payload, deletes the `presenterai_*` rows, and touches no `local_ai_course_assistant` row. It **refuses to roll back an instance whose `grade != 0`**, because that means a teacher has since turned grading on and real gradebook entries exist.
6. A site wide lock via `\core\lock\lock_config::get_lock_factory('presenterai_migration')`, named per course, stops two operators both seeing `planned` and both creating modules. About ten lines.
7. If the source `sbx_assign` row's `timemodified` is later than the map entry's `timecreated`, the assignment is **refused** with a clear message rather than resumed, because the already created instance is stale and nothing downstream would detect the mismatch.

### 7.6 Verification, which is the actual gate

Per course, recorded as a `presenterai_migreport` row, not observed once and remembered.

**Tier 1, counts. Every one exact.**

| Check | Source | Destination |
|---|---|---|
| Assignments | `COUNT(sbx_assign WHERE courseid = c)` | `COUNT(presenterai WHERE course = c AND legacyassignid > 0)` |
| Topics | `COUNT(sbx_topic JOIN sbx_assign)` | same, via `legacytopicid` |
| Recordings, all statuses | `COUNT(sbx_rec JOIN sbx_assign)` | `COUNT(presenterai_recording WHERE legacyrecid > 0)` |
| Recordings with surviving media | `... WHERE storage_key IS NOT NULL` | same on `storagekey` |
| Scores | `COUNT(sbx_rec WHERE scoreid IS NOT NULL)` | `COUNT(presenterai_score)` |
| Distinct learners | `COUNT(DISTINCT userid)` on `sbx_rec` | same |

**Tier 2, content, per migrated score row.** Transcript equality by hash, not by length. Same criterion count, same names in the same order, same integer scores, same feedback strings. `legacymeanscore` equals the source `overall_score` exactly. Where `rawmax > 0`, the recomputed mean of the assessed criteria equals the source `overall_score` to within one, allowing for the rounding at `classes/rubric_manager.php:369`; a larger gap means the reconstruction picked the wrong maxima and the row goes to `unreconstructed` rather than carrying a false percentage. `expiresat` equals `expires_at` exactly, because any drift means retention windows moved.

**Tier 3, storage.** For a sample of recordings with a non null `storagekey`, and for all of them when the count is under a few thousand, issue an S3 HEAD against the key read from the **destination** row and confirm the object exists and its size matches `sizebytes`. This is the only check that proves the re-pointing works.

**Tier 4, the human check.** Three learners per course, opened as that learner with the log entry recorded: one with a surviving video and slides, one whose video was deleted but whose score survived, one whose newest attempt is `failed`. The second is the one that matters, because it is the case the old page never rendered at all.

**The gate.** SOLA 8.0's upgrade step that drops `sbx_*` refuses to run for any course without a passing `presenterai_migreport` row. That is a real gate rather than a note in a runbook, and it is not optional: there is no gradebook entry anywhere in Soapbox, so `sbx_rec` plus `practice_scores` is the only copy of a learner's work. A global drop after a global run is a single point of total loss for anyone the run skipped.

### 7.7 Rollout, and what cannot be done safely

Rollout order `dev.sylr.org`, then `degrees.saylor.org`, then `learn.saylor.org`. Degrees first despite being the accredited site, because it is the smaller corpus and the records risk is what we most need to observe under low volume; `grade = 0` everywhere is what makes that safe. Stand ins stay in place for at least one full term before `--remove-url-stubs`.

Four things stated plainly:

1. **The table drop cannot be gated on a date.** Per course, on a passing report, or not at all. If that pushes the drop past 8.0 into 8.1 or later, that is the correct outcome and the timeline should say so.
2. **`overallpct` cannot be computed for every historical row and must not be faked.** The only way to get a percentage on every row is to assume `max_score = 5` for every criterion, which is true for every shipped rubric and not for a hand edited one. If a stakeholder needs that, it has to be recorded on the row rather than applied silently. See section 9.8.
3. **"Deletion is optional" cannot be honoured for migrated objects while a bucket lifecycle rule stands on the `soapbox/` prefix.** That is infrastructure, not code. Turning retention off in PresenterAI while the rule remains produces rows that claim the video is kept and a bucket that has already thrown it away. See section 9.3.
4. **Everything else here is low risk**, and it is low risk precisely because of the two choices the prior plan already made correctly: `grade = 0` on every migrated instance, and byte identical storage keys. Keep both.

---

## 8. Phased build order

Each phase ends somewhere installable, tested and useful on its own. Phase 0's rule is the single best risk control in the prior plan and it carries unchanged: **port the existing test files first and make them the acceptance gate, before any new code.**

**Phase 0. Scaffold and pure functions.** Repo, CI (`moodle-plugin-ci` across 4.5 and main, PHP 8.1 to 8.3, phpunit, behat, phpcs, phpdoc, grunt), `version.php`, `lang/en/presenterai.php`, `db/install.xml` with all eight tables, `db/access.php`, `lib.php` skeleton, `mod_form.php`, `view.php` showing intro and topics only, `index.php`, backup and restore of the instance and topics. Ported tests: `config_test` (9 cases), `storage_test` (6, including the pinned SigV4 `presign_url` output), `scorer_test` (3), `transient_retry_test` (2), `speech_level_resolution_test` (4), `score_test` (7), `rubric_test` (11), `slide_vision_test` (4), `instance_manager_test` (6), plus new `timeline_test` and Jest tests for `slideForTime`, `makeTimeline` and `sampleTimes`.
*Ships:* an activity that appears in the chooser, holds its settings, backs up and restores.

**Phase 1. Recording, storage, slide synced playback. No AI.** `store_interface`, `media_ref`, **both** `fs_store` and `s3_store`, `store_factory`, `probe` with both arms, `upload.php` with negotiated resumable chunking, `begin_attempt`, `finalize_recording`, `get_playback`, `render_deck`, `delete_recording`, the deck renderer (Ghostscript via `$CFG->pathtogs`, 110 DPI, 60 page cap, `-dSAFER`), `recorder.js`, `uploader.js`, `slides.js`, `view.js`, `player.js`, the retention task with optional retention, the learner download, the deletion date callout, topics with their file area.

Building both stores here is a change from the prior plan, which had S3 in phase 5 as a Saylor migration concern. Investigations A and C differed on emphasis, A wanting both stores first class from the start and C wanting `fs` first because it is the default. **Resolved by doing both in phase 1:** the interface has to be shaped by both backends or it gets shaped around the File API and then bent for S3, and `s3_store` is a port of an existing 341 line class whose signing is already pinned by a test, so it is cheaper than it sounds. `fs` remains the shipped default.
*Ships:* the whole learner experience including slides beside video on playback, on a stock Moodle with no AI and no S3 configured.

**Phase 2. Gradebook, teacher screens, privacy.** Scores written by hand, `grader`, the four grade functions, `gradingmethod`, `report.php`, `grade.php` plus `grading.js`, completion, the four events, the message provider, the privacy provider (deletion purges storage objects before rows), course reset, and the outcomes spike with a written answer.
*Ships:* a fully gradeable manual video presentation activity. This is already a plugin worth publishing.

**Phase 3. AI: transcription, scoring and body language.** `client_interface`, `core_ai_client` (with the 4.5 and 5.x branch), `claude_client`, `openai_client`, `gemini_client`, `stt_client`, `json_parser`, `usage`, the `auto` route resolver, `rate_limiter`, `security` (SSRF plus TLS pinning on admin entered endpoints), `scorer`, the `score_recording` adhoc task with the `get_fail_delay() < 960` transient rule, stored attempt pruning, `rubrics` with the speech criteria and level presets, `rubric.php`, `warm_stt`, and the body language port: `amd/src/frames.js` and `video_vision`, the assessed-criteria-only denominator, the criterion allowlist, the `visual_not_assessed` message, and the `visualevidence` decision from section 9.1.

The prior plan had body language as its own phase because it was new work. It is a port with named source files and shipped tests, so it folds in here, which also means both features in the original brief are delivered before migration work starts, a better place to be if migration slips.
*Ships:* everything today's Soapbox does, as an activity, with grades.

**Phase 4. Migration. This phase is on SOLA 8.0's critical path.** `migration/from_local_aica`, `presenterai_migration_map`, `presenterai_migreport`, `cli/migrate_from_local.php`, `migrate.php`, the verification tiers, `--rollback`, the SOLA side interlock in `soapbox_cleanup::execute()`, `migration_test`, and the written runbook. Rollout dev, degrees, learn.
*Ships:* Saylor can cut over, and SOLA 8.0 is unblocked.

**Phase 5. Polish and publish.** Deck page caching into a `deckpage` file area returning pluginfile URLs, replacing the base64 data URI response that today re-renders a multi megabyte JSON payload through Ghostscript on every playback. Behat features, `monologo.svg` and icon, accessibility pass on the player and recorder, string review, plugin directory submission.

---

## 9. Open questions for Tom

Each of these needs a decision from you. The facts I could not establish are listed separately at 9.11 to 9.16; those need a query or a console, not a judgement.

**9.1 Is the body language observation shown to the learner?** This blocks phase 3.
The prior DECISIONS.md rests the entire no consent decision on learner visibility ("that visibility is the only thing standing between the feature and a learner's surprise"). That visibility was never built, and the SOLA docblock at `classes/soapbox_gesture_vision.php:47-54` says so and gives the reason: it is raw unreviewed model prose about a named learner's body, and nothing enforces the appearance bar the prompt asks for.
*Options:* (a) show it, and add a second model pass or a rule based filter that enforces the appearance bar before storage; (b) do not show it, and re-take the no consent decision on different grounds, most likely a disclosure sentence plus a per instance opt in; (c) show a rewritten learner facing summary rather than the raw note.
*Recommendation:* (c). The learner gets the transparency the decision assumed, the raw note stays available to a teacher and to a privacy export, and the rewrite is the same call that already produces the feedback.

**9.2 Default retention for a fresh install: keep forever, or 7 days?**
The prior plan recommended 28 days for graded instances and 7 for ungraded. Investigation C recommended keep forever by default with Saylor setting 7. These conflict.
*Options:* (a) 0, keep forever, with a loud disk note on the settings page; (b) 7 days, matching Saylor today; (c) the prior plan's grading dependent rule.
*Recommendation:* (a), and reject (c) outright. A retention window that changes when a teacher turns grading on is invisible coupling, and deleting a graded learner's evidence by default is the more surprising behaviour for an activity whose whole point is assessment. Saylor sets 7 explicitly on both sites, which matches the recorded decision. The 28 day hard cap goes regardless.

**9.3 What happens to the S3 lifecycle rule on the `soapbox/` prefix?**
PresenterAI cannot see it and cannot override it.
*Options:* (a) remove the rule on that prefix as part of cutover, and let PresenterAI be the only thing that deletes; (b) keep it, and have PresenterAI clamp its retention setting to at most the rule's age on an S3 backend, saying so on the settings page; (c) keep it and say nothing, which produces playback pages for objects that are gone.
*Recommendation:* (a), with (b) as the code level safety net regardless, because a future admin can add a rule back. (c) is not acceptable.

**9.4 Minimum Moodle version, and how much core AI support 4.5 gets.**
*Options:* (a) `requires = 2024100700` (4.5) with one adapter carrying a version branch for the 4.5 static API and the 5.x instance API; (b) `requires = 2024100700` but core AI mode available only on 5.0 and later, hidden with a message on 4.5; (c) `requires = 2025041400` (5.0) and one code path.
*Recommendation:* (a). The branch is around thirty lines, and both production sites are 4.5, so (b) would mean the core AI requirement does not exist on the only sites we run. Revisit if Catalyst's upgrade timeline says 5.x is close.

**9.5 Do we ship a companion `aiplacement_presenterai` plugin?**
Without one, PresenterAI does not appear in the admin AI placements table and, on 5.1 and later, not in the per activity "AI tools" section of the module form.
*Options:* (a) no placement; honour `is_action_enabled_in_context()` voluntarily; (b) ship a second plugin that installs into core's `ai/placement/` directory.
*Recommendation:* (a) for v1. (b) means a second plugin in a core owned directory plus the 4.5 and 5.1 API split handled twice, for admin UI polish rather than function.

**9.6 Which S3 compatible targets must actually work?**
Today `host()` hard codes `{bucket}.s3.{region}.amazonaws.com` (`classes/soapbox_storage.php:96-98`), so "S3 compatible" is not met by a straight port. SigV4 already parameterises host, region and service (`:256-262`), so adding `s3endpoint` and `s3pathstyle` is small, but path style plus region `auto` for Cloudflare R2 is real work and real testing.
*Options:* (a) AWS only in v1, with the settings present but undocumented; (b) AWS plus MinIO, which is testable in CI; (c) AWS, MinIO, R2 and Wasabi.
*Recommendation:* (b). MinIO in CI is what proves the abstraction is real rather than aspirational, and R2 support then costs one setting and a manual test rather than a redesign.

**9.7 Does the standalone practice page `soapbox.php` survive SOLA 8.0?**
The prior plan recommends leaving it in SOLA indefinitely. `.wiki/Migration-to-PresenterAI.md` says v8.0.0 removes "the Soapbox pages, settings and tasks", and `soapbox.php` is a Soapbox page. Either the wiki means the assignment pages only, or learners lose their cross course practice history.
*Options:* (a) the wiki means assignment pages; `soapbox.php` stays, and the wiki is corrected to say so; (b) it goes in 8.0 and the practice history goes with it; (c) it goes in 8.0 and PresenterAI grows a site level practice mode.
*Recommendation:* (a). It costs nothing to keep and a learner losing their practice history is worse than a little duplication. This needs an explicit answer before the wiki is read as a commitment.

**9.8 Historical rows with no recoverable denominator: null percentage, or assume 5?**
*Options:* (a) `overallpct = null`, `scoreprovenance = 'unreconstructed'`, the original mean preserved in `legacymeanscore`; (b) assume `max_score = 5` for every criterion, which is true for every shipped rubric and not for a hand edited one, recorded on the row as `assumed_max`.
*Recommendation:* (a). With `grade = 0` on every migrated instance, nothing depends on the percentage, and a number nobody can reproduce is worse than an honest gap.

**9.9 Where does a migrated activity land when the course has no `mod_url` stand in?**
*Options:* (a) last section; (b) section 0, as the prior plan says; (c) refuse and make the dry run put the list in front of the course owner.
*Recommendation:* (a) as the default, with the dry run listing every assignment taking the fallback so a course owner can move them. Section 0 puts a new activity above the course introduction in a course nobody asked to change.

**9.10 Must recordings play in the Moodle mobile app in v1?**
If yes, the filesystem backend needs `tokenpluginfile.php` URLs, which are bearer URLs and partly undo the revocation advantage in section 4.6.
*Options:* (a) no app support in v1; (b) app support with token URLs and a short expiry.
*Recommendation:* (a). Saylor is on S3, where the URL is already a bearer token, so this costs Saylor nothing and keeps the filesystem story clean for everyone else.

### Facts I could not establish. These need a query or a console, not a decision.

**9.11** Which Moodle version learn.saylor.org and degrees.saylor.org run today, and Catalyst's upgrade timeline. This decides whether the 5.x core AI path is reachable at all (9.4).
**9.12** Whether `presenterai` is free in the Moodle plugin directory. A clash means a rename touching every file, table and string, so check before phase 0 ends.
**9.13** `SELECT COUNT(*) FROM mdl_local_ai_course_assistant_sbx_topic WHERE pdf_itemid <> 0` on both sites. If it is not zero, something wrote those values that I did not find and the topic file migration comes back.
**9.14** Whether any `sbx_rec` rows share a `scoreid`, and how many score rows are orphaned. Duplicates must stop the migration (section 7.2) and I do not know whether they exist.
**9.15** Per site volumes: assignments, topics, recordings by status, scores, distinct learners, and objects still in the bucket. This decides whether tier 3 verification is exhaustive or sampled, and it is the number the retention question has been waiting on since the prior plan.
**9.16** Whether `$CFG->allowstealth` is on, whether the bucket is versioned, what the lifecycle rule's age is, and whether the two sites use the default bucket `archive-course` and prefix `soapbox/` or overrides. The first decides whether stand ins can keep working without duplication; the rest gate section 9.3.

---

## 10. Index of disagreements between the investigations, and how each was resolved

| Topic | Positions | Resolution | Where |
|---|---|---|---|
| `storagekey` format on the filesystem backend | A: filename only. C: `fs:{contextid}/{filearea}/{itemid}/{filename}` | Filename only. A restore changes the context id, so an embedded one goes stale and becomes a second source of truth. | 3.3 |
| Retention default for a fresh install | Prior plan: 28 graded, 7 ungraded. C: keep forever. A: 7 is fine, an off switch is what matters | Open for Tom, recommending keep forever, and rejecting the grading dependent rule outright as invisible coupling. | 9.2 |
| When the S3 store gets built | Prior plan and C: phase 5, after the filesystem default. A: both in phase 1 | Both in phase 1. The interface must be shaped by both or it will be bent later; `fs` remains the default. | 8 |
| Importing `session_meta['visual_observation']` | D: import where present, expect almost none. A: do not import | Do not import. D's own finding makes the cost near zero; the risk of undoing the v7.5.2 scrub is real. | 7.2 |
| `scoreprovenance` placement | D: on the recording | On the score. It describes how that score's denominator was derived, and a recording can carry more than one score row. | 3.5 |
| Migration into the filesystem backend | A and D both: refuse in v1 | Agreed, stated in scope. | 2.2, 7.1 |
| Vision output format | Prior plan: JSON with `confidence`. Shipped: prose | JSON with a `note` plus `confidence` and `unusable_frames`. The confidence gate is worth one extra parse. | 5.8 |
