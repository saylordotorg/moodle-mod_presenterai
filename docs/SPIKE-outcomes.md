# Spike: can PresenterAI grade core outcomes from rubric criteria?

Answers the question `IMPLEMENTATION-PLAN.md` section 6 left open: "Treat outcomes as a phase 2 spike with a written answer before any code." Checked against Moodle 4.5.10 source, 6 October 2026.

## The short answer

Partly. Core outcomes work for PresenterAI the same way they work for `mod_assign`, and that part ships in phase 2. The prior plan's version, where each rubric criterion carries an `objectiveid` and the AI score grades that outcome automatically with a `>= 0.5` mastery threshold, doesn't map onto core without a plugin-side link core doesn't have, so it's deferred and not designed here.

## What core actually provides

- **An outcome is attached to an activity, not to anything inside it.** When outcomes are enabled (`$CFG->enableoutcomes`, off by default, `admin/settings/subsystems.php:5`) and the activity supports `FEATURE_GRADE_OUTCOMES`, the activity settings form lists the course's outcomes. Ticking one makes core create a separate grade item for it with `itemnumber` from 1000 upward (`course/modlib.php:308-345`). The plugin writes no code for that part.
- **An outcome's value is a scale index, not a fraction.** Every outcome has a `scaleid` (`lib/grade/grade_outcome.php:51`). `grade_update_outcomes()` takes `[itemnumber => value]` and stores any value below 1 as null (`lib/gradelib.php:350-363`), so the only valid values are the scale's item numbers, 1 to N.
- **Activities set outcome values from their grading screen.** `mod_assign` reads one select per outcome on its grading form and calls `grade_update_outcomes()` with the changed values (`mod/assign/locallib.php:7350-7373`). It never derives them.

## What that means for the prior plan's design

1. There's no criterion-to-outcome link in core. To grade an outcome from a criterion, PresenterAI would need its own mapping (a criterion field holding the outcome's grade item number, chosen from the outcomes attached to that activity) and would have to keep it valid when a teacher detaches an outcome or restores the course elsewhere, where outcome ids change.
2. "Normalised partial credit" can't be stored as such. It has to become a scale index. The main grade's scale rule, `index = clamp(ceil(pct * count), 1, count)`, would give the `>= 0.5` threshold exactly on a two-item scale (Not met / Met) and a different cut on longer scales. That's a pedagogical choice, not a translation, and it would be the AI deciding mastery with no teacher in the loop at Saylor.
3. `enableoutcomes` is off on a stock site, and whether either Saylor site has it on wasn't checked.

## What phase 2 ships

- Keep `FEATURE_GRADE_OUTCOMES` true, so a teacher can attach course outcomes to a PresenterAI activity through the standard form. Core creates and manages the items.
- `grade.php` shows one select per attached outcome, prefilled from the gradebook, and saves changes through `grade_update_outcomes()`, exactly as `mod_assign` does. Teachers set outcome values by hand.
- Backup, restore and course reset need no outcome-specific code: they're core grade items.

## Deferred, with what it would take

Automatic, AI-derived outcome grading from rubric criteria. It needs a decision on the scale mapping (point 2 above), a per-criterion mapping field with restore remapping, and an answer to whether an AI should set mastery outcomes at all where nobody reviews them. None of that blocks phase 2.
