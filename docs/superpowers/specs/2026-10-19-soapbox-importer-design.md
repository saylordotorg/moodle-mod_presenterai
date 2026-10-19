# PresenterAI 0.5.0: import Soapbox recordings and scores (phase 4)

Status: APPROVED to build by Tom on 2026-10-19 ("Pls build PresenterAI feature to import recordings and scores"). The design already exists: `docs/IMPLEMENTATION-PLAN.md` section 7 and decisions D3, D4, D8, D10 to D15 in `docs/DECISIONS.md`. This spec does not redesign it. It says what is built in 0.5.0, rules on the questions the plan left open, adds the one SOLA change the plan requires, and says how the result is proven. Written 2026-10-19.

## 1. What we are building, and how we will know it worked

**Purpose.** SOLA's Soapbox feature (deprecated since SOLA 7.5.2) stores a learner's presentation attempts, scores and written feedback in SOLA tables, and nowhere else in Moodle. PresenterAI is its replacement, a standalone activity. A site that has Soapbox data needs to move it over without losing a learner's history, and SOLA can only remove Soapbox once that is possible. The migration is non destructive, idempotent, dry run by default, moves no byte of media, and writes nothing to any SOLA table.

**Success criteria**

1. `php admin/cli/...` (name in 4.1) on a site with Soapbox data produces, per source assignment, a PresenterAI activity in the same course carrying the same topics, every attempt (all statuses, including attempts whose video has already expired) and every score with its per-criterion detail, feedback and tips.
2. A dry run lists exactly what an apply would do and changes nothing: no activity, no row, no event, no write to any table.
3. Running apply twice changes nothing the second time; an interrupted run resumes; a rollback removes only what this migrator created and restores each course page stand-in as it was.
4. The verification (plan 7.6 tiers 1 to 3) runs per course and records a passing or failing `presenterai_migreport` row. Tier 1 counts are exact. A row that cannot be reconstructed is reported, never faked.
5. No SOLA table row is written, updated or deleted by the migrator, including on rollback. A test pins this.
6. SOLA's own cleanup cannot delete media that PresenterAI now owns (section 5).
7. The whole path is proven on the dev site with synthesized Soapbox data: dry run, apply, verify, rollback, apply again (section 8).

**Out of scope.** Dropping the `sbx_*` tables and removing Soapbox code from SOLA (the plan gates both on a passing report per course; that is SOLA 8.x). The S3 bucket lifecycle rule on the `soapbox/` prefix (infrastructure, plan 9.3). Moodle mobile app playback (9.10). Translations (phase 5). A web page for the migration (see ruling 5). Importing `visual_observation` (D11). Importing SOLA token spend (`presenterai_aiusage`). Production Learn and Degrees: the plan measured zero Soapbox recordings on both on 2026-09-20 (9.15), and neither is upgraded before 2027.

## 2. Current state (verified 2026-10-19)

- `mod_presenterai` is at 0.4.1 (2026100708) on `main`, public repo `saylordotorg/moodle-mod_presenterai`. `db/install.xml` already holds `presenterai_migration_map`, `presenterai_migreport` and the `legacy*` columns on four tables; nothing uses them. No migration code, CLI, lang string, test or privacy declaration for them exists.
- `score_manager::save_ai_score()` and `save_teacher_score()` fire events, push the gradebook and message the learner. The importer inserts rows directly and never calls them.
- SOLA is at 7.12.0. `sbx_rec` has no score or feedback columns: they live in `practice_scores`, reached only through `sbx_rec.scoreid`. `practice_scores.overall_score` is a 0 to 5 mean, not a percent; `pct` and `visual_assessed` exist only inside `session_meta` and only on rows written since SOLA 7.5.1; `tips` has been there since 6.7.0. `soapbox_cleanup` marks media-expired rows `deleted` with the three keys nulled and keeps transcript, score and feedback.
- `sbx_assign.rubricid` and `sbx_assign.speaking_level` are dead columns: no form writes them and no code reads them. The speaking level that scored an attempt is the per-course setting `soapbox_level_course_<id>` in `config_plugins` (absent means general), and a row scored with a preset has `practice_scores.rubricid = 0`. So the `preset_backfill` step of plan 7.2 reads the course setting, not the assignment column; the reader interface exposes it as `speaking_level_for_course($courseid)`.
- Measured on 2026-10-19 (counts only): dev has 3 assignments, 2 topics, 5 recordings and 30 speech scores; all 5 recordings are already `deleted` with no storage key, 2 of them have a transcript and a score, and 27 of the 30 scores are seeded demo rows. Alpha has no Soapbox rows. Production has none (plan 9.15). Media is deleted after 7 days (28 maximum) and pruned to the newest `stored_attempts` (default 2), so for most real data only the transcript, scores and feedback can be imported.
- Both plugins are installed in the local Moodle and its PHPUnit database. CI installs only the plugin under test, so SOLA's tables do not exist there.

## 3. Rulings

Each says what was decided, why, and what it costs if wrong. Tom did not answer the plan's open questions, so these follow the plan's own recommendations and are listed in the release notes.

1. **Build phase 4 as written in plan section 7**, with decisions D3 (no runtime dependency on SOLA), D8, D10 to D15. Where this spec and the plan differ, this spec wins and says so.
2. **Plan 9.8: a row with no recoverable denominator gets `overallpct = null`, `scoreprovenance = 'unreconstructed'`, and keeps SOLA's mean in `legacymeanscore`.** No assumed maximum of 5. Cost if wrong: a stakeholder wants a percentage on every row; it can be added later and recorded on the row.
3. **Plan 9.9: an assignment with no `mod_url` stand-in lands in the last section of its course, not section 0.** The dry run lists every assignment that takes the fallback.
4. **Migrated instances get `retentiondays = -1` (follow the site setting) and `grade = 0` (D12).** Each imported attempt keeps its own `expiresat` verbatim; recomputing it would move every learner's deletion date.
5. **0.5.0 ships a command line tool and a runbook, no web page.** The plan lists `migrate.php`; it is a convenience, the dry run output is the review surface, and a web page that writes to live courses needs its own security pass. Cost if wrong: an admin without shell access asks for it; it is an additive release.
6. **A source reader interface carries every read of SOLA.** `source_reader` is an interface with one implementation (`local_aica_reader`) that queries SOLA's tables and calls SOLA classes only behind `class_exists()` guards. The mapper and the runner are tested against a test double that returns arrays, so CI (which has no SOLA tables) tests everything except the thin reader, and a second test group (skipped when SOLA's tables are absent) tests the reader against real rows.
7. **The SOLA-side interlock skips recordings, it does not skip the whole cleanup.** Plan 7.1 item 3 has `soapbox_cleanup::execute()` return early once a migration has begun. That would leave every un-migrated course's media undeleted past the 7-day promise the learner was shown. Instead (section 5) the cleanup skips each recording that a PresenterAI map row has taken over, so un-migrated courses keep being pruned on schedule and migrated ones are pruned only by PresenterAI. The signal is PresenterAI's own map table, so the migrator still writes nothing to SOLA (D13). Cost if wrong: none; the early return remains available as a stricter variant.
8. **The importer refuses to run until it can see the interlock.** Precondition: SOLA exposes `soapbox_cleanup::PRESENTERAI_INTERLOCK` (a constant added in SOLA 7.13.0). Without it the importer stops with a message naming the SOLA release to install. Cost if wrong: an operator on an older SOLA is blocked until they upgrade, which is the point.
9. **Ship order.** SOLA 7.13.0 (interlock) first, to dev and alpha; then PresenterAI 0.5.0 (importer). Both are GitHub releases deployed to dev and alpha. No Marketplace upload, no production.

## 4. PresenterAI 0.5.0

### 4.1 Files

Created under `classes/local/migration/`: `source_reader.php` (interface), `local_aica_reader.php`, `mapper.php` (pure: assignment, topic, recording and score mapping, the rubric_backfill chain, status mapping, attempt numbering), `runner.php` (per-course run: preconditions, locks, one transaction per source assignment, create_module, stand-in rewrite, map state machine, resume, refusals), `verifier.php` (tiers 1 to 3 and the `presenterai_migreport` row), `rollback.php`, `plan.php` (a value object the dry run prints). `cli/migrate_from_local.php` is the command. `docs/MIGRATION-RUNBOOK.md` is the operator guide. Lang strings for every message (English). `classes/privacy/provider.php` declares `presenterai_migration_map` and `presenterai_migreport` (the report has `operatorid`) and the tests prove neither holds learner text.

### 4.2 The command

`php mod/presenterai/cli/migrate_from_local.php` with: `--course=<id>|all`, `--apply` (without it: dry run), `--rollback`, `--verify`, `--report`, `--json`. Refuses `--backend=fs` (D10). Runs as the admin user (`\core\session\manager::set_user(get_admin())`). Exit codes: 0 done or clean dry run, 1 error or refusal, 2 a precondition is unmet.

### 4.3 What it does

Exactly plan sections 7.1 to 7.6, with these implementation rules:

- **Preconditions** (refused, with a message that names the fix): PresenterAI installed and SOLA present with the three `sbx_*` tables; the backend is S3 and PresenterAI's bucket, region and prefix equal SOLA's `soapbox_storage_*` settings exactly; the SOLA interlock constant exists; no `score_recording` adhoc task is queued or running for a row being migrated.
- **Selection of scores** only through `sbx_rec.scoreid` (the SQL in plan 7.2). Orphan scores are counted and left. Two recordings sharing a `scoreid` refuse the course.
- **Mapping** per plan 7.2 and 7.4. `status` becomes `scored` when a score row exists and `abandoned` when none does; media keys that SOLA nulled stay null with `mediadeletedat` from `expires_at`; `attemptnumber` is assigned per (instance, user) by `timecreated` among counted statuses; criteria get `counts = true`; `visual_observation` is dropped; a criterion with no `assessed` flag counts as assessed.
- **Rows are inserted directly**, never through `save_ai_score()`/`save_teacher_score()`: no event, no gradebook, no message. The tests assert no `\mod_presenterai\event\*` and no message is produced and `grade_items` is untouched.
- **One delegated transaction per source assignment**, never around the whole run. A per-course `lock_config` lock.
- **The stand-in** (`mod_url` pointing at a Soapbox assignment) is found by the regex in SOLA's `soapbox_course_link`, keeping the full list so two URLs for one assignment are refused, not collapsed. Its original `externalurl` and visibility are written to the map `payload` before it is rewritten. It is hidden with `visibleoncoursepage`, never `visible = 0`, and only when `$CFG->allowstealth` and the course format allow it; otherwise it stays visible and the report says so (D15).
- **Re-runs and rollback** per plan 7.5, including: a source assignment whose `timemodified` is later than its map row is refused, a vanished `targetcmid` moves the entry to `failed`, and rollback refuses an instance whose `grade != 0`.
- **Verification** per plan 7.6: tier 1 counts all exact, including `deleted` source rows; tier 2 content checks per score row; tier 3 an S3 HEAD of the key on the destination row for a sample (all of them under a few thousand), with the S3 client behind an interface so tests use a double. The `presenterai_migreport` row is the record. Tier 4 (a human opens three learners' views) stays a runbook step.
- **No SOLA write, ever.** The reader opens SOLA tables read-only through SELECTs. A test fails the build if any SQL the migrator issues is an INSERT, UPDATE or DELETE against a table whose name starts with `local_ai_course_assistant_` (use a recording `$DB` wrapper or a source scan, whichever the first task finds workable).

## 5. SOLA 7.13.0: the interlock

`classes/task/soapbox_cleanup.php` gains `public const PRESENTERAI_INTERLOCK = true;` and, before it prunes a recording, skips any recording that PresenterAI's migration map holds: `presenterai_migration_map` exists (checked with `$DB->get_manager()->table_exists()`), and a row with `sourcetable = 'local_ai_course_assistant_sbx_rec'` and `sourceid = <rec id>` exists for this site's `sourcesiteid` (the exact `sourcetable` string and `sourcesiteid` formula are those the migrator writes; the SOLA task and the migrator share the formula through a SOLA-side helper the migrator calls when SOLA is present, or by documented contract; the first task decides and records which). A skipped recording writes one audit row per run (count only). Tests: a mapped recording's media is not deleted and its row not changed; an unmapped one is deleted as before; with no PresenterAI tables the task behaves exactly as today. Version `7.13.0`, no schema change, no upgrade step, release notes, wiki entry. The SOLA Soapbox deprecation notices and the wiki page `Migration-to-PresenterAI` are updated to say the importer exists.

## 6. Tests

- Mapper: pure unit tests over arrays: every status, the three provenance outcomes, absent `assessed`, absent `max_score`, `overallpct` computed (not copied), `legacymeanscore` and `legacymeta` minus `visual_observation`, attempt numbering across users and statuses, a recording with no score, an orphan.
- Runner and rollback: PHPUnit with the source test double and real PresenterAI tables: dry run writes nothing; apply creates the instance, topics, recordings, scores and map rows; apply twice is a no-op; resume after a forced failure between topics and recordings; refusal paths (precondition unmet, shared `scoreid`, stale source, missing stand-in, duplicate stand-ins); stand-in rewrite and restore; stealth unavailable leaves it visible; rollback removes only created instances; rollback refuses `grade != 0`; no event, message or gradebook write; section fallback is the last section and never creates a section.
- Verifier: counts, content hash equality, a doctored destination row fails tier 2, S3 double.
- Reader (group skipped when SOLA's tables are absent): real `sbx_*` and `practice_scores` rows, including a row whose score is in `session_meta` only and a `deleted` row.
- No-SOLA-write guard and privacy declarations (the two tables hold no user text).
- Prove at least the central guards can fail with the repo's mutation tooling.

## 7. Release

PresenterAI `0.5.0` (beta), version `2026101901`, GitHub release with `mod_presenterai.zip` (root folder `presenterai/`), release notes in the repo's shape ("Added / Upgrading / Not in this release / Plugin version"), README updated (the migrator is no longer "planned next"), `docs/MIGRATION-RUNBOOK.md`.

## 8. Proof on dev

On dev.sylr.org, after SOLA 7.13.0 and PresenterAI 0.5.0 are installed: use dev's own Soapbox rows as the first real case (5 expired recordings, 2 real scores, 27 seeded ones) and, for the media path, create synthetic Soapbox data in one disposable course (assignments with and without a course page stand-in, topics, attempts with surviving media objects, expired ones, a failed one, scores in the old shape without `max_score` and in the new shape), point PresenterAI's S3 settings at SOLA's bucket, region and prefix, and run: dry run, apply, verify (tiers 1 to 3), open three learners' views, rollback, apply again, verify again. Record every number in the release notes. Clean up the synthetic course and the objects afterwards. Nothing runs on any other site. The S3 bucket and prefix used for the proof are SOLA's existing dev ones; the proof writes no object and deletes none (it only re-points keys), except the synthetic objects it creates itself and removes.

## 9. Risks

- **The shared key contract.** The migrator and SOLA's interlock must agree on the map row's `sourcetable` and `sourcesiteid`; a mismatch means the interlock never fires. A test in each repo pins the same literal values, and the first task states them in one place.
- **S3 settings equality** is strict and will refuse on sites that configured different prefixes; the message names both values (not keys) and the runbook says why it cannot be relaxed (D10).
- **Real S3 is not exercised in CI.** The HEAD check uses an interface; the dev proof is the only real-bucket run.
- **Moodle 5.3 (alpha)** is not in PresenterAI's CI matrix; the dev proof runs on dev (4.5) and alpha (5.3) both where possible.
- **A dry run cannot prove the real run** (events fire, gradelib is touched); the runbook says so.
