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
**C6. A learner already reads unreviewed model prose derived from the raw note. The channel exists, it is just unnamed.**
C1 is correct that nothing renders `session_meta['visual_observation']` itself. It is not correct that nothing derived from it reaches the learner. The raw note is written into the scoring prompt at `classes/external/score_speech.php:209-215`, the model produces per criterion `feedback` for the two visual criteria out of it, that feedback is read back at `soapbox_present.php:289` and rendered at `templates/soapbox_present.mustache:183`. So a learner already reads model prose about their body today. It is shorter than the note and it is attached to a criterion name, and nothing checks it. This changes what D21 has to cover: a gate across a new summary alone would leave the older channel open.

**C7. There is no `json_parser` class in SOLA.** `IMPLEMENTATION-PLAN.md:760` lists `json_parser` in the phase 3 build and section 5.8 says asking the vision pass for JSON "costs one parse that `json_parser` already does for scoring". A repo wide grep for `json_parser` across `/Users/tom.caswell/ai-projects/ai_course_assistant` returns zero hits. Scoring parses inline: `json_decode` at `classes/external/score_speech.php:285`, a regex brace extraction fallback at `:287-289`, and a give up at `:291-293`. The conclusion survives, the parse is genuinely cheap and already happens, but `json_parser` is a class PresenterAI is planning to write, not one it can point at.

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

**D21. The learner sees a rewritten summary of the body language evidence, never the raw note, and the same gate covers the criterion feedback that already reaches them.**
Recorded 20 September 2026, settling plan section 9.1 in favour of its option (c). Full design in [DESIGN-visual-feedback-and-retention.md](DESIGN-visual-feedback-and-retention.md).

The learner gets a short second person summary of what the sampled frames showed, generated as one extra field in the scoring call's structured response and checked before it is stored. The raw vision output stays on the recording for staff and for a privacy export, on the `visualdatadays` clock D5 already gives it. This is what makes the no consent decision (question 8) rest on something that exists, which C1 records that it never did.

Three things follow that are part of the decision rather than implementation detail.

The gate runs over the two visual criterion feedback strings as well as the summary, because of C6: the criterion channel is live today and is unchecked. A gate on the new field alone would have been theatre.

A rejected string is never retried. It is replaced by a deterministic template built from the scores already in hand, and the plugin fires an event. A model that has just written a banned sentence is not reliably better on the second attempt, and paying the full prompt cost to find out is not a control.

A rejection on a criterion's feedback sets that criterion to `assessed = false`, so it leaves both sums in `compute_overall()` (`classes/rubric_manager.php:355-366`). Keeping a mark whose only written explanation the plugin judged unfit to show is the worst of the available positions, and the mechanism that removes it already exists and is already tested.

*Rejected: show the raw note.* It is unreviewed model prose about a named person's body and the shipped prompt's appearance bar is a request, not an enforcement (`classes/soapbox_gesture_vision.php:44-51` says so in the source).

*Rejected: show nothing and re-take question 8 on a per instance opt in.* D17 already makes body language a per instance opt in, so that would have delivered nothing new, and it leaves the C6 channel exactly as it is.

*Rejected: have the vision pass write the learner facing summary as well as the staff note.* It is free and it is the only model that has seen pixels, but it puts the model that is looking at a person's body closest to the learner, and it does not know the scores, so its summary cannot be anchored to the rubric.

*Rejected: a second, dedicated rewrite call.* Its one real advantage is a cheap retry, and the no retry rule above removes it.

*Rejected: a deterministic template with no model pass as the primary.* It can state the scores and it cannot state what the camera saw, and what the camera saw is the whole content of the transparency this decision exists to provide. It is kept as the fallback, where it is load bearing.

**D22. Automatic deletion is off by default. Two admin options sit on top of it: whether learners may download their own recording, and deletion after X days with a notice that cannot be switched off.**
Recorded 20 September 2026, settling plan section 9.2 in favour of its option (a). Full design in [DESIGN-visual-feedback-and-retention.md](DESIGN-visual-feedback-and-retention.md).

Site `retentiondays` defaults to 0, which means nothing deletes a recording on a clock. `allowlearnerdownload` defaults to 1. Saylor sets `retentiondays = 7` explicitly on both sites, which is carried decision 7 stated rather than inherited. `expiresat = 0` already means never to the cleanup query (`classes/task/soapbox_cleanup.php:56-58`), so this is a clamp change and a settings change, as D5 says, and `RETENTION_MAX_DAYS = 28` (`classes/soapbox_config.php:41`) goes with it.

Four things follow that are part of the decision.

`storedattempts` defaults to 0, meaning keep every attempt's media. The plan currently has `DEFAULT="2"` (`IMPLEMENTATION-PLAN.md:105`) and the `max(1, ...)` floor at `classes/task/soapbox_cleanup.php:93` makes pruning unconditional. Pruning is a second deletion path with no clock and no notice, so a default of 2 would destroy a learner's first recording the moment they start their third, on a site whose stated default is keep forever. Keep forever is not honoured by the retention setting alone.

No implicit change ever shortens an existing recording's life. `expiresat` is written once at finalize and a settings change never rewrites it. Turning deletion on does not backfill from `timecreated`, and turning deletion off does not cancel dates already shown to learners. Shortening always requires `cli/apply_retention.php` with an explicit basis, which D5 already called for.

The notice is not a setting and there is no way to suppress it. Every learner facing sentence about deletion is derived from the effective instance value before recording and from the row afterwards, never from `get_config()` at render time. SOLA gets this half right and half wrong in one file: the per attempt date comes from the row (`soapbox_present.php:255-256`) while four paragraphs around it come from the live setting (`:128-131,155-159,162-166,167-170`), so the prose starts lying the moment an admin changes the window.

Download and deletion are governed separately. `allowlearnerdownload` controls what a learner may keep of their own face. A grader taking a copy away is a different act and runs on `mod/presenterai:downloadany`, which is not implied by `viewallattempts` and carries `RISK_PERSONAL`. One setting cannot mean both things.

*Rejected: refusing to save deletion on with download off.* It is a defensible institutional policy, that assessment evidence must not leave the platform, and the plugin does not have the context to overrule it. It gets a loud admin warning and a mandatory learner sentence before recording instead.

*Rejected: a per activity download switch in v1.* Retention per activity is reasonable, because a weekly practice and a capstone are different artefacts. Download is a statement about a learner's own recorded face and differing by activity is something no learner can explain and no support person can defend. The per course lever already exists as a role override on the capability. The forward rule is fixed now so the field can be added later without a migration: a per activity download setting may only tighten the site setting, never loosen it.

*Rejected: letting `visualdatadays` take 0 to mean forever.* Under the media semantics 0 means forever, and here that would restore the exact state D5 exists to abolish. The minimum is 1 and there is no forever option. The two clocks do not share semantics and the settings page says so.

---

**D23. Body language is feedback only by default; an activity can opt in to scoring it.**
Recorded 6 October 2026 by Tom, settling 9.17. The default is option (b): the two visual criteria produce written feedback, gated by D21, and contribute nothing to `rawsum`, `rawmax`, the gradebook or any outcome. An activity setting, `visualscored`, default 0, lets a teacher score them as designed (option (c)). When it's on, the scoring prompt carries the counterweight the design document describes, so a behaviour that is visibly and permanently absent goes to `assessed = false` rather than to an assessed zero, and an absent `assessed` flag is never defaulted to true. The setting's help text says plainly that a model is judging a body from six stills and what that means for learners with disabilities.

**D24. No body language opt out by default; an activity can allow one.**
Recorded 6 October 2026 by Tom, settling 9.18. An activity setting, `allowvisualoptout`, default 0. When it's on, the learner sees a checkbox before recording, and ticking it has two effects the recommendation made conditions: the frames are never sampled or uploaded (not uploaded and ignored), and the page says the score is computed over the remaining criteria. It costs no model call and no marks. With the setting off, D17's audio mode remains the only route out.

**D25. English only until the plugin directory submission.**
Recorded 6 October 2026 by Tom, settling 9.22. Phases 3 and 4 add strings in `lang/en` only. One translation pass into SOLA's 45 other locales happens in phase 5, before the directory submission, once the strings have stopped changing. Privacy strings are translated, not left identical to English, so the parity trap in 9.22 isn't repeated.

**D26. When scoring runs on Moodle core AI, nothing else leaves core for a vendor key.**
Recorded 7 October 2026 by Tom. Until now, vision, slide vision and the summary judge fell back to the keyed order (Claude, OpenAI, Gemini, compatible) when the route was core, which is how a core site that also held a key got slide design notes. That's the wrong reading of an admin who picks core: they've chosen to keep learner content inside core. So when scoring resolves to core, whether by `airoute = core` or by `auto` finding nothing else, `route_resolver::client_for()` returns null for `vision`, `slide_vision` and `judge`. Scoring goes ahead on the transcript and the slide text, without a slide design note and without body language.

Four things follow.

No frames are taken on core, and the learner isn't told about any. Analyzing nothing while still sampling and uploading a frame sheet, and still telling the learner six frames go to an AI model, would make the disclosure false. So `visual_pipeline::takes_frames()` answers no when scoring resolves to core, by the same rule as above, and everything that decides whether frames exist asks it: the recorder's config doesn't sample, the frame disclosure and the D24 opt out aren't shown, the privacy paragraph leaves out its frames clauses, `start_upload` refuses a frame sheet (`error:framesdisabled`), and finalize deletes a sheet that arrived before the site moved to core. The visual pipeline then reports no evidence at all rather than "couldn't be analyzed", so a core attempt's feedback has no body language section: nothing a learner sees implies visual analysis. Added later the same day, after the first D26 commit left this undone.

The D21 gate doesn't fail closed on core. The visual pipeline has no evidence on the core route and never asks for a client (design 3.6), and `finalise()` gates nothing when there's no usable evidence, so the judge is never asked for and its absence withholds nothing. A test scores a core attempt with body language and slide design switched on and vendor keys present, and checks that no vision, slide or judge call is made, nothing is withheld, and there's no body language section. No gate code needed changing for this.

Transcription is the one exception and it can't be removed. Core AI has no audio action (D6), so a recording is still sent to the transcription service. The route's help text says so, and doesn't claim that nothing at all leaves core.

The settings page says it. The readiness list shows that body language and slide design feedback are off on core and that no frames are taken, the route's help text says no provider key is used for anything but transcription there, and the vendor fields that can't be used under core are hidden (the OpenAI key stays visible, because transcription uses it).

**D27. A permanent AI failure hands the attempt back to the learner, and a teacher can still grade it.**
Recorded 7 October 2026 by Tom, confirming what phase 3 built rather than changing it. When scoring fails for good (a bad response, too few spoken criteria assessed, the media gone with no transcript, the retries spent), the scorer sets the attempt to `failed`. `failed` is in `recording_manager::COUNTED_EXCLUDED`, so it doesn't use up one of the learner's attempts and they can record again. That's right: the failure wasn't theirs.

The attempt isn't lost to staff either. The submissions report lists it with its status and a grade link, `grade.php` opens it (`failed` isn't in `access::NOT_GRADABLE`), and a hand grade saved there moves it to `scored`, where it counts toward the attempts and the gradebook like any other. Rescore is the other way back: `grade_page::rescore_state()` offers it on a failed attempt that has a transcript or its media, and the scorer acts on `failed` when it's a rescore. `tests/failed_attempt_test.php` pins all three paths end to end, through the report, the grade form and the queued task.

A consequence worth knowing: a learner whose first attempt failed and who records again can end up with two scored attempts if a teacher later grades the failed one, which can take them past `maxattempts`. That's accepted. The cap stops a learner recording more than they're allowed, not a teacher grading work that was submitted.

**D28. An activity can hold AI feedback for a teacher to review before the learner sees it. Off by default.**
Recorded 7 October 2026 by Tom. A new activity setting, `reviewbeforerelease`, default 0, under the AI feedback heading. Saylor leaves it off: there's no teacher in its flow (Part 1), and this doesn't change that. It's for the sites that do have teachers and want to check what a model wrote before a learner reads it.

What's held, and where. The AI still scores. Its row is written with a new `presenterai_score.released = 0`; teacher rows are always 1, and the column defaults to 1 so every score that existed before the upgrade stays released. The attempt's `status` stays `scored`, because D8 keeps `status` for the scoring lifecycle and review is a release state, not a scoring one. While the current score is held, the learner's attempt list shows "Awaiting review" and the sentence "Your feedback is being reviewed by your teacher." in place of the score, with no feedback row at all, so the overall comment, criterion feedback, tips and body language summary all stay hidden. `grader::aggregate_for_users()` reads released scores only, so the gradebook, the `completionminscore` rule and the core grade and pass rules all wait for the release, and `notifier` isn't called.

Release. A teacher releases one attempt from its grading page, either as the AI wrote it ("Release to learner") or by saving their own grade in the existing form, whose button reads "Save and release" on a held attempt. A teacher's own grade is always a release. The submissions page says how many attempts are awaiting review, marks each one, and offers "Release all awaiting review" behind a confirmation page; it releases only attempts of learners the teacher can see in the active group, so separate groups hold. Every release flips the flag, fires the new `feedback_released` event (logged, edulevel teaching), pushes the grade, updates completion and sends the learner the usual message, in that order.

*Chosen: release all means every attempt awaiting review.* "Release all reviewed" would need a separate "reviewed" mark that a teacher sets per attempt and that nothing else uses. The confirmation page says how many attempts it will release, and a teacher who wants to change one first opens it instead.

Rescoring a released attempt while the setting is on writes a held row, so the attempt goes back to awaiting review. The grade and completion are recalculated without it, which takes the old released grade out of the gradebook until the new one is released. That follows `mod_assign`, which nulls the gradebook grade when a marking workflow grade moves out of released, and it means the gradebook never shows a number the learner's own page is hiding.

Turning the setting off releases everything held, at once, with the grade, completion and message each attempt would have had (`presenterai_update_instance()`). Nobody is going to release them otherwise. Turning it on hides nothing that's already released. The help text says both.

*Capability: none new; `mod/presenterai:grade` releases.* A grader can already release by saving their own grade on a held attempt, so a separate release capability couldn't stop a grader releasing; it would only make the AI's version harder to release than the grader's own, which is backwards. `mod_assign` has `mod/assign:releasegrades` because its marking workflow separates markers from the person who signs grades off, and PresenterAI has no marker allocation to separate.

*Privacy: held feedback is exported, marked as not released.* It's personal data the site holds about the learner whether or not it has been shown, and a subject access request covers what's held, not what's displayed. `mod_assign` does the same: its provider exports the grade and the marking workflow state regardless of release. Each exported score carries `released` and, when held, a sentence saying it hadn't been shown yet. Deletion is unchanged, since held rows are score rows like any other.

Backup and restore carry both fields, and a held score stays held after a restore. Course reset needs nothing new: it deletes score rows with the attempts. The plugin has no Behat features, so this is pinned by PHPUnit (`tests/review_release_test.php`, plus the event, privacy, backup and grading page tests).

## Part 4: open questions these two decisions raised

These are **new information produced by designing 9.1 and 9.2**, not a reopening of either. D21 and D22 stand as taken. What the design work found is that both of them sit on top of an older assumption nobody has written down as a decision, and on four smaller choices the plan never made. They belong in `IMPLEMENTATION-PLAN.md` section 9 when it is next updated and are numbered to continue it.

**9.17 Do the two visual criteria contribute to the gradebook in v1?** This is the largest of the six and it is the one D21 cannot answer, because D21 is about the words and this is about the mark.

The two shipped visual criteria score a body. `classes/rubric_manager.php:80-100` makes "open hands that mark structure", "a steady stance", "weight that stays settled", "looking at the camera lens" and "facial expression that matches what you are saying" into scored criteria, and names "fidgeting, rocking or pacing" as scored distracting habits. Section 6 of the plan (`IMPLEMENTATION-PLAN.md:604`) turns that score into a gradebook number, which Soapbox never had.

A learner who uses a wheelchair produces evidence in which the hands sit low and often out of frame, and passes every layer of D21's gate cleanly, because nothing the gate checks is violated: the prose is about hands, posture and framing, it names no protected term, and the judge is told to accept a negative observation about what the hands did. The output is a correct, well phrased sentence next to a low mark. The same walk holds for a tremor against "fidgeting", for a learner who does not look at lenses, and for facial paralysis against "facial expression that matches what you are saying". Nothing in the 930 line plan addresses it: `grep -n "disab\|accessib\|wheelchair\|accommodat\|exempt\|opt out"` over `IMPLEMENTATION-PLAN.md` returns one hit and it is about the video player's UI at line 768.

*Options:* (a) drop body language from v1 entirely and ship the five spoken criteria, which removes the vision pass, the frame sampler, `visualevidence`, the gate, the deny lists, the judge, the new capability and the new event; (b) ship body language unscored, as a labelled camera and framing check that contributes nothing to `rawsum`, `rawmax` or any outcome; (c) ship it scored as designed.
*Recommendation:* (b). Under (b) everything D21 specifies stays and is worth having, because a summary that carries no mark cannot discriminate in a grade, and the whole of D21's gate still earns its keep on the words. (a) is the cheaper answer if the date matters more than the feature. (c) is the one shape that should not ship: a graded criterion for gestures, assessed by a model from six thumbnails, in a course with no instructor, no moderation and no appeal (`IMPLEMENTATION-PLAN.md:612`).

One line of the scoring prompt is load bearing here and is currently pointed the wrong way. `classes/external/score_speech.php:212-214` tells the model "Scoring 0 with `assessed` true means you could see the behaviour and it was absent", which steers a learner whose behaviour is visibly and permanently absent toward an assessed zero rather than toward the one flag that would have removed the criterion from both sums. `:327` then defaults an absent flag to true. If the answer to 9.17 is (c), that sentence needs the counterweight described in the design document. Under (a) or (b) the question does not arise.

**9.18 Does a learner get a per attempt opt out from body language assessment?** Today the only route out is a teacher setting the instance to audio (D17), and at Saylor there is no teacher. So a learner who does not want a model judging their body has one option, which is not to submit.

The machinery is already built and free. `classes/rubric_manager.php:255-257` strips the visual criteria out of the rubric entirely when there is no evidence, so they never enter the prompt and never enter the allowlist at `classes/external/score_speech.php:154-164`, and `compute_overall()` at `classes/rubric_manager.php:355-366` excludes them from numerator and denominator alike. A checkbox that sets the evidence flag false costs no model call and no grade.
*Recommendation:* ship it, with two conditions or it is theatre. The frames must not be uploaded at all when it is ticked, rather than uploaded and ignored. And the page must say the score is computed over the remaining criteria, so ticking it does not read as forfeiting marks.
Note this is the clean answer to the disability question in 9.17 that does not require the system to detect a disability, which would mean inferring health data from six stills.

**9.19 Who reads the staff side of this at Saylor, and what should Saylor set `storevisualevidence` to?**
D21 keeps the raw note for staff and for a privacy export, and the design adds a staff only log of gate rejections so the deny list can be tuned. At Saylor nobody holds `teacher`, `editingteacher` or `manager` in a course, so the grading screen has no reader and the admin report has no reader. The export route is also thinner than it looks: the note expires at 30 days and the statutory response window for a subject access request is one month, so by the time most requests are answered the field is already null.
The design therefore makes storing the raw note a site setting, `storevisualevidence`, default on, which is what D21 decided. What is open is the operational answer: does Saylor turn it off, and if the answer is that nobody reads the rejection report either, then the report is not a control and should not be described as one.

**9.20 Is there a per activity download setting?** D22 ships site setting plus capability and fixes the forward rule. The plan never mentioned a download setting at all: `grep -i download IMPLEMENTATION-PLAN.md` returns five hits, all of which assume download is unconditional. If a per activity field is wanted it is cheap to add later under the tighten only rule, and it should not be added on a guess.

**9.21 Can a learner delete their own recording?** "Kept until it is deleted" is a lie on a keep forever site if the learner cannot be one of the people who deletes it. Under a 7 day clock a learner who regretted a recording waited a week; under D22's default their face is on the site indefinitely with no self service answer. `delete_recording` is in the phase 1 list (`IMPLEMENTATION-PLAN.md:752`) with no stated caller, and the plan names only two capabilities anywhere (`:466`, `:492`).
*Recommendation:* yes, behind `mod/presenterai:deleteownmedia`, deleting the media only. It is safe precisely because the score row survives, so the attempt still counts toward the grade and toward the attempt cap and deleting media cannot be used to escape a bad mark.

**9.22 Does PresenterAI ship the 45 non English locales?** SOLA has 46 `lang` directories. Phase 0 names only `lang/en/presenterai.php` (`IMPLEMENTATION-PLAN.md:749`) and no phase mentions translation. The learner facing strings for these two decisions alone are 34 keys, which is 1,530 translated strings at 45 locales. That is the number, and it should be decided rather than discovered. Note the related trap: SOLA's own parity test carries 68 privacy strings in an identical to English backlog (`tests/lang_completeness_test.php:796`), which passes a parity gate while a learner making a data request reads English.
