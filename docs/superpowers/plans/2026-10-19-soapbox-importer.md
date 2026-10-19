# PresenterAI 0.5.0: Soapbox importer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A command that imports SOLA Soapbox assignments, topics, attempts and scores into PresenterAI activities, per course, as a dry run by default, idempotent, resumable and reversible, writing nothing to any SOLA table, and a SOLA interlock so SOLA's cleanup can't delete media PresenterAI now owns.

**Architecture:** `classes/local/migration/` holds a `source_reader` interface (every read of SOLA goes through it), a pure `mapper`, a `runner` (preconditions, locks, one transaction per source assignment, create_module, stand-in rewrite, map state machine), a `verifier` (plan 7.6 tiers 1 to 3 into `presenterai_migreport`) and a `rollback`. `cli/migrate_from_local.php` is the command. Tests run against a source test double so CI (which has no SOLA tables) covers everything but the thin reader.

**Tech Stack:** PHP 8.1+, Moodle 4.5+ (`create_module()`, delegated transactions, `lock_config`, `core\session\manager`), PHPUnit (`advanced_testcase`), the existing PresenterAI generator.

**Spec:** `docs/superpowers/specs/2026-10-19-soapbox-importer-design.md` (read it first, then `docs/IMPLEMENTATION-PLAN.md` section 7 and `docs/DECISIONS.md` D3, D4, D8, D10 to D15). Where this plan and the older plan differ, this plan and the spec win.

## Global Constraints

- Moodle 4.5+ (`requires = 2024100700`), PHP 8.1+, component `mod_presenterai`. Release 0.5.0 (beta), version `2026101901`.
- **The migrator writes nothing to any table whose name starts with `local_ai_course_assistant_`, ever, including on rollback (D13).** It does not call `score_manager::save_ai_score()`/`save_teacher_score()` (they fire events, push the gradebook and message the learner); it inserts `presenterai_*` rows directly. It moves no byte of media (D10: S3 only, `--backend=fs` refused). `visual_observation` is never imported (D11). Every migrated instance gets `grade = 0` (D12). No outer transaction around a whole run: one delegated transaction per source assignment.
- **No learner text, name or transcript in `presenterai_migration_map.payload` or `presenterai_migreport.mismatches`.** Ids, counts, hashes and states only.
- **Map contract shared with SOLA's interlock (Task 6):** `presenterai_migration_map.sourcetable` is the literal string `local_ai_course_assistant_sbx_rec` for a recording row, `local_ai_course_assistant_sbx_assign` for an assignment, `local_ai_course_assistant_sbx_topic` for a topic; `sourcesiteid` is `sha1($CFG->wwwroot . '|' . get_site_identifier())` (one function, `migration\ids::site_id()`, used by everything). `targetid` is the PresenterAI row id.
- Rows the migrator creates carry the `legacy*` columns (`legacyassignid`, `legacytopicid`, `legacyrecid`, `legacyscoreid`, `legacymeanscore`, `legacymeta`) so backup, restore and privacy export carry them as they already do.
- PHPDoc with `@param`/`@return` on every method and PLAIN `array` types (the repo's phpdoc gate fails on generic `array<...>` @param); lines at most 132 characters counted in characters (CI runs phpcs with `--max-warnings 0`); no em dashes in docs or strings; US English; contractions in prose. New lang strings go in `lang/en/presenterai.php` only, in the same commit as the `get_string()` call.
- Test environment: `export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"` (Moodle 4.5 refuses PHP 8.5); `rsync -a --delete --exclude=.git --exclude=.superpowers --exclude=node_modules /Users/tom.caswell/ai-projects/wt-presenterai-importer/ ~/Sites/moodle/mod/presenterai/` (THIS worktree, never another checkout) then `php ~/Sites/moodle/admin/cli/purge_caches.php`; after any `version.php` or `install.xml` change run `php admin/tool/phpunit/cli/init.php` from `~/Sites/moodle` first; run ONE test file per phpunit call by path (`php vendor/bin/phpunit mod/presenterai/tests/<file>.php`) and READ THE COUNT ("OK (N tests ...)", N must not be 0; a bare directory or a stale environment prints OK having executed nothing). The local Moodle PHPUnit database has SOLA's tables (SOLA's own code is installed there). Do NOT start the whole `mod_presenterai_testsuite` (about 610 tests; the controller runs it).
- Never mutate the tracked tree to prove a test can fail and then restore it; plant the defect in a temporary copy of the file you point the test at, or use `scripts`-style tooling if the repo has one (check first: the SOLA repo's `scripts/prove_test.py` exists at `/Users/tom.caswell/ai-projects/ai_course_assistant/scripts/prove_test.py` and takes `--file --find --replace --filter` with `SOLA_REPO` pointing at the repo; read it and adapt the invocation to this repo, or write the equivalent flock + temp-copy + revert flow in your scratch directory).
- Commits end with: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` and `Claude-Session: https://claude.ai/code/session_016qGUU4qerkRHrXTh1qyBp6`. Work only in `/Users/tom.caswell/ai-projects/wt-presenterai-importer` on branch `feat/soapbox-importer`; never `cd` to `~/ai-projects/moodle-mod_presenterai`; no bare `git stash`. Do NOT touch AWS except where a task says so.

## Review Focus

1. Dry run writes nothing (no row, no event, no `course_modules` change). (Tasks 3, 5.)
2. No SOLA row is ever written; a rollback restores each stand-in exactly. (Tasks 2, 3, 4.)
3. `overallpct` is computed, never copied from `overall_score`; an unreconstructable row says so instead of faking a percentage. (Task 1.)
4. A source assignment edited after it was migrated is refused, not resumed. (Task 3.)
5. Two recordings sharing a `scoreid` refuse the course; an orphan score is counted and left. (Tasks 2, 3.)
6. No learner text in the map payload or the report. (Tasks 3, 4, 5.)
7. A section is never created by the fallback. (Task 3.)

---

### Task 1: The contract, the reader interface and the mapper

**Files:**
- Create: `classes/local/migration/ids.php`, `classes/local/migration/source_reader.php`, `classes/local/migration/mapper.php`, `tests/migration_mapper_test.php`, `tests/fixtures/migration/` (small JSON fixtures of SOLA-shaped rows)
- Modify: `lang/en/presenterai.php` only if the mapper needs a string (it should not)

**Interfaces (use these names exactly; later tasks depend on them):**
- `ids::SOURCE_REC`, `ids::SOURCE_ASSIGN`, `ids::SOURCE_TOPIC` (the three literal strings in Global Constraints); `ids::site_id(): string`.
- `source_reader` interface methods, all returning plain arrays or scalars (no `stdClass`):
  - `site_id(): string`
  - `course_ids_with_data(): int[]`
  - `assignments(int $courseid): array` (list of rows keyed by SOLA column names: `id, courseid, name, intro, introformat, ptype, mode, min_seconds, max_seconds, max_attempts, stored_attempts, rubricid, speaking_level, slides_enabled, slide_vision, visible, timecreated, timemodified`)
  - `topics(int $assignid): array` (`id, assignid, title, instructions, instructionsformat, sortorder, timecreated, timemodified`)
  - `recordings(int $assignid): array` (`id, assignid, userid, topicid, mode, storage_key, deck_key, frames_key, duration_seconds, size_bytes, status, transcript, slide_timeline, scoreid, expires_at, timecreated`)
  - `score(int $scoreid): ?array` (`id, rubricid, userid, courseid, scores` (the JSON string), `overall_score, ai_feedback, session_meta` (JSON string), `timecreated`)
  - `orphan_score_count(int $courseid): int`
  - `shared_score_ids(int $courseid): int[]` (a `scoreid` used by more than one recording)
  - `speaking_level_for_course(int $courseid): string` (`''` or `'general'` when absent)
  - `rubric_criteria(int $rubricid): ?array` (criterion name => max score, names normalised the way SOLA's `score_speech::normalise_name()` does, `null` when the rubric no longer exists)
  - `preset_criteria(string $level): array` (same shape, for the level preset)
  - `storage_settings(): array` (`bucket, region, prefix` of SOLA's `soapbox_storage_*`, no keys)
  - `interlock_present(): bool`
  - `queued_scoring_task_count(): int` (adhoc `score_recording` tasks queued or running)
  - `standins(int $courseid): array` (list of `['cmid' => int, 'assignid' => int, 'externalurl' => string, 'visible' => int, 'visibleoncoursepage' => int]` for `mod_url` activities that point at a Soapbox assignment, ALL of them)
- `mapper` is a final class of pure static methods (no `$DB`, no `$CFG`): `assignment_to_instance(array $assign): array` (fields for `create_module`'s moduleinfo: `ptype, mode, minseconds, maxseconds, maxattempts, storedattempts, grade=0, retentiondays=-1, legacyassignid, name, intro, introformat`, ...; check `mod_form.php`/`instance_manager::normalise` for the exact field names and defaults); `topic_to_row(array $topic, int $instanceid): array`; `recording_status(array $rec, bool $hasscore): string` (`scored` when a score row exists else `abandoned` for a `deleted` source row, otherwise the PresenterAI equivalent of the SOLA status; check `recording_manager` status constants); `attempt_numbers(array $recordings): array` (recording id => attempt number, assigned per user by `timecreated` among counted statuses, ties broken by id); `recording_to_row(array $rec, int $instanceid, ?int $topicid, int $attemptnumber, bool $hasscore): array` (keys and `deck_key`/`frames_key` carried verbatim; `storagekey` null with `mediadeletedat = expires_at` and `mediagonereason` set when SOLA nulled the key; `expiresat` copied verbatim; `legacyrecid`); `score_to_row(array $score, array $context): array` where `$context` carries `speaking_level`, `rubric` (criteria or null), `preset` (criteria) and the new recording/user ids, and the result holds `scores` JSON with `counts = true`, `rawsum`, `rawmax`, `overallpct` (computed or null), `scoreprovenance` (`exact` | `rubric_backfill` | `preset_backfill` | `unreconstructed`), `feedback` from `ai_feedback`, `tips` from `session_meta['tips']`, `visualstatus`, `released = 1`, `origin = 'ai'`, `legacymeanscore` = SOLA's `overall_score`, `legacyscoreid`, `legacymeta` = the session meta JSON minus `visual_observation`.

- [ ] **Step 1: Read.** Read plan section 7.2 and 7.4 in `docs/IMPLEMENTATION-PLAN.md`, `classes/local/score_manager.php` (`decode_criteria` and how criteria JSON is shaped), `classes/local/recording_manager.php` (status constants, `COUNTED_EXCLUDED`), `classes/instance_manager.php` (`normalise`), `db/install.xml` (the `presenterai_*` and `legacy*` columns), `classes/output/attempt_row.php` lines around 100 (the "recording deleted" branch), and the SOLA files named in the spec section 2 (`/Users/tom.caswell/ai-projects/ai_course_assistant/classes/external/score_speech.php` around lines 300-375 for the stored criteria and meta shapes, `classes/rubric_manager.php` around 320-375). Write in your report the exact stored shapes you found for `practice_scores.scores` (old and new) and `session_meta`.
- [ ] **Step 2: Failing tests first** (`tests/migration_mapper_test.php`, with fixtures): every status mapping (SOLA `uploading|uploaded|scored|deleted|failed` crossed with has-score or not); attempt numbering across several users and statuses and with equal `timecreated`; `recording_to_row` for a surviving-media row (keys verbatim) and a deleted row (keys null, `mediadeletedat` = `expires_at`, `expiresat` verbatim); `score_to_row` for (a) a new-shape row with `max_score` on every criterion (`exact`, `overallpct` computed from raw sums, NOT `overall_score`), (b) an old-shape row with no `max_score` and no `assessed` where the rubric still exists (`rubric_backfill`, absent `assessed` counts as assessed), (c) an old-shape row with `rubricid = 0` and a level preset (`preset_backfill`), (d) a row whose rubric was deleted and no preset matches (`unreconstructed`, `rawmax = 0`, `overallpct = null`, `legacymeanscore` kept), (e) a criterion name in no rubric, (f) `visual_observation` present in `session_meta` is absent from `legacymeta`, (g) `tips` and `visual_assessed` carried, (h) a row with `session_meta` null. Also a test that `score_to_row`'s `overallpct` for 4 of 5 is 80.00, never 4. Run: fail (classes missing).
- [ ] **Step 3: Implement** `ids`, the interface and `mapper`. Keep each method short. Run the test file; read the count.
- [ ] **Step 4: Prove** two guards can fail (for example: make `overallpct` copy `overall_score`; make `recording_to_row` recompute `expiresat`) with a temp copy of `mapper.php`. Report exit codes or the equivalent output.
- [ ] **Step 5: Run** `migration_mapper_test`, `phpdoc`-style lint by `php -l` on every new file, and `awk 'length > 132'` (by characters: `python3 -c` if needed) on every new file. Commit ("Add the Soapbox migration contract, the source reader interface and the mapper").

---

### Task 2: The SOLA reader, and the no-SOLA-write guard

**Files:**
- Create: `classes/local/migration/local_aica_reader.php`, `tests/migration_reader_test.php`, `tests/migration_no_source_write_test.php`

**Interfaces:**
- Consumes: Task 1's `source_reader` and `ids`.
- Produces: `local_aica_reader implements source_reader`. SELECTs only. Reads SOLA classes (`rubric_manager`, `soapbox_course_link`'s URL regex, `soapbox_cleanup::PRESENTERAI_INTERLOCK`) only behind `class_exists()` / `defined()` guards, so PresenterAI keeps no runtime dependency (D3).

- [ ] **Step 1: Failing tests first.** `migration_reader_test`: skipped (`markTestSkipped`) when `$DB->get_manager()->table_exists('local_ai_course_assistant_sbx_rec')` is false. Otherwise insert SOLA rows with plain `$DB->insert_record` (SOLA's tests do the same; there is no SOLA generator) and assert each reader method: assignments and topics per course; recordings including `deleted` rows; `score()` for a row whose score is reached through `scoreid`; a `practice_scores` row of `session_type = 'speech'` that NO recording points at is not returned and is counted by `orphan_score_count`; two recordings sharing a `scoreid` appear in `shared_score_ids`; `speaking_level_for_course` from the `soapbox_level_course_<id>` setting (absent means general); `rubric_criteria` for a stored rubric and `null` for a missing one; `storage_settings` returns bucket, region and prefix and NEVER a key; `standins` finds a `mod_url` whose `externalurl` is `/local/ai_course_assistant/soapbox_present.php?id=N`, one with an absolute URL carrying the wwwroot, and returns BOTH when two point at one assignment (no collapse); `site_id()` equals `ids::site_id()`.
  `migration_no_source_write_test`: (a) the reader class contains no `insert_record`, `update_record`, `delete_records`, `set_field`, `execute(` call (source scan of every file under `classes/local/migration/`, allowing `$DB->execute` only if it is a SELECT you justify in a comment, preferably none); (b) a behavioral guard: run a full reader pass over fixture rows and assert the row counts and checksums of the three `sbx_*` tables and `practice_scores` are unchanged afterwards. The scan covers the whole `migration/` directory, so later tasks are held to it.
- [ ] **Step 2: Implement** the reader. Selecting scores ONLY through `sbx_rec.scoreid` (plan 7.2 SQL). `queued_scoring_task_count()` counts rows in `{task_adhoc}` whose `classname` is `\local_ai_course_assistant\task\score_recording` (with the leading backslash as core stores it). `interlock_present()` is `defined('\local_ai_course_assistant\task\soapbox_cleanup::PRESENTERAI_INTERLOCK')` (use `defined()` with the class-constant string form). `standins()` reimplements the regex from SOLA's `soapbox_course_link.php` lines 76 to 82 with no SOLA class call, tolerating the absolute-URL form.
- [ ] **Step 3: Run** both test files (read counts; the reader file may report skipped tests if the SOLA tables are absent: note which), `php -l`, line length. Prove the source-scan guard fails when you add an `update_record` call to a temp copy of the reader. Commit ("Add the SOLA reader and a guard that the importer never writes to SOLA").

---

### Task 3: The runner (dry run, apply, resume, refusals)

**Files:**
- Create: `classes/local/migration/runner.php`, `classes/local/migration/plan.php`, `tests/migration_runner_test.php`
- Modify: `lang/en/presenterai.php` (messages), `tests/generator/lib.php` only if a helper is needed

**Interfaces:**
- Consumes: Tasks 1 and 2.
- Produces: `runner::__construct(source_reader $reader, ?\core\lock\lock_factory $locks = null)`; `runner::plan_course(int $courseid): plan` (read-only: no write, no event); `runner::apply_course(int $courseid): plan` (does the work, returns the same plan object filled with outcomes and counts); `plan` value object: per assignment its decision (`create`, `resume`, `skip_verified`, `refuse` with a code and message), counts (assignments, topics, recordings by status, scores, distinct learners, orphans), the stand-in decision (`rewrite`, `leave_visible_no_stealth`, `none_fallback_last_section`, `refuse_duplicate_standins`), and `preconditions` (list of met/unmet with messages). `runner::preconditions(): array` (list of unmet precondition codes with messages, empty when fine).

- [ ] **Step 1: Failing tests first** (`migration_runner_test`, source double returning arrays, real PresenterAI tables, `\core\session\manager::set_user(get_admin())`, a generated course with sections and a `mod_url` stand-in via `create_module('url', ...)`): dry run (`plan_course`) writes nothing (compare row counts of `presenterai*`, `course_modules`, `course_sections`, `logstore_standard_log`, and assert no event is triggered with `$sink = $this->redirectEvents()`); apply creates the instance (`grade = 0`, `retentiondays = -1`, `legacyassignid`), topics, recordings, scores, and map rows in state `verified` at the end; no `\mod_presenterai\event\*` fires, no message is sent (`redirectMessages`), `grade_items` untouched (assert `grade_items` count for the course unchanged); attempt numbers and statuses as the mapper gives; a `deleted` source row becomes a `scored` attempt with null keys; apply twice is a no-op (second run's plan says `skip_verified`, counts unchanged); resume: force a failure after topics are created and before recordings (a seam: protected `after_topics()` hook overridden by an anonymous subclass that throws), assert state is `created`/`attached`, then re-run and assert it completes without duplicating the instance or topics; refusals each with its own test: preconditions unmet (S3 settings differ, backend not s3, interlock absent, queued scoring tasks), two recordings sharing a `scoreid` refuse the course, a source assignment whose `timemodified` is later than its map row refuses (not resumes), a map `targetcmid` that no longer resolves becomes `failed` and is reported not recreated, two stand-ins for one assignment refuse; stand-in rewrite writes the original `externalurl` and visibility into the map `payload` BEFORE the rewrite and points the stand-in at the new activity; with `$CFG->allowstealth` off the stand-in stays fully visible and the plan says so; with no stand-in the instance lands in the LAST section and no section is created (assert `course_sections` count); a per-course lock is taken and released (lock factory double); a failure in one assignment does not roll back another (two assignments, first fails): the second still completes; no learner text is in any map `payload` (assert on a fixture transcript string). Run: fail.
- [ ] **Step 2: Implement** `runner` and `plan` following plan 7.1, 7.3 and 7.5 exactly (read them again; they carry the order of operations). Creating the instance goes through `create_module()` (read `course/lib.php` and `course/modlib.php` as the plan says; confirm `legacyassignid` persists from moduleinfo, else `update_record` after create). Rows other than the instance are inserted directly with the mapper's output. Every write for one assignment sits in one `$DB->start_delegated_transaction()`; the map rows are written in the same transaction. Messages via `get_string('migration:...', 'mod_presenterai')`.
- [ ] **Step 3: Run** `migration_runner_test` (read the count), `migration_mapper_test`, `migration_no_source_write_test`; `php -l`; line length. Prove three guards can fail with temp copies: (a) the dry run writing, (b) the stale-source refusal removed, (c) the stand-in payload written after the rewrite. Commit ("Add the Soapbox migration runner: dry run, apply, resume and refusals").

---

### Task 4: Rollback and verification

**Files:**
- Create: `classes/local/migration/rollback.php`, `classes/local/migration/verifier.php`, `classes/local/migration/object_checker.php` (interface: `exists_with_size(string $key, int $bytes): bool`), `classes/local/migration/s3_object_checker.php` (uses the existing S3 store; read `classes/local/storage/s3_store.php`), `tests/migration_rollback_test.php`, `tests/migration_verifier_test.php`
- Modify: `lang/en/presenterai.php`

**Interfaces:**
- Consumes: Tasks 1 to 3.
- Produces: `rollback::__construct(source_reader $reader)`, `rollback::rollback_course(int $courseid): array` (counts of what it removed and what it refused); `verifier::__construct(source_reader $reader, object_checker $checker)`, `verifier::verify_course(int $courseid, int $operatorid): array` returning `['passed' => bool, 'counts' => array, 'mismatches' => array, 'storagesampled' => int, 'storagefailures' => int]` and inserting one `presenterai_migreport` row (`runid`, `courseid`, `counts` JSON, `mismatches` JSON of ids and codes only, `mismatchcount`, `storagesampled`, `storagefailures`, `passed`, `operatorid`).

- [ ] **Step 1: Failing tests first.** Rollback: removes only instances whose `legacyassignid` matches a `created`/`verified` map entry of this site, restores each stand-in's recorded `externalurl` and visibility from the map `payload`, deletes the `presenterai_*` rows it created and their map rows, touches no SOLA row (reuse the checksum guard), refuses an instance whose `grade != 0` (and reports it), leaves an instance a teacher created by hand (no map entry) alone, and a second rollback is a no-op. Verifier: tier 1 counts all exact including `deleted` source rows (assignments, topics, recordings by all statuses, recordings with surviving media, scores, distinct learners); tier 2 per score row: transcript equality by sha1, same criterion count, names and order, integer scores and feedback strings, `legacymeanscore` equals the source `overall_score`, where `rawmax > 0` the recomputed mean is within one of the source mean else the row must be `unreconstructed`, `expiresat` equals `expires_at`; a doctored destination row (change one feedback string, drop a criterion) fails tier 2 with the recording id in `mismatches` and NO text in it; tier 3 S3 HEAD through the `object_checker` double for every surviving-media recording under a threshold (default 2000) and a deterministic sample above it, a missing object or a size mismatch increments `storagefailures` and fails; a report row is written per call and `passed` is false on any mismatch or storage failure; the report holds no learner text.
- [ ] **Step 2: Implement.** Read `classes/local/storage/s3_store.php` to see how to HEAD an object (read-only); the real `s3_object_checker` is thin and is exercised only on dev. Tier 4 is a runbook step, not code.
- [ ] **Step 3: Run** the two test files plus the earlier migration test files (one call each, read counts), `php -l`, line length; prove two guards can fail (rollback ignoring `grade != 0`; verifier skipping `deleted` rows in the source count). Commit ("Add Soapbox migration rollback and verification").

---

### Task 5: The command, strings, privacy declarations, runbook

**Files:**
- Create: `cli/migrate_from_local.php`, `docs/MIGRATION-RUNBOOK.md`, `tests/migration_cli_test.php`
- Modify: `classes/privacy/provider.php` (declare the two tables), `lang/en/presenterai.php`, `tests/privacy_provider_test.php` (or the nearest existing privacy test)

**Interfaces:**
- Consumes: Tasks 1 to 4.
- Produces: the command `php mod/presenterai/cli/migrate_from_local.php` with `--course=<id>|all`, `--apply`, `--rollback`, `--verify`, `--report`, `--json`, `--help`; dry run when `--apply` is absent; refuses `--backend=...` and any unknown option; exit codes 0 done or clean dry run, 1 error or refusal, 2 an unmet precondition. Output: counts, decisions and messages only, never a transcript, a name or an S3 key secret; `--json` prints the plan/result array.

- [ ] **Step 1: Failing tests first.** `migration_cli_test`: the CLI file is `CLI_SCRIPT`, uses `cli_get_params`, runs as admin, a dry run's output names counts and every assignment taking the last-section fallback, `--apply` is required to write (assert the same fixture course is unchanged after a run without it), `--rollback` and `--verify` route to the right classes, unknown option and `--backend=fs` are refused with exit 1 and a fixed message that does not echo the value, an unmet precondition exits 2; a source scan pins the output channels (`cli_writeln`/`cli_error`/`cli_problem` only; no `var_dump`, `print_r`, `echo` of fixture text); the runner/verifier/rollback are exercised through a seam (the CLI builds them through one factory method a test can override or through a `--reader` test hook that is refused outside PHPUnit). Privacy: `get_metadata()` declares `presenterai_migration_map` and `presenterai_migreport` (the report has `operatorid`; the map has no user data), and a test asserts neither table's declared fields or stored fixture rows contain learner text; the user export and erasure of an operator do not break. Run: fail.
- [ ] **Step 2: Implement** the command and the declarations. Every message is a lang string with the code the runner returns. The runbook (`docs/MIGRATION-RUNBOOK.md`, plain short sentences, no em dashes): who should run this and when; preconditions with the exact fix for each; dry run first and what to look for (the fallback list, refusals); apply per course; verify and the three-learner human check (tier 4: one with a surviving video and slides, one whose video was deleted but whose score survived, one whose newest attempt is `failed`); rollback and its limits; the S3 settings rule and why it cannot be relaxed; the bucket lifecycle rule warning (plan 9.3); that a dry run cannot prove the real run; that nothing in SOLA is changed and SOLA 7.13.0 or later is required (the interlock); what to do when the report says `unreconstructed` rows exist.
- [ ] **Step 3: Run** `migration_cli_test`, the privacy test, all earlier migration tests (one call each, read counts), the existing `privacy_provider_test` and `lang`-related tests the repo has, `php -l`, line length. Commit ("Add the migration command, privacy declarations and runbook").

---

### Task 6: SOLA 7.13.0, the cleanup interlock (separate repository, separate worktree)

Written as its own plan in the SOLA repo after Task 1 fixes the contract: `docs/superpowers/plans/2026-10-19-soapbox-interlock.md` there. Summary: `soapbox_cleanup` gets `PRESENTERAI_INTERLOCK = true` and skips each recording that PresenterAI's `presenterai_migration_map` holds for this site (the literals and `site_id()` formula in Global Constraints), one audit row per run with a count; tests for mapped, unmapped, and no-PresenterAI-tables; version 7.13.0; wiki and notes updated.

---

### Task 7: Version, README, release notes, release, deploy, proof (controller)

Not delegated except the doc edits: `version.php` `2026101901` / `0.5.0`; `README.md` (the migrator exists, how to run it, links the runbook; remove "planned next" at lines about 5 and 134); a release-notes file in the repo's shape ("Added / Upgrading / Not in this release / Plugin version"); `docs/IMPLEMENTATION-PLAN.md` phase 4 marked built. Then: whole-branch review, full `mod_presenterai_testsuite`, PR, CI, merge, tag `v0.5.0`, GitHub release with `mod_presenterai.zip`, a copy of `deploy_sola.sh` for the `mod/presenterai` and `public/mod/presenterai` directories, deploy to dev then alpha (after SOLA 7.13.0), and the proof run of spec section 8.
