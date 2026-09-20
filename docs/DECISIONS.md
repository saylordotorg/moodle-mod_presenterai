# Decisions

Decisions already taken for PresenterAI, with their reasons. Decisions carried forward from `moodle-mod_soapbox/docs/DECISIONS.md` keep their original date and decider; new ones are dated here. Questions still open are in [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md) section 9 and are not repeated as decisions.

---

## Part 1: carried forward from the Soapbox design

Decided by Tom Caswell, 17 September 2026, unless noted.

| # | Question | Decision | Status now |
|---|---|---|---|
| 3 | S3 or the Moodle File API for Saylor | **Leave storage as is.** Keep S3 with the existing bucket and prefix on both production sites, which is what keeps migration metadata only. | **Stands, half superseded.** The second half of the original wording, "the File API stays the shipped default for everyone else", is now not a default that others live with but a first class per site choice. See D4. |
| 7 | Retention default once grading is real | **Leave the 7 day default.** No bump to 28 days for graded instances. | **Stands for Saylor.** It never contemplated a third option, "no automatic deletion at all", which the product requirement adds. The plugin's own default for a fresh install is a separate question and is open (plan section 9.2). |
| 8 | Consent for visual analysis of learner frames | **No consent gate.** Frame analysis runs without a per learner opt in. | **Still the decision. Its stated justification is factually wrong**, see C1 below, and it is only achievable on the direct AI route, see D6. |
| 10 | Is ffmpeg available | Unknown and doubtful; not on the Saylor dev fleet. | **Answered and built around.** The browser side sampler shipped in SOLA v7.5.1 as `amd/src/soapbox_frames.js`. Catalyst's production containers remain unchecked and it no longer matters for v1. |

### Carried forward in full: there is no teacher in this flow

Recorded 18 September 2026. Saylor's learners are fully online and self paced. There is no teacher, no human evaluation, no moderation and no appeal path. Every word of feedback a learner gets is the entire product.

This invalidates any design that treats human review as a safeguard, and it raises the bar on the feedback itself: it has to be specific and actionable for someone with nobody to ask. It has now been applied to the gradebook design, which is what the original note asked for: the teacher screens stay, because PresenterAI is a public plugin and most sites do have teachers, but teacher review is no longer cited as a safeguard anywhere, and the learner facing sentence promising that a teacher's override always wins is struck, because at Saylor it is false.

### Carried forward: disclosure is not consent, and it is cheap

The activity says, on the recording screen of an instance with visual analysis enabled, that still frames from the recording are sent to an AI model to assess body language. A sentence of static text, not a blocking modal. This keeps the privacy notice accurate without a gate.

---

## Part 2: corrections to the prior decisions

**C1. The transparency guarantee that question 8 rests on was never built.**
The prior DECISIONS.md says the vision output is "stored verbatim in `soapbox_recording.visualevidence` and shown to the learner", and concludes that "with no consent step in front, that visibility is the only thing standing between the feature and a learner's surprise, so it should not be quietly dropped later". It was never there to drop. The class docblock at `classes/soapbox_gesture_vision.php:47-54` states it plainly, including a warning not to cite that comment as evidence the guarantee exists, and gives the reason: it is raw unreviewed model prose about a named learner's body and nothing enforces the appearance bar the prompt asks for. Whether PresenterAI builds the display, and in what form, is open (plan section 9.1) and blocks phase 3.

**C2. The prior plan's gradebook rule would silently drop grades.**
It says grades are computed over `status = 'scored'` rows so "a retention-deleted-but-scored attempt still counts". Against the status model it ports, retention sets `status = 'deleted'` (`classes/task/soapbox_cleanup.php:131-138`), so such an attempt leaves the gradebook. Fixed by D8.

**C3. The case study PDF migration migrates nothing.** `sbx_topic.pdf_itemid` is written by `classes/soapbox_assignment_manager.php:304,316` and read by nothing: no file manager element, no `get_file_storage()` call anywhere in the Soapbox code, and `lib.php:46-49` serves only the `customavatars` area. The file area is a good new feature. The migration step is deleted, subject to one production query.

**C4. Hiding the `mod_url` stand in breaks what it protects.** The prior plan sets `course_modules.visible = 0`, which makes the module unavailable to students entirely, so every preserved bookmark returns "not available". The correct field is `visibleoncoursepage`, which needs `$CFG->allowstealth` (`lib/modinfolib.php:1009-1012`). See D15.

**C5. The prior plan's frame numbers are wrong.** It specifies 9 frames at 512 px extracted by ffmpeg. The shipped implementation is 6 frames in 426 by 240 cells composited into one contact sheet at JPEG quality 0.72, sampled in the browser from the middle 80 percent of the recording (`amd/src/soapbox_frames.js:48-56,73`). Use the shipped numbers, and treat the cost estimate built on the old ones as void.

---

## Part 3: new decisions

**D1. The plugin is named PresenterAI, component `mod_presenterai`, repository `saylordotorg/moodle-mod_presenterai`.** Public from the first commit, GPL v3. Recorded 20 September 2026. Whether `presenterai` is free in the Moodle plugin directory has not been checked and must be before phase 0 ends, because a clash means a rename touching every file, table and string.

**D2. It is an activity module, not a feature inside a local plugin.** Soapbox has no course module, no module context, no gradebook entry and no backup of learner work, reachable only through a `mod_url` stand in whose URL is pattern matched back to an assignment id (`classes/soapbox_course_link.php:76-82`). An activity gets a context, capabilities, backup and restore, privacy, completion, events and a real grade. That last one is the point: today a learner's transcript, score and feedback exist in two plugin tables and nowhere else in Moodle.

**D3. Clean room. No runtime dependency on `local_ai_course_assistant`.** PresenterAI does not call SOLA's code, does not require it installed, and declares no dependency. It reads SOLA's source freely, because the shapes there are known good and tested, but it carries its own copies. What this costs is the unified spend view in SOLA's `token_analytics.php`, and that cost is now unavoidable anyway: SOLA v8.0.0 removes the Soapbox code, so the bucket disappears with or without PresenterAI. PresenterAI ships its own spend table and CSV export.

**D4. Storage is a per site choice between Moodle file storage and an S3 compatible bucket, and both are first class.** The filesystem backend is the default for a fresh install: it works with no configuration, keeps no secret at rest in `mdl_config_plugins`, needs no bucket CORS rule, and lets core do backup, restore, context deletion and privacy with no plugin code. Saylor stays on S3, because an identical bucket, region and prefix is what keeps the migration metadata only. Both concrete stores are built in phase 1, not just the default, because an interface shaped by one backend and bent later for the other is how this goes wrong.

**D5. Automatic deletion is optional, the 28 day cap is removed, and model prose about a learner's body gets its own clock.**
`expiresat = 0` already means "never" in the existing cleanup query (`classes/task/soapbox_cleanup.php:56-60`), so this is a clamp change and a settings change, not a schema change. `RETENTION_MAX_DAYS = 28` (`classes/soapbox_config.php:41`) goes, because it made sense for a practice artefact and does not for assessment evidence. Changing the setting never rewrites existing rows implicitly; a CLI tool makes the admin choose a basis. And because SOLA's v7.5.2 fix scrubs the body language observation only when the video is deleted (`:169-199`), a site with retention off would keep that prose forever, which is the exact state the fix abolished, so `visualevidence` expires on its own setting, default 30 days, regardless of media retention. The plugin's own default retention value for a fresh install is still open (plan section 9.2).

**D6. The AI back end is a per site choice of Moodle core AI, Claude, OpenAI or Gemini, resolved `auto` by default, and core AI covers the scoring step only.**
Core's AI subsystem has no action that accepts audio or an image, on 4.5 or on any later branch including the current development branch, and a plugin cannot add one without patching core (`ai/classes/manager.php:117-121` builds the response class by string concatenation from a hardcoded prefix). So transcription and body language always run through the plugin's own client, whatever the site chose for scoring, and a site that configures core AI and nothing else cannot produce a transcript and therefore cannot produce a score. The settings page says this at the point of choosing.
Two consequences follow. Core's per user policy acceptance exists but is not enforced by `process_action()` on the plugin's behalf, so "no consent gate" (question 8) is a property of the direct route, not of the plugin. And core sets no timeout on the request path, which settles that scoring runs in an adhoc task and never in a web request.

**D7. Deprecation timeline: SOLA v7.5.2 deprecates Soapbox, v8.0 removes the code, the tables are dropped later and per course.**
v8.0 will not remove Soapbox until PresenterAI can import existing recordings and scores, which puts the migration phase on SOLA's release critical path. The table drop is gated on a passing per course verification report, not on a date, because `sbx_rec` plus `practice_scores` is the only copy of a learner's work and a global drop after a global run is a single point of total loss for anyone the run skipped. If that pushes the drop past 8.0, that is the correct outcome.

**D8. `status` records the scoring lifecycle only; media existence is `storagekey IS NULL`.**
Values are `uploading`, `uploaded`, `scoring`, `scored`, `failed`, `abandoned`. Grades are computed over attempts that have a score row, never over attempts whose media still exists. This fixes C2 and it is what makes SOLA's public promise to import attempts whose video is already gone actually hold once those attempts reach a gradebook.

**D9. On the filesystem backend, `storagekey` holds the filename alone.**
Not a pathname hash, which the prior plan specified and which is a derived SHA1 that changes with the context and which no core activity stores. Not a composite key embedding the context id either, which was the other proposal: a course restore gives the module a new context, so an embedded context id goes stale and becomes a second source of truth that can disagree with the row it sits on. Everything except the filename is derivable from the row, so only the filename is stored. A null key still means the media is gone.

**D10. Migration in v1 supports the S3 backend only.**
Re-pointing keys is metadata only and moves no bytes. Migrating into Moodle file storage is a download and re-upload of every surviving object into moodledata, a different operation with different failure modes and a real storage cost. `--backend=fs` is refused with a message that says why. That is exactly Saylor's case, and pretending one migrator does both is how the S3 path acquires bugs it does not need.

**D11. `session_meta['visual_observation']` is not imported. `visual_assessed` is.**
The v7.5.2 scrub exists to stop model prose about a learner's body outliving the video it describes. Importing it into a site with retention off would undo that. Almost none survive anyway, because the scrub has been running against a 7 day clock since the feature shipped, so the cost of dropping it is close to zero and the risk of keeping it is not.

**D12. Every migrated instance is created with `grade = 0`.**
Carried forward from the prior plan and still the single most important safety choice in it. No grade item is created, so no course total moves for any learner, including ones who finished a course last term. A teacher turns grading on per activity afterwards, knowingly. `--pushgrades` exists for a fresh site and is refused when the target course has any released final grades.

**D13. The migration writes nothing to any `local_ai_course_assistant` table, ever, including on rollback.**
Not even a "migrated" flag. `practice_scores` is also read by the outcomes report, analytics, the privacy provider and the course backup, so touching a row to mark it would change behaviour in four unrelated features.

**D14. The migrator is keyed on a dedicated map table, not on `legacyassignid` alone.**
`sbx_assign` and `sbx_topic` are carried in course backups while `sbx_rec` deliberately is not, so restoring or duplicating a migrated course produces fresh source rows with new ids and no recordings, which a `legacyassignid` only check would treat as unmigrated and duplicate. The map row carries the source site identity, which is what makes a course restored from one site onto another recognisable as foreign data rather than as a completed migration.

**D15. The `mod_url` stand in is kept reachable by `visibleoncoursepage`, not hidden by `visible = 0`.**
And when `$CFG->allowstealth` is off, the stand in is left fully visible and the report says so, accepting one term of a duplicate entry on the course page rather than choosing silent breakage of every existing bookmark. The original `externalurl` and visibility are recorded in the map before the rewrite, because after the rewrite the URL no longer matches the pattern rollback would search for.

**D16. The attempt row is created before the bytes arrive.**
`get_upload_url` becomes `begin_attempt`, inserting the row with `status = 'uploading'` and returning the recording id with the upload target, because the File API needs an itemid before the first byte and an itemid that is not the attempt id would mean a second mapping table. Finalize updates rather than inserts. Abandoned uploads are swept at 24 hours and excluded from the attempt cap.

**D17. Body language feedback is a per instance opt in.**
This reverses SOLA's deliberate choice to have only a site toggle, taken on the reasoning that per assignment opt in would mean most learners never receive the feedback (`classes/soapbox_gesture_vision.php:91-107`). In an activity a teacher configures the instance anyway, so the objection does not transfer, but the reversal is recorded rather than slipped in. Forced off on audio attempts.

**D18. The vision pass returns JSON, not prose.**
The shipped implementation returns four to six sentences of prose. The prior design specified JSON carrying `confidence` and `unusable_frames`, and those two fields were what drove the rule "drop the visual criteria when the evidence is weak"; with prose the only gate is that the note is non empty. PresenterAI asks for a JSON object with a `note` plus `confidence` and `unusable_frames`, and keeps the gate. It costs one parse that the scorer already performs.

**D19. Upload chunk size is negotiated by probe, not fixed.**
The prior plan's fixed 5 MB chunks fail on stock PHP (`post_max_size` 8M, `upload_max_filesize` 2M) and on stock nginx (`client_max_body_size` 1m). The default is 512 KiB and the probe walks a ladder with a real POST at each rung, caching the largest that succeeded, because no `ini_get()` can see the reverse proxy's limit.

**D20. A rate limiter and SSRF validation on admin entered endpoints are in scope for v1.**
SOLA has both (`classes/soapbox_gesture_vision.php:85,88,161`, `soapbox_transcribe.php:52,133,150`) and the prior plan had neither. A public plugin that presigns uploads, calls paid models, and will POST a learner's audio to whatever host an admin pastes needs both before it goes near the plugin directory.
