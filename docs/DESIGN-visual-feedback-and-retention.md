# Visual feedback and retention: the working design

**Status:** working design for decisions D21 and D22 in [DECISIONS.md](DECISIONS.md), written 20 September 2026 against SOLA `local_ai_course_assistant` v7.5.2 and the Moodle 4.5.10+ checkout at `/Users/tom.caswell/Sites/moodle`.
**Covers:** plan sections 9.1 and 9.2, both of which were blocking phase 3.
**Reading rule, same as the plan:** everything asserted about existing code carries a `file:line` reference and was read, not remembered. Where a fact could not be established it says so.

Section 12 lists what an adversarial pass found, what changed in response, and what was accepted as a known limitation. It is the section to read if you only read one.

---

## 1. What this document settles, and what it does not

**Settled here.** How the learner facing body language summary is produced, checked, stored, exported and deleted. How retention and download are configured, what happens to existing recordings when an admin changes a setting, and exactly what a learner is told in every combination.

**Not settled here, and deliberately so.** Whether the two visual criteria contribute to the gradebook at all (open question 9.17), whether a learner can opt out per attempt (9.18), who reads the staff surfaces at Saylor (9.19), whether there is a per activity download setting (9.20), whether a learner can delete their own recording (9.21), and whether PresenterAI ships 45 non English locales (9.22). Those six are in DECISIONS.md Part 4. They arose from doing this design and none of them reopens D21 or D22.

**The dependency worth stating up front.** Almost all of section 3 and section 4 is worth building under any answer to 9.17, because a summary that carries no mark still has to be safe, honest and in the learner's language. What changes with 9.17 is how much is riding on it.

---

## 2. Corrections to the inputs, established by reading the code

**2.1 A learner already reads unreviewed model prose derived from the raw note.** This is C6 in DECISIONS.md and it is the single most consequential finding here. The raw note goes into the scoring prompt at `classes/external/score_speech.php:209-215`, the model writes per criterion `feedback` for the two visual criteria out of it, that string is read back at `soapbox_present.php:289` and rendered at `templates/soapbox_present.mustache:183`. So PresenterAI is not building a learner facing channel that does not exist. It is naming the one that does, adding the thing it lacks, which is a statement of what the camera saw rather than coaching advice, and putting one gate across both.

**2.2 The store line is `classes/external/score_speech.php:369`.** `:367` writes `$meta['visual_assessed']`, `:368` is the `if ($hasvisual)` guard, `:369` writes `$meta['visual_observation'] = $visual['note'];`. The 900 character clamp is applied earlier, at `classes/soapbox_gesture_vision.php:191`, using `MAX_NOTE_CHARS` (`:82`).

**2.3 There is no `json_parser` class in SOLA.** C7 in DECISIONS.md. Zero grep hits across the plugin. Scoring parses inline at `classes/external/score_speech.php:285`, with a regex brace extraction fallback at `:287-289` and a give up at `:291-293`. The parse is real and cheap; the class is something PresenterAI is planning to write (`IMPLEMENTATION-PLAN.md:760`).

**2.4 SOLA has no download setting, no download capability and no learner delete.** The download link at `soapbox_present.php:333-343` is offered to anyone who reaches the attempt list, which is gated only by `local/ai_course_assistant:use` at `:48`. The only `soapbox_*` external functions are `soapbox_finalize_recording.php`, `soapbox_get_playback.php`, `soapbox_get_upload_url.php` and `soapbox_render_deck.php`, so there is no delete endpoint to port.

**2.5 The retention prose and the retention date already disagree in SOLA.** The per attempt date comes from the row and the comment at `soapbox_present.php:251-254` explains why. The four paragraphs around it come from the live site setting: `:128-131` (`retentionnote`), `:155-159` (`privacynote`), `:162-166` (`retentionheading`), `:167-170` (`retentionline`). The moment an admin changes the window, the prose is wrong and the cell is right.

**2.6 `clamp_retention_days()` makes zero unreachable.** `classes/soapbox_config.php:108-116` returns 1 below 1 and 28 above 28, so "keep forever" cannot be expressed today. `expires_at` is written once at `classes/external/soapbox_finalize_recording.php:200` with no zero branch, and nothing rewrites it afterwards.

**2.7 The frames are six moments, not continuous video.** `amd/src/soapbox_frames.js:47-56` sets `FRAME_COUNT = 6`, `COLS = 3`, `CELL_W = 426`, `CELL_H = 240`, `QUALITY = 0.72`, and `sampleTimes` at `:73-88` spreads them across the middle 80 percent. On a 12 minute attempt (`classes/soapbox_config.php:32`, `DEFAULT_MAX_SECONDS = 720`) that is roughly one still every 96 seconds. Any learner facing number must match these, per C5 in DECISIONS.md.

**2.8 Core AI on 4.5 takes three arguments and stores the prompt forever.** `core_ai\aiactions\generate_text::__construct()` takes `contextid`, `userid` and `prompttext` and nothing else (`~/Sites/moodle/ai/classes/aiactions/generate_text.php:39-47`). There is no response format, no JSON schema, no strict mode. `core_ai\manager::process_action()` calls `store_action_result()` unconditionally on success and on failure (`~/Sites/moodle/ai/classes/manager.php:130,160-172`), `generate_text::store()` writes `$record->prompt = $this->prompttext` and inserts it (`:50-64`) into `ai_action_generate_text` (`~/Sites/moodle/lib/db/install.xml:4914`), and **there is no `ai/db/` directory at all in the 4.5 tree**, so core ships no scheduled task that ever prunes that table. Both consequences are handled in section 3.6.

---

## 3. The summary pipeline

### 3.1 Where the rewrite happens

The summary is one extra field in the existing scoring call's structured response, with a deterministic template as the guaranteed fallback. There is no second model call for the rewrite. DECISIONS.md D21 records what was rejected and why.

Cost: about 130 extra input tokens for the instruction block and up to 90 output tokens, against a prompt that already carries up to 40,000 characters of transcript (`classes/external/score_speech.php:108-110`) and 8,000 of slide text (`:194`). The comment at `:265-268` already calls this the most expensive ancillary call. The addition is under two percent of it, and there are no extra round trips.

The deciding reason is not cost. It is that the gate's structural anchor (layer 3) needs the summary tied to the rubric criteria, and the scoring call already holds the allowlist of criterion names actually written into the prompt (`:154-164`) and already writes per criterion feedback. A standalone rewriter would have to be handed the criteria separately and would produce a second, unverified copy of them.

### 3.2 The pipeline

```
browser sampler (6 frames, one contact sheet)
  -> server side luminance check on the sheet          [deterministic, no model]
  -> vision pass, JSON: {note, confidence, unusable_frames}          [prompt 1]
  -> evidence gate: drop the visual criteria when the evidence is weak
  -> scoring call, JSON: {criteria[], overall, tips, visual_summary} [prompt 2]
  -> summary_gate over visual_summary AND the two visual criterion feedback strings
       layers 0-3 deterministic, layer 4 a small model                [prompt 3]
  -> pass: store and render
     fail: deterministic template, store, render, fire an event, log the rejected text
```

Scoring runs in an adhoc task and never in a web request (DECISIONS.md D6), so every latency figure here is cron wall time, not a learner waiting.

### 3.3 Prompt 1: the vision pass

Replaces the prose prompt at `classes/soapbox_gesture_vision.php:210-227`. JSON rather than prose, per D18.

```
You are shown a single image containing still frames sampled at even intervals
across one learner's recorded presentation, in order from the start of the talk
to the end. There are six of them and they are six separate moments, not
continuous video.

Report ONLY what is visible. Do not score, rate or evaluate anything. Do not
guess at what is not visible. Reporting that you cannot tell is more useful than
a confident guess.

In "note", write four to six sentences of plain prose covering:
- what the hands and arms are doing, and whether that changes across the frames;
- posture and stance, and any repeated movement such as rocking, pacing or
  fidgeting;
- where the eyes appear to be directed, for example toward the camera, downward
  at notes, or off to one side;
- how the speaker is framed: how much of them is in shot, whether the hands are
  in view, and whether the face is lit well enough to read.

Say how many of the six frames each observation is based on. Do not write
"throughout", "for most of the talk" or "consistently": you have six moments and
you cannot see what happened between them.

Never write about the speaker's appearance, face or body except as it bears on
the four points above. Never write about ethnicity, race, age, gender, build,
health, disability, hair, clothing, jewellery or religious dress. Never write
about the room, the background, or anything in it. Never read or transcribe text
in the image. If another person is visible, ignore them completely and report
only on the speaker.

In "unusable_frames", give how many of the frames are too dark, too blurred, too
distant, or cropped so that the hands or the face cannot be seen.

In "confidence", answer "high" when at least four frames are readable and the
hands and face are in shot, "medium" when the frames are readable but part of
the speaker is out of shot, and "low" when three or more frames are unusable.
```

Schema: `{"note": string, "unusable_frames": integer, "confidence": "high"|"medium"|"low"}`, all three required, `additionalProperties: false`.

Server side after the call: clamp `note` to `MAX_NOTE_CHARS` exactly as `classes/soapbox_gesture_vision.php:191` does today, then apply the layer 0 gate.

Two clauses in that prompt are changes from the shipped one and both came from the adversarial pass. The shared frame instruction in the first draft said "say only that the frame is shared", which manufactures a fact the scoring model will then use, producing a comment on a learner's housing that passes every deterministic layer. It is gone. The "six moments" and no-`throughout` clauses exist because six stills across twelve minutes do not support "for most of the recording", and the first draft's own worked example said exactly that.

### 3.4 Prompt 2: the summary block in the scoring prompt

Appended immediately after the existing VISUAL EVIDENCE block built at `classes/external/score_speech.php:210-215`, included only when `$hasvisual` is true, on exactly the same condition, so the field is never requested when there is nothing to summarise.

```
VISUAL SUMMARY. Also produce "visual_summary": two or three sentences, at most
60 words, written to the learner in the second person, saying what the visual
evidence above shows about their presentation. This is the only part of the
visual evidence the learner will ever read, so write it as the plain-language
version of that evidence, not as a repeat of your criterion comments.

Write only about things the learner can do differently in their next recording:
what their hands and arms did, their posture and stance, any repeated movement,
where they were looking, how much of them was in shot, and whether the light let
their face be read. Tie each observation to its effect on the presentation. For
example: "your hands stayed below the desk in every frame, so your gestures were
hard to see".

The evidence comes from six still frames, not from continuous video, so do not
write "throughout", "for most of the talk" or "consistently".

Never write about the learner's appearance, face, body, build, hair, clothing,
jewellery, religious dress, age, gender, ethnicity, race, accent, health or
disability. Never write about the room, the background, or anything in it. Never
describe anyone else who is visible. Never state or guess how the learner was
feeling, or what kind of person they are. Never quote the visual evidence text
directly. Never give a number or a score.

Write it in {LANGUAGE}.

If the visual evidence does not support two sentences of this kind, write one
and stop. If it supports none, return an empty string.
```

`{LANGUAGE}` is the English name of the learner's Moodle language, resolved from the loaded user record as `$user->lang ?: $CFG->lang`. **It is not `current_language()`.** That function checks `$SESSION->forcelang`, `$PAGE->cm->lang`, `$PAGE->course->lang` and `$SESSION->lang` before it reaches `$USER->lang`, and SOLA's scorer sets the user with `\core\session\manager::set_user($user)` (`classes/soapbox_scorer.php:177`), which does not reinitialise `$SESSION`, rather than `\core\cron::setup_user()`, which does (`~/Sites/moodle/lib/classes/cron.php:670-678`). In a cron run scoring a queue of attempts, a stale session language would outrank the learner's own, deterministically, for the rest of that run, and learner B would get a summary in learner A's language.

Schema change on the existing object at `classes/external/score_speech.php:234-262`: add `'visual_summary' => ['type' => 'string']` to `properties` **and** add `'visual_summary'` to `required`, because OpenAI strict mode rejects a property present in `properties` and absent from `required`. The comment at `:245-248` records that the hard way. The empty string is how the model declines under that constraint.

### 3.5 Prompt 3: the judge, batched

One call per scored video attempt, carrying the `visual_summary` plus the two visual criterion feedback strings as a numbered list. **The judge is never given the learner's name, the transcript, the raw note or the scores.** It does not need them and handing them over makes the judge one more place a learner's name travels.

```
You are checking short pieces of feedback before they are shown to the learner
they are about. Each one should say what a speaker did during a recorded
presentation. None of them may describe the speaker.

Reject an item if any part of it:
- names or implies appearance, face, body, build, hair, clothing, jewellery,
  religious dress, age, gender, ethnicity, race, accent, health or disability;
- names or implies a mobility aid, assistive device or medical equipment;
- describes the room, the background, or anything in it;
- describes any person other than the learner;
- states or guesses the learner's feelings, confidence, personality or
  character;
- says what kind of person the learner is, or compares them with other people;
- gives advice the learner could not act on by changing what they do in their
  next recording.

Accept an item that says what the hands, arms, posture, stance, gaze, framing or
lighting did during the recording and what effect that had, even where the
effect is a negative one. Negative is not a reason to reject. Personal is.

Answer with JSON only, one verdict per numbered item, in order:
{"verdicts":[{"n":1,"pass":true,"rule":""},{"n":2,"pass":false,"rule":"<the one bullet it broke>"}]}
```

User message: the candidate strings, numbered, and nothing else.

Schema: `{"verdicts": [{"n": integer, "pass": boolean, "rule": string}]}`, all three required on each verdict, the empty string used on a pass.

Cost: roughly 500 input and 40 output tokens on a small model, comfortably under a tenth of a cent per attempt, plus one round trip inside a cron task.

### 3.6 The core AI route does not carry any of this

Two independent reasons, both from section 2.8, and they point the same way.

`generate_text` has no schema, so there is no way to compel `visual_summary` to be present. The response is prose and the parse falls through to the brace extraction regex at `classes/external/score_speech.php:287-289`. On that backend the field would be absent most of the time and the learner would get the deterministic template every time, while the settings page claimed a summary feature.

Worse, `store_action_result()` writes the full prompt into `ai_action_generate_text` on every call, success or failure, and nothing in the 4.5 tree ever deletes those rows. The PresenterAI scoring prompt would contain the VISUAL EVIDENCE block, which is the raw model prose about a learner's body, plus up to 40,000 characters of transcript and the learner's name. A verbatim copy of the prose would land in a core table PresenterAI does not own, is not declared in PresenterAI's `get_metadata()`, is not touched by PresenterAI's deletion path, and that nothing ever deletes. The whole point of `visualdatadays` (D5) is that this prose cannot outlive the video, and on that route it would outlive everything.

**The rule: when the resolved scoring route is core AI, the raw note is never put in the prompt.** `$hasvisual` is forced false, the visual criteria are absent from the rubric entirely by the existing mechanism at `classes/rubric_manager.php:255-257`, no `visual_summary` is requested, and the learner gets the "could not be analysed" string from section 6 case (a2). The settings page says this at the point of choosing the route, the same way D6 already requires for transcription.

The alternative considered and rejected was a PresenterAI scheduled task that deletes the plugin's own rows out of `ai_action_generate_text` on the `visualdatadays` clock. That is a plugin reaching into a core table it does not own, to clean up after a core feature, and it would still leave a window between the write and the sweep.

---

## 4. The enforcement gate

The class is `mod_presenterai\local\vision\summary_gate`, with `check_batch(array $texts, array $context): array` so the whole attempt is gated in one pass. Five layers. Layers 0 to 3 are deterministic and free. Layer 4 is a model and costs money.

The editorial line the whole gate serves is one test, applied sentence by sentence:

> **Could the learner do this differently in their next recording, without changing who they are or what they own?**

If no, it is out. That test separates a body's **actions** from a body's **properties** without anyone having to enumerate every protected category correctly, which is the thing a word list cannot do.

### 4.1 What may and may not be said

**In.** What the hands and arms did. Posture, stance, weight shift. Repeated movement. Where the eyes were directed. How much of the speaker was in shot. Whether the hands were in frame. Whether the light let the face be read. Each tied to its effect on the presentation, in the second person, past tense, bounded to this recording.

**Out.** Appearance, face, body, build, height, weight, hair. Clothing, jewellery, religious dress, glasses. Age, gender, ethnicity, race, accent, health, disability. Mobility aids and assistive equipment. The room, the background, anything in it. Any other person in frame. Inferred feelings, confidence, personality, character. Comparisons with other learners. Any score or number.

The boundary cases, worked, because this is where a rule earns its keep. These are the CI fixture set.

| Candidate | Verdict | Why |
|---|---|---|
| "your hands stayed below the desk in every frame, so gestures were hard to see" | In | Action, bounded, consequence tied to a criterion, fixable by moving the camera or sitting back |
| "you looked tense" | Out | Inferred internal state, not an observable, not actionable |
| "your posture was slouched" | Out as written | "Slouched" is a judgement about a body. The permissible version is the observable: "your shoulders came forward in the later frames" |
| "the light behind you put your face in shadow" | In | Lighting is named in the shipped Eye Contact & Camera Presence criterion, "a face lit well enough to read" (`classes/rubric_manager.php:95-96`), and it is fixable |
| "you were wearing a dark shirt against a dark wall" | Out | Names clothing and setting. The permissible version drops the cause: "you were hard to make out in every frame, so your gestures did not register" |
| "the room behind you was cluttered" | Out | Setting. In a self paced learner's home this is a comment on how they live, and no rubric criterion covers it |
| "your beard caught the light" | Out | Appearance |
| "your eyes went down and to the left in four of the six frames, which reads as checking notes" | In | Gaze direction, a pattern, an effect, fixable |
| "someone walked behind you in one frame" | Out | Another person, and the learner cannot always control it |
| "you were seated with the chair back visible behind your shoulders, which narrowed the frame" | Out | Assistive equipment, and the shape the first draft of this gate let through cleanly |
| "a cap pulled low shaded your eyes, so your gaze was hard to read" | Out | Headwear. Simultaneously a real framing observation and a comment on religious dress, which is exactly why a word list cannot be the primary control |

Form constraints, enforced rather than requested: second person, two or three sentences, at most 60 words, no number, no score, no direct quotation of the raw note.

### 4.2 Layer 0: the summary exists only when the evidence does

Already true in SOLA and carried: the visual criteria are appended only when there is evidence (`classes/external/score_speech.php:144`), and the VISUAL EVIDENCE block exists only then (`:209-215`). PresenterAI tightens the gate itself, per D18, because today the only test is `trim($note) !== ''` (`:139`).

The new test has two halves, and the split matters.

**The objective half, which the model cannot author.** Before the vision call, compute per cell mean luminance and variance on the contact sheet server side. Drop the visual criteria when more than two of the six cells fall below the threshold. The sheet's geometry is fixed and known (`amd/src/soapbox_frames.js:47-52`), so the cell boundaries are arithmetic, not detection.

**The model's self report.** Evidence is usable when `note` is non empty **and** `confidence !== 'low'` **and** `unusable_frames < 3`.

The second half is worth having and it is not evidence. Both fields come from the same model that wrote the note, so a model confident enough to describe posture from six dark thumbnails will report `high` and `0`, and the gate fires only in the case that did not need a gate. D18's justification for JSON is that those two fields "drove the rule", and they cannot drive a rule they are not independent of. The luminance check is the only part of layer 0 that is evidence, and it is a few lines.

*Unverified:* whether GD is a hard Moodle requirement or can be absent on a given site. The luminance check must degrade to a skip with a debugging note rather than a fatal, and the self test must report when it is unavailable.

### 4.3 Layer 1: schema, length, and the copy detector

- `visual_summary` is a required string in the strict JSON schema (section 3.4), and the empty string is the permitted way for the model to decline. **This layer does not exist on the core AI route**, which is one of the two reasons section 3.6 keeps that route out of this pipeline entirely.
- Over 500 characters of `core_text::strlen`: reject. Do not truncate. A summary cut mid clause can invert its meaning, and the fallback always works.
- **The copy detector.** Lowercase and word split both the raw note and the summary, build every 8 word shingle from the note, reject if any appears in the summary. Eight rather than twelve, because a 60 word summary sharing a twelve word run with a 900 character note has effectively pasted it.

The copy detector catches the failure that matters most, which is the model dumping the raw note straight into the learner facing field. It is also **the only layer that works in every language**, because it compares two strings without understanding either. That is the argument for its strictness and it is why it stays whatever else changes.

### 4.4 Layer 2: deny list, tiered

Word boundary, case insensitive, `core_text` aware match over five categories.

**Hard terms, reject outright.**

- Appearance and body: beard, moustache, hairstyle, hair colour words, tattoo, piercing, makeup, skin, complexion, build words, weight words, height words, attractive, handsome, pretty, scruffy, tired-looking.
- Identity: race, ethnicity and nationality terms, young, elderly, middle-aged, accent, native speaker, religion terms.
- Clothing, dress and headwear: shirt, t-shirt, hoodie, blouse, tie, jacket, jewellery, watch, glasses, hijab, headscarf, scarf, turban, kippah, yarmulke, sheitel, cap, hat, hood, veil, niqab, dupatta, wrap, bonnet, patka, taqiyah, gele.
- Health and disability terms.
- **Assistive equipment and medical devices:** wheelchair, chair back, headrest, armrest, cane, crutch, walker, prosthesis, prosthetic, brace, sling, splint, oxygen, tube, hearing aid, cochlear, interpreter, signer, service dog, tremor.

**Soft terms, reject only when the sentence carries no rubric anchor.** Setting words: bedroom, kitchen, bed, poster, wall, curtain, plant, pet, child, roommate, messy, cluttered, untidy.

Three notes on the list that are not tuning.

**The headwear group and the assistive equipment group are additions, and both are in the direction that matters.** The first draft listed `hijab, headscarf, turban, kippah` and nothing else, and word boundary matching means `headscarf` does not catch `scarf`. It listed no mobility aid at all. Both gaps produce text that is simultaneously a protected characteristic comment and a genuine framing observation, which is the overlap that makes a word list unable to be the primary control.

**`frame` is a layer 3 observable and cannot also be a deny term.** The collision is real and it is resolved in favour of the observable, because "framing" is how half the legitimate feedback is phrased. `chair back`, `headrest` and `armrest` carry the assistive case instead.

**False positives are the real cost.** "Glasses" appears in "you reached for your glass of water", and word boundaries do not save you there because the possessive and plural forms collide in practice. "Wall" appears in "you drifted toward the wall as you spoke", which is a good observation about movement, and the tiering is what makes the setting words survivable. Expect to tune against real output over the first fifty runs, which requires section 5.3.

**The list is data, not lang strings.** It lives at `classes/local/vision/denylist/{lang}.php` returning `['hard' => [...], 'soft' => [...], 'observables' => [...]]`, looked up by **generation** language with no fallback to English. English ships at v1. When no list exists for the generation language, layers 2 and 3 are skipped and layer 4 carries the whole gate.

### 4.5 Layer 3: structural anchor to the rubric

Accept only if the text contains at least one of:

- a criterion name from the allowlist already built at `classes/external/score_speech.php:154-164`, normalised through the existing `normalise_name()` (`:513`), or
- an observable from a small list: hands, arms, gestures, posture, stance, movement, eyes, gaze, looking, camera, lens, frame, framing, shot, light, lighting, shoulders, head, face.

It costs a small number of good summaries that use none of these words. Set the list generously and accept that a summary with no observable in it is usually not a summary of anything observable. It catches drift into general character comment, because a sentence about a person rather than about a presentation reliably carries none of these nouns. Same file and same language rule as layer 2.

### 4.6 Layer 4: a second model as judge

On by default everywhere, as the setting `visualsummaryjudge`.

The first draft made the default language dependent: off where a deny list exists, forced on where one does not. That is exactly backwards for the site that will run it. Layers 1 to 3 are blind to the euphemism, and the sentence "you do not come across as someone used to doing this" contains no listed term, carries layer 3 nouns as soon as it mentions hands or gaze, breaks the rule completely, and passes. A frontier model writes its most fluent and most subtly personal prose in English. The euphemism failure is an English first failure, and a default derived from list coverage would turn off the only layer that catches it precisely where it bites. Coverage of literal terms is not coverage of the failure mode.

The cost argument for turning it off does not survive the numbers: 500 input and 40 output tokens on a small model, against a scoring call section 3.1 measures at roughly 12,000 tokens.

### 4.7 What happens on a rejection, and what happens on an outage

These are two different events and the first draft collapsed them into one handler.

**A rejection is a verdict.** The text is replaced by the deterministic fallback (section 6), the plugin fires `\mod_presenterai\event\visual_summary_rejected` carrying the recording id and the rule that fired, the rejected text is written to the staff only log in section 5.3, and **it is never retried**. A model that has just written a banned sentence is not reliably better on attempt two.

**An outage is not a verdict.** If the judge provider is unreachable, rate limited or times out, the gate **throws**, so the adhoc task requeues under the plan's existing transient rule (`IMPLEMENTATION-PLAN.md:760`, `get_fail_delay() < 960`). It fires `\mod_presenterai\event\visual_summary_judge_unavailable`, which is a separate event so that a count of rejections is not polluted by a count of timeouts. Scoring already runs in an adhoc task (D6), which is the one place in Moodle where deferring is free.

The first draft said an unreachable judge fails closed to the template, on the reasoning that a gate that fails open is not a gate. That is right about the verdict and wrong about the outage: forty minutes of provider trouble would have permanently degraded every attempt scored in that window, silently, in rows that are written once and never rescored. Failing closed within one attempt is correct; failing closed for the whole run is a bug wearing a principle's clothes.

**A rejection on a visual criterion's feedback sets that criterion to `assessed = false`.** It then leaves both sums in `compute_overall()` (`classes/rubric_manager.php:355-366`), the not assessed badge renders (`templates/soapbox_present.mustache:182`), and the learner is not marked on words nobody was willing to show them. The first draft kept the score and replaced only the words, on the reasoning that the score came from evidence the gate has no opinion about. That is the worst of the available options: the gate firing is the system saying the model wrote something about this person that will not be shown, and at that moment the reasonable inference is that the model reasoned about the person rather than the presentation, which is the same reasoning that produced the score.

### 4.8 What the gate catches and what it does not

Stated plainly, because a gate whose limits are not written down gets trusted for things it cannot do.

| Failure | Caught by | Honestly |
|---|---|---|
| The raw note pasted into the learner facing field | Layer 1 copy detector | Reliably, in every language, for free |
| A summary generated when there was nothing to see | Layer 0 luminance | Reliably on darkness. Not on blur, distance or crop, where only the model's self report exists and it is not independent |
| An outright protected category term | Layer 2 | Reliably in English. In no other language at v1 |
| Assistive equipment named directly | Layer 2 | Only because it was added after a walk through found it missing. The next category nobody has thought of is not covered |
| Drift into character comment | Layer 3 | Usually, because such sentences carry no observable noun |
| The euphemism, "you do not come across as someone used to doing this" | Layer 4 only | This is the whole reason layer 4 is on by default |
| A criterion feedback string that leaks | Layers 0 to 4, same gate | This is new. SOLA has never checked this channel at all (section 2.1) |
| **A correct, well phrased, gate passing sentence attached to a low mark on a body a learner cannot change** | **Nothing here** | **See section 12.1. This gate is about words and that is a problem about marks** |

---

## 5. What is stored, exported and deleted

### 5.1 Three things, three clocks

| Thing | Where | Who reads it | Clock |
|---|---|---|---|
| Raw vision JSON (`note`, `confidence`, `unusable_frames`) | `presenterai_recording.visualevidence`, with `visualevidenceat` set at write (plan section 3.3) | Staff only, on the grading screen, behind `mod/presenterai:viewvisualevidence` | `min(media deletion, visualevidenceat + visualdatadays)`, default 30 days |
| Learner facing summary | **New column `presenterai_score.visualsummary`**, text, nullable | The learner, and staff | Lives and dies with the score row |
| Per criterion visual feedback | Already inside `presenterai_score.scores` JSON | The learner, and staff | Lives and dies with the score row |

The summary goes on `presenterai_score`, not on `presenterai_recording`, for the reason the plan gives at section 3.5: a recording can carry more than one score row over time, and the summary is an assertion belonging to one scoring pass. It is not folded into `feedback`, which is the overall comment, and not into a meta blob, because the deletion rules and the privacy declaration both need it addressable on its own.

### 5.2 Storing the raw note is a site setting

`storevisualevidence`, checkbox, **default 1**, which is what D21 decided. When it is off the note is built, used for scoring, and discarded without ever being written. `visualevidenceat` and the clock stay for the sites that keep it.

The setting exists because the readers D21 assumes may not exist on a given site. At Saylor nobody holds `teacher`, `editingteacher` or `manager` in a course (`IMPLEMENTATION-PLAN.md:612`), so the grading screen has no reader, and the export route is thinner than it looks: the note expires at 30 days and the statutory response window for a subject access request is one month, so by the time most requests are answered the field is already null. A site with real teachers and a real grading workflow turns it on knowingly; a site without them can reduce the inference to something transient inside a prompt. What Saylor sets it to is open question 9.19.

### 5.3 The rejected text is logged, to a staff only table

`presenterai_gatelog`: recording id, which text it was (summary or a named criterion), the rule that fired, the rejected text, and `timecreated`. Own clock, 7 days, deleted by the cleanup task independently of everything else. Admin visible only, declared in `get_metadata()`, and named on the settings page so nobody discovers it.

It exists because the tuning plan in section 4.4 is otherwise impossible. When "glass" trips the `glasses` rule the report shows `rule: appearance`, and so does a genuine appearance leak, and the two are indistinguishable in everything else that is stored. A count of which bullet fired cannot tune a word list. If the answer to 9.19 is that nobody reads it, then the report is not a control and the design should stop describing it as one.

### 5.4 The privacy export contains both, declared explicitly

Not exporting the raw note would be wrong: a subject access request is precisely the situation in which a person is entitled to see what a model wrote about their body. SOLA exports it today only by accident, because the provider dumps `session_meta` wholesale (`classes/privacy/provider.php:729`) under a generic declaration (`:162`). PresenterAI declares both in `get_metadata()`:

- `visualevidence`: "A description an AI model produced of what was visible in still frames sampled from your recording: what your hands and arms were doing, your posture, where you were looking, and how you were framed. It is used to score the body language criteria and is deleted after {N} days."
- `visualsummary`: "The short body language summary shown to you with your feedback."

A deletion request removes both with everything else on the recording, through the normal context path.

Worth stating rather than hiding: a learner who makes a request in time receives the raw note, which is the exact text D21 decided was too raw to show them. So the product position is "we show you a checked version, and the route to what the model actually wrote is a data request". That is defensible in law and uncomfortable as a product, and it is part of why 5.2 exists.

### 5.5 The retention interaction, stated out loud

With automatic deletion off by default (D22), media is kept until someone removes it. The raw note still expires at `visualdatadays`. That is not a contradiction and it has to be said on the settings page, because SOLA's `forget_visual_observation()` fires only inside `drop_object()` (`classes/task/soapbox_cleanup.php:139`, reached from retention at `:60` and from stored attempt pruning at `:102`), so on a site that never deletes media it would never run and the prose would live forever. That is the exact state the v7.5.2 fix abolished.

The settings page sentence:

> The AI's raw description of what was visible in a learner's recording is deleted after {N} days even when recordings are kept, because it is unreviewed model output about a person and nothing reads it once the learner's feedback has been written.

### 5.6 The migration does nothing here

D11 already says `visual_observation` is not imported and `visual_assessed` is. Migrated rows arrive with `visualevidence` null and `visualsummary` null and the feedback panel renders neither. Given `IMPLEMENTATION-PLAN.md` section 9.15 there is no production Soapbox data to migrate anyway.

---

## 6. What a learner sees when the summary cannot be produced

Four cases, kept apart, because they call for different actions from the learner. **The invariant is that a learner never sees an empty visual panel.**

### (a1) No usable visual evidence, and the learner's setup is the reason

No frames, the sheet failed the luminance check, or the layer 0 model gate fired on `confidence` or `unusable_frames`. The visual criteria are absent from the rubric entirely, so there is nothing to summarise. Carry SOLA's string at `lang/en/local_ai_course_assistant.php:1766` (`soapbox:visual_not_assessed`), which names the cause, says the criteria were left out rather than marked down, and says what to change.

### (a2) No usable visual evidence, and nothing the learner did caused it

The rate limiter fired, the frame fetch failed, or the scoring route is core AI (section 3.6). **This is a separate string and the split is a fix, not a refinement.** `classes/soapbox_gesture_vision.php:163-165` returns an empty note with `couldhavevideo` still true when the rate limiter fires, and `classes/external/score_speech.php:347-350` then appends the (a1) string, which states its causes as fact: "because no video was recorded or the camera view could not be read". Both are false. The camera was fine and the site hit a quota. The string then closes with "Record with your camera on, with your head, shoulders and hands in the picture", so a learner who did all of that is told to do it again, and with no instructor they will re-record, burn an attempt against the cap, and hit the same limiter.

The (a2) string says the attempt could not be analysed, that nothing they did caused it, and that the score is computed over the other criteria. **It contains no advice**, because there is no action for them to take.

### (b) Evidence existed, the criteria were scored, and only the summary failed

The model omitted the field, the JSON did not parse, or the gate rejected it. The learner must not see a hole and must not be told their camera failed, because it did not. Use the deterministic template, built from the two visual criterion scores already in hand, banded on each criterion's own `max_score`:

- Both at 80 percent or above: "Your gestures and your camera presence both came through clearly in this recording. The comments on those two criteria say what worked."
- One at 80 percent or above, one below 50: name the weaker. "Your camera presence came through clearly. Gestures were the weaker of the two visual criteria here, and the comment on that criterion says what to change next time."
- Both below 50 percent: "Both visual criteria scored in the lower part of the range for this recording. The two comments below say what to change before your next take."
- Anything else: "Your gestures and your camera presence were both assessed for this recording. The comments on those two criteria say what to keep and what to change."
- Any visual criterion with `assessed = false`, including one set false by section 4.7: prepend "Part of the visual feedback could not be judged from this recording."

Every one is a lang string, so it translates. None of them claims to have seen anything, which is the property that makes a template safe as a fallback and unsafe as the primary.

### (c) The whole scoring call failed

`provider_error` (`classes/external/score_speech.php:282`) or `parse_error` (`:292`). There is no feedback panel at all and the question does not arise. The existing empty result path (`:564`) handles it.

---

## 7. The settings

### 7.1 Site level, plugin `mod_presenterai`

| Name | Type | Default | Meaning |
|---|---|---|---|
| `retentiondays` | configtext, PARAM_INT | **0** | 0 keeps media until someone removes it. N deletes media N days after the attempt is finalized. No upper bound, `RETENTION_MAX_DAYS` is gone (D5). Negative coerced to 0. Minimum positive value 1. |
| `allowlearnerdownload` | configcheckbox | **1** | Master switch for learners downloading their own media. |
| `deletewarndays` | configtext, PARAM_INT | 3 | Message a learner this many days before their media is deleted. 0 means no advance message. Inert when nothing has a deletion date. |
| `visualdatadays` | configtext, PARAM_INT | 30 | Clock for the raw body language note. **Minimum 1. Zero does not mean forever here.** See 7.4. |
| `storevisualevidence` | configcheckbox | 1 | Whether the raw note is written at all. Section 5.2. |
| `visualsummaryjudge` | configcheckbox | **1** | Layer 4 of the gate. On everywhere, section 4.6. |
| `s3lifecycledays` | configtext, PARAM_INT | 0 | Admin **declaration** of a bucket lifecycle rule's age. 0 means none or unknown. The plugin cannot read this from S3. S3 backend only. |
| `backupmaxmediabytes` | configtext, PARAM_INT | 0 | 0 means no limit. Above this, a course backup carries attempt metadata without media bytes. Filesystem backend only. |

### 7.2 Per activity, on the mod_form

| Name | Type | Default | Meaning |
|---|---|---|---|
| `retentiondays` | select or int | -1 | -1 uses the site value, 0 keeps forever, N days. Already in the schema at `IMPLEMENTATION-PLAN.md:111`. |
| `storedattempts` | int | **0** | 0 keeps every attempt's media. The plan has `DEFAULT="2"` (`:105`) and it must change; see 8.4. |

**There is no per activity download switch in v1.** D22 records why and fixes the forward rule: a per activity download setting may only ever tighten the site setting, never loosen it, so it can be added later without a data migration. Open question 9.20.

### 7.3 Capabilities

| Capability | Default | Risk | Purpose |
|---|---|---|---|
| `mod/presenterai:downloadown` | student, teacher, editingteacher, manager | none | Download your own media. |
| `mod/presenterai:downloadany` | editingteacher, manager | `RISK_PERSONAL` | Download another learner's media. **Not implied by `viewallattempts`.** |
| `mod/presenterai:deleteownmedia` | student, teacher, editingteacher, manager | `RISK_DATALOSS` | Delete your own media early, keeping score and feedback. Subject to open question 9.21. |
| `mod/presenterai:deleteany` | editingteacher, manager | `RISK_DATALOSS` | Delete a learner's media. Takedown and support. |
| `mod/presenterai:setretention` | manager | none | Change the per activity retention value. |
| `mod/presenterai:viewvisualevidence` | teacher, editingteacher, manager | `RISK_PERSONAL` | See the raw body language note on the grading screen. |

Two of these need their reasoning on the record.

**`setretention` is gated to manager, not editingteacher.** Retention per activity is real power: a teacher can set 0 on a site that deletes, or 14 on a site that keeps. The alternative was site level floor and ceiling settings, and one number cannot express both a minimisation ceiling and an evidence floor. Without the capability the mod_form shows the field frozen at the effective site value with a one line explanation, which is standard Moodle practice and gives a compliance bound site a hard ceiling for free.

**`downloadany` is not governed by `allowlearnerdownload`.** Viewing to grade and taking a copy away are different acts. The site switch exists to control what learners may keep; if it also blocked graders, an admin could not express the reasonable policy "learners may not circulate recordings, but a grader may take evidence to a moderation meeting". One setting cannot mean two things.

### 7.4 `visualdatadays` does not share the media semantics

Under the media setting, 0 means forever. For `visualdatadays` the minimum is 1 and there is no forever option, because 0 there would restore the exact state D5 exists to abolish: model prose about a named learner's body, kept indefinitely. The raw note always expires, on its own clock, regardless of media retention.

**This is where D22 meets D21.** The raw note expires on `visualdatadays`. The rewritten learner facing summary is feedback and is kept with the score, on the same terms as every other criterion comment. Say that asymmetry out loud in both the setting's help text and the privacy declaration, because a reader who has just learned that 0 means forever for media will otherwise assume it means forever here.

### 7.5 The download gate

```
may_download_own(user, recording):
    get_config('mod_presenterai', 'allowlearnerdownload') == 1
    AND has_capability('mod/presenterai:downloadown', $modulecontext, $user)
    AND recording.userid == user.id
    AND recording.storagekey IS NOT NULL

may_download_other(user, recording):
    has_capability('mod/presenterai:downloadany', $modulecontext, $user)
    AND recording.storagekey IS NOT NULL
```

**On S3** this is `presign_get($key, 900, $filename)` with `response-content-disposition` signed into the canonical query string. The mechanics already work and the comment at `classes/soapbox_storage.php:182-185` explains why appending it to a finished URL would invalidate the signature. The property to state rather than hide: **a presigned URL is a bearer token.** Once issued it works for its full TTL for anyone holding it, regardless of the switch being flipped, the learner logging out, unenrolment, or the row being marked deleted. So on S3 the download switch is enforced **at issue time only**, and flipping it off leaves a window equal to the TTL. 900 seconds, per `IMPLEMENTATION-PLAN.md:498`, which is a reduction from the 3600 SOLA uses today at `soapbox_present.php:335,338`. The admin help says this rather than implying the switch is instant.

**On the filesystem** it is `send_stored_file($file, 0, 0, $forcedownload, ['cacheability' => 'private'])`. `$forcedownload = true` is the entire difference from inline playback. Enforcement is per request, so revocation is immediate. A `?forcedownload=1` parameter is a rendering choice only and the handler re-checks the capability on every request, never treating the parameter as authorisation.

**Every download fires `recording_downloaded`**, with `relateduserid` set to the recording's owner, including learner self downloads. Logging only staff downloads makes the report look like a surveillance log; logging both makes it an access record, and the volume is trivial. A copy of a learner's face leaving the platform is the single most likely thing an institution is asked about later, and the event log is the only answer available.

---

## 8. The schema consequence

### 8.1 What `expiresat` holds

`expiresat`, int, NOT NULL, DEFAULT 0, exactly as the plan has it at `IMPLEMENTATION-PLAN.md:198`. **No schema change is needed for optional retention.**

- `0`: no scheduled deletion. On a default install this is every row.
- `> 0`: the instant the media becomes eligible for deletion.

Resolution, replacing `soapbox_config::clamp_retention_days()` (`classes/soapbox_config.php:108-116`):

```php
// mod_presenterai\local\retention
public static function effective_days(\stdClass $instance): int {
    $v = (int) $instance->retentiondays;          // -1 site, 0 forever, N days
    if ($v === -1) {
        $v = (int) get_config('mod_presenterai', 'retentiondays');
    }
    return $v > 0 ? max(1, $v) : 0;               // no upper clamp, 0 means never
}
```

At finalize, replacing `classes/external/soapbox_finalize_recording.php:200`:

```php
$days = retention::effective_days($instance);
$row->expiresat = $days > 0 ? $now + $days * DAYSECS : 0;
```

### 8.2 What happens when an admin changes the setting after recordings exist

**`expiresat` is a fact about a row, written once at finalize, and never rewritten by a settings change.** Changing the site value or the activity value affects attempts made from that point on and nothing else.

The principle in one line, because it is what makes the model safe to reason about: **no implicit change can ever shorten an existing recording's life. Shortening always requires an explicit command.**

| Admin action | Existing rows | Is that right? |
|---|---|---|
| Turn auto delete on, 0 to 30 | Keep `expiresat = 0`, kept forever | Yes. Backfilling from `timecreated` would delete a year of evidence on the next cron run, from a form save with no confirmation, belonging to learners who were told "kept until removed". |
| Shorten X, 90 to 30 | Keep their longer date | Yes. The learner was shown that date. |
| Lengthen X, 30 to 90 | Keep their shorter date | Surprising to the admin, safe for the learner. The CLI covers the admin. |
| Turn auto delete off, 30 to 0 | Keep their dates, and they are still deleted | Correct, and the one people get wrong. The row is a promise already made. Cancelling those promises is `cli/apply_retention.php --from=none`, a deliberate act. |

If `expiresat` were instead derived from the setting at read time, turning retention off would have to resurrect rows whose bytes are already gone, which is incoherent, and turning it on afterwards would have no basis to compute from. The row has to remember.

**The learner facing consequence, which is the point.** An admin turns retention off. Every existing row still carries a future `expiresat`, and cron still deletes them. If the attempt list drew its text from the setting, every one of those learners would be told their recording is kept until removed, and then it would be deleted on schedule. Sourcing from the row means they are correctly told "Deletes on 27 September 2026" right up until it goes, while the callout above the recorder correctly says new recordings are kept. Both statements are true at the same time on the same page, which is why every prospective string says "this recording" and never "your recordings".

### 8.3 The tool that does change existing rows

`cli/apply_retention.php`, specifying the plan's section 4.5 consequence 2:

- `--from=created|now|none` is mandatory with no default. `created` sets `timecreated + X`, `now` sets `time() + X`, `none` sets `expiresat = 0` and cancels pending deletions.
- Dry run is the default. `--execute` is required to write.
- `--course=`, `--instance=`, `--olderthan=` for scoping.
- Prints before writing: rows affected, rows that would become eligible within 24 hours, and **the count of rows currently carrying `expiresat = 0`**, because those are the ones whose learners were shown "kept until removed", and that is the size of the promise being broken.
- **Grace floor, on by default.** With `--from=created`, any computed date in the past or within `max(deletewarndays, 7)` days is raised to `now + max(deletewarndays, 7)` days. A retroactive apply can shorten the future; it cannot delete anything today. `--no-grace` opts out and requires `--force`.
- `--notify` sends the deletion notice to affected learners as part of the run.
- Refuses above a threshold of newly eligible rows without `--force`.
- Its output states that it changes **what learners are told**, not only when cron deletes.

The `retentiondays` settings field carries this next to it: "Changing this affects recordings made from now on. Recordings that already exist keep the deletion date they already have. Use the apply retention tool to change them."

### 8.4 Three schema changes this design asks for

1. **`presenterai_score.visualsummary`**, text, nullable. Not in plan section 3.5.
2. **`presenterai_recording.mediagonereason`**, char(20) NOT NULL DEFAULT ''. Values `retention`, `pruned`, `manual`, `learner`, `missing`, `notbackedup`. Without it the plugin has to guess why a key is null, and the learner facing sentence genuinely differs: an announced scheduled deletion is information, "this recording could not be found" is a support ticket. No index.
3. **`storedattempts` DEFAULT 0, not 2** (`IMPLEMENTATION-PLAN.md:105`), and drop the `max(1, ...)` floor at `classes/task/soapbox_cleanup.php:93`. Pruning is a second deletion path with no clock and no notice (`:84-104`). With a default of 2, a learner's third attempt destroys their first recording at the moment they press record, on a site whose stated default is keep forever. D22 is not honoured by the retention setting alone.

Also revisit the index. The plan has `statusexp` on `(status, expiresat)` (`:212`), which matched SOLA's query. The new cleanup query does not filter on status, so replace it with `expiresat` alone plus `(status, timecreated)` for the abandoned upload sweep (D16). New query:

```sql
storagekey IS NOT NULL AND expiresat > 0 AND expiresat <= :now
```

### 8.5 The media gone state is first class

`storagekey IS NULL` means the bytes are gone. `status` never carries `deleted` (D8), it carries scoring states only. `mediadeletedat` says when, `mediagonereason` says why. Grades are computed over score rows, never over media existence (plan section 6). This is what makes C2's silent grade loss impossible and what makes a learner delete safe: the score row survives, the attempt still counts toward the grade and the attempt cap, so deleting media cannot be used to escape a bad mark.

Six ways media goes, one state, six sentences. `retention` and `pruned` are routine. `manual`, `learner`, `missing` and `notbackedup` each need their own sentence, and `missing` should also raise an admin health warning rather than being shown to the learner as if it were planned.

What the learner sees: the attempt row survives with its date, length, status and full feedback panel, and only the player link is replaced. SOLA's shape is already right, because the feedback row at `templates/soapbox_present.mustache:164-199` sits outside the `viewurl` condition and already renders for an expired attempt. Two changes: the bare "Expired" label (`:163`, string at `lang/en/local_ai_course_assistant.php:1741`) becomes a sentence that says why the video is gone and that score, feedback and transcript are kept; and **the deletion date cell renders only while `storagekey IS NOT NULL`**, because showing "Deletes on 3 October" against an attempt whose media is already gone is the obvious bug this design creates if nobody says otherwise.

And a planned deletion must never surface as a lookup failure. `classes/external/soapbox_get_playback.php:68-70` throws `soapbox:assignment_notfound` when the key is empty. The PresenterAI web service returns a structured media gone result carrying `mediadeletedat` and `mediagonereason`, and the player renders a sentence.

**Rescore stays possible, retranscribe does not.** The transcript is a column on the recording (`IMPLEMENTATION-PLAN.md:196`), so after media deletion a teacher can still rescore from it. Visual criteria must be marked `assessed = false` on such a rescore, which the assessed only denominator already handles. Transcription is impossible with no audio and the teacher screen must say so rather than offering a button that fails.

### 8.6 The two storage backends

**S3.** The lifecycle rule is the whole problem and keep forever makes it worse. SOLA asserts a bucket rule exists as its backstop four times and never reads one (`classes/soapbox_storage.php:30-31`, `classes/task/soapbox_cleanup.php:26-27,121-122`, `classes/privacy/provider.php:541`). Under a 7 day clock an unseen rule is roughly aligned with the plugin's behaviour. Under keep forever it is a contradiction: the database says the recording is kept and the bucket has already thrown it away. Five consequences:

1. `s3lifecycledays` is an admin **declaration**, not a read. Reading the real rule needs `s3:GetLifecycleConfiguration` and a parser, and it can be changed behind the plugin's back at any time, so a read would be a snapshot presented as a guarantee. When it is greater than zero and the effective retention is 0 or longer, learner facing text must not say "kept until removed".
2. The storage self test states in words that it cannot read lifecycle configuration, so nobody reads a green self test as proof that keep forever holds.
3. A new low frequency `reconcile_media` task signs a HEAD for a bounded sample of rows whose media should exist, oldest first, newest N excluded, a few hundred per run, and on a 404 sets `storagekey = NULL`, `mediadeletedat = time()`, `mediagonereason = 'missing'`. Sampling matters: on a site keeping forever you cannot HEAD every row nightly. This is the only mechanism that keeps the database honest about a bucket the plugin does not control.
4. On a versioned bucket a delete writes a delete marker and the bytes survive as a noncurrent version, so on S3 "deleted" means "no longer reachable with this plugin's credentials". Learner facing and privacy text say what the plugin did, not what the bucket did.
5. Orphan deletion becomes the plugin's job. Under a 7 day clock an orphaned object self healed through the lifecycle rule; under keep forever it does not. `presenterai_delete_instance()`, course deletion and course reset must each delete S3 objects explicitly. SOLA already names this failure at `classes/privacy/provider.php:529-535`: deleting the row without the object strands it permanently, because the cleanup task walks rows and there would no longer be one. Keep forever turns that from an edge case into the normal path.

Cost visibility: `sizebytes` is already written at finalize (`classes/external/soapbox_finalize_recording.php:196`), so a per course, per instance and site total is a `SUM()` with no S3 API call. Keep forever without a number on the settings page is how a storage bill becomes a surprise.

**Moodle file storage.** No invisible deleter, so the plugin can say honestly what deletion means. It needs a measured disk figure on the settings page from `SUM(sizebytes)` plus `disk_free_space($CFG->dataroot)` as advisory and not as a blocking check, since dataroot can be clustered. And it needs a backup answer, because the `recording` file area is user data and with keep forever every user data course backup carries every recording; the plan's own arithmetic puts 1,000 learners at 2 attempts each around 57 GB. `backupmaxmediabytes` sets a per backup ceiling above which the backup writes attempt metadata and omits the bytes, and records the omission inside the mbz so the restore marks those attempts media less with `mediagonereason = 'notbackedup'` rather than restoring rows that point at files which are not there. That reuses the media gone state that has to exist anyway, so it costs one flag and no new UI.

**One rule spanning both.** The store is resolved **per row** from `presenterai_recording.backend`, never once per process. SOLA builds one storage object before the loop (`classes/task/soapbox_cleanup.php:50`) and returns early if storage is unconfigured (`:47-49`), which on a site that had switched backends would skip every surviving row on the other backend forever. The plan states this at section 4.7; the cleanup task is where it bites hardest.

### 8.7 Settings an admin can choose that are actively harmful

**a. Retention shorter than the time it takes to score.** Scoring retries transient failures for roughly 30 minutes and cron can be backed up far longer, so a very short window plus a backlog deletes the media before transcription reads it. The minimum positive value is 1, so "delete immediately" cannot be typed by accident; the form warns below 3 days; and the cleanup task carries a hard floor independent of `expiresat`, never deleting media for a row whose status is `uploading`, `uploaded` or `scoring` and whose `timecreated` is within 24 hours.

**b. Auto delete on with learner download off.** A learner's presentation is destroyed on a schedule and they were never able to keep a copy. SOLA's own build notes name the exposure (`.drafts/v7.5.1-build-plan.md:529`). Blocking the save via `write_setting()` was considered and rejected: ephemeral by policy is a legitimate configuration some institutions require. Instead, a warning rendered with both settings naming the exact consequence, plus a **mandatory** learner sentence before recording.

**c. `cli/apply_retention.php --from=created` on a site that has been keeping forever.** Mass immediate deletion from one command. Guarded by 8.3 in full.

**d. `storedattempts` small with `maxattempts` large.** The only deletion path with no clock and no announcement. Default 0 (8.4), and when it is greater than 0 the recorder screen says before recording: "Recording again will delete your oldest recording, made on <date>. Your score and feedback for it are kept."

**e. An S3 lifecycle rule shorter than the plugin's retention, with retention off.** The plugin cannot prevent this. It can stop lying about it (`s3lifecycledays`) and repair the row afterwards (`reconcile_media`).

**f. Disabling the `presenterai_cleanup` task while rows carry deletion dates.** Every affected learner sees "Deletes on <date>" for a date that never arrives. A health line appears on the settings page and the activity report, and **the learner facing date is suppressed**, falling back to "kept until deleted", when the task is disabled. Never display a date the site is not going to honour. This is checkable at render time from the scheduled task record.

**g. Lowering the site retention while per activity overrides exist.** Nothing happens to the overriding activities, which is correct and surprising. The setting shows a count of instances that override it, with a link to the list.

---

## 9. The learner facing strings

### 9.1 Three groups of inputs, and why they must not be mixed

**Group A, prospective.** Describes the recording the learner is about to make. Source: the **effective setting now**, resolved by `retention::effective_days($instance)` and the download gate in 7.5.

**Group B, retrospective.** Describes one attempt that already exists. Source: **the row**, never the setting. `expiresat`, `mediadeletedat`, `mediagonereason`, `storagekey IS NULL`.

**Group C, gating the privacy paragraph.** Instance `videovision` and `mode` decide whether still frames exist at all. Site `visualdatadays` gives the raw note its own clock.

Group A and Group B can disagree on the same page at the same time and both be true (8.2). That is accurate state, not a bug to paper over, and it is why every prospective string says "this recording" and never "your recordings".

**Download is deliberately not stored per attempt.** Deletion is a promise about the future, made at recording time, so it is frozen on the row. Download is a permission exercised now, so it is read live. The asymmetry looks like an oversight unless it is written down, so it is written down here and in a code comment.

**Guard test, `retention_message_source_test`.** Create a row with `expiresat` in the future, set the site setting to 0, assert the rendered cell is still the date. Then set `expiresat = 0`, set the site setting to 7, assert the cell is `attempt_kept` and contains no date. A second test greps the templates and the view code for `get_config` reached from any per attempt renderer.

### 9.2 Naming

Bare keys with underscores, no `soapbox:` style prefix. SOLA uses a prefix because it is one local plugin hosting many features; `mod_presenterai` is the component, so the prefix is redundant and core convention for an activity is the bare key.

`{$a}` carrying the site name is filled from `format_string($SITE->fullname)`. **No `[[uniname]]`.** PresenterAI is clean room (D3) and has no branding resolver. SOLA's comment at `soapbox_present.php:146-153` records what happens when a caller forgets one: `get_string()` shipped the literal `[[uniname]] storage` to every learner in all 46 locales, and `tests/branding_test.php` could not catch it because it asserts no string retains a token after `apply()` runs, not that a caller remembered to run it.

**No learner string says "your teacher."** DECISIONS.md Part 1 records that Saylor's learners are fully online and self paced with no teacher, no human evaluation and no appeal path. Where a route out is offered it is gated on a route existing.

### 9.3 Before recording: the policy callout

Four pairs, one per combination of the two settings. It is **a sibling of the attempt table, not a child of the recorder.** In SOLA the how to card carrying the retention statement sits inside `{{#storageready}}` (`templates/soapbox_present.mustache:102-115`) while the attempt table sits outside it (`:141-207`), so on a site whose storage is unconfigured the table appears and the retention policy does not.

```php
// Deletion OFF, download ON.
$string['record_keep_dl_heading'] = 'Your recording is kept until it is deleted.';
$string['record_keep_dl_body'] = 'Nothing deletes this recording automatically. It stays on {$a} until it is deleted by you or by someone with permission to manage this activity, or until the activity or the course is removed. You can download a copy at any time from the list of your attempts below.';

// Deletion OFF, download OFF.
$string['record_keep_nodl_heading'] = 'Your recording is kept until it is deleted, and cannot be downloaded.';
$string['record_keep_nodl_body'] = 'Nothing deletes this recording automatically. It stays on {$a} until it is deleted by you or by someone with permission to manage this activity, or until the activity or the course is removed. Downloading is switched off for this activity, so you can watch your recording here but cannot save a copy to your own device. Your scores, written feedback and transcript are always available to you here.';

// Deletion ON, download ON.
$string['record_delete_dl_heading'] = 'Your recording is deleted after {$a} days.';
$string['record_delete_dl_body'] = 'This recording is deleted automatically {$a} days after you make it. The exact date is shown against every attempt in the list below. Your scores, written feedback and transcript are kept after the recording is gone. Download anything you want to keep before that date.';

// Deletion ON, download OFF.
$string['record_delete_nodl_heading'] = 'Your recording is deleted after {$a} days, and cannot be downloaded.';
$string['record_delete_nodl_body'] = 'This recording is deleted automatically {$a} days after you make it, and the exact date is shown against every attempt in the list below. Downloading is switched off for this activity, so there is no way to save a copy of the recording before it goes. Your scores, written feedback and transcript are kept and stay available to you here after the recording is gone.';

// Appended to either _nodl_ body, only when the site has a support contact.
$string['record_nodl_contact'] = 'If you need a copy of the recording itself, contact {$a} before that date.';

// Appended when storedattempts > 0 and the learner is at the cap.
$string['record_prune_warning'] = 'Recording again will delete your oldest recording, made on {$a}. Your score and feedback for it are kept.';
```

`record_nodl_contact` renders only when `$CFG->supportemail` or `$CFG->supportpage` is non empty, with `{$a}` as the rendered link.

The deletion on plus download off pair does four things deliberately. It says **"switched off for this activity"**, not "not available" and not "you do not have permission", because this is a configuration choice someone made, not a fault and not something the learner failed to earn. It states the loss **before** the learner records, in the same callout as the deletion date, because a learner who finds out after speaking for seven minutes has been misled by omission. It immediately names **what is kept**, because without that sentence "deleted and you cannot keep it" reads as if the whole attempt evaporates, which is false and is the version that generates the complaint. And it offers a route **only if a route exists**.

### 9.4 The privacy paragraph, assembled from clauses

```php
$string['privacy_stem'] = 'Your recording is uploaded to {$a} storage so it can be transcribed and scored. Only you and people with permission to view submissions in this course can open it.';
$string['privacy_delete'] = 'It is deleted automatically {$a} days after you record it.';
$string['privacy_keep'] = 'It is not deleted automatically. It is kept until it is deleted here, or until this activity or the course is removed.';
$string['privacy_frames_delete'] = 'The still frames used for body language feedback are deleted with it.';
$string['privacy_frames_keep'] = 'The still frames used for body language feedback are kept for as long as the recording is.';
$string['privacy_visualnote'] = 'The note the AI writes about what it saw in those frames is deleted after {$a} days, whether or not the recording itself is still here.';
$string['privacy_kept_after'] = 'Your transcript, scores and feedback are kept after the recording is gone.';
$string['privacy_download_on'] = 'You can download a copy of your recording at any time while it is here.';
$string['privacy_download_off'] = 'Downloading is switched off for this activity, so you cannot save a copy to your own device.';
```

Assembly order: stem, deletion clause, frames clause, visual note clause, kept after clause, download clause, joined by a single space.

**Why clauses and not eight whole paragraphs.** Three independent conditions give eight paragraph variants, and a translator has to keep eight long paragraphs consistent across 45 locales forever. Nine short clauses is nine units and the conditions compose. The standard objection to clause assembly is word order and grammatical agreement across languages, and it does not apply here because **every clause is a complete sentence joined to its neighbour by a space and nothing is slotted into the middle of another sentence.** That constraint must be written into the translator notes or someone will later improve it into fragments.

Six changes from SOLA's `soapbox:present_privacy` (`lang/en/local_ai_course_assistant.php:1754`), which is called at `soapbox_present.php:154-157`:

1. The deletion clause becomes conditional, which forces the split. A single `{$a}` cannot express "no deletion": there is no day count that makes "deleted automatically N days after you record it" true when nothing deletes it.
2. Clause assembly, not eight paragraphs, per above.
3. **The frames clause is already false today and now sits behind a gate.** The string states "together with the still frames used for body-language feedback" unconditionally, but the frames exist only when the sampler ran. `soapbox_present.php:154-157` passes no gesture flag, while the sampler is gated at `:217` by `soapbox_gesture_vision::is_enabled($assign)`, which returns false for `mode === 'audio'` (`classes/soapbox_gesture_vision.php:104-106`) and false when the site toggle is off (`:101-103`). So on every audio only assignment today, and on every assignment on a site with the toggle off, the privacy notice tells the learner about still frames that were never taken. The clause renders only when `videovision` is on for the instance and `mode !== 'audio'`.
4. `privacy_visualnote` is new with D22 and no current string covers it. It is the learner facing consequence of D5: on a keep forever site the recording survives and the AI's note about their body does not.
5. `[[uniname]]` goes, replaced by `{$a}`.
6. "site administrators" is wrong for an activity. Soapbox has no module context (D2), so administrators genuinely were the only other readers. PresenterAI has `mod/presenterai:viewallattempts`, which a teacher role holds, so the stem says "people with permission to view submissions in this course".

### 9.5 The attempt list

```php
$string['col_recording'] = 'Your recording';   // replaces 'Deletes on'

$string['attempt_deletes_on']     = 'Deletes on {$a}';
$string['attempt_deletes_due']    = 'Due to be deleted';
$string['attempt_kept']           = 'Kept until deleted';
$string['attempt_deleted_on']     = 'Deleted on {$a}';
$string['attempt_deleted']        = 'Deleted';
$string['attempt_never_uploaded'] = 'Not uploaded';
$string['attempt_gone_pruned']    = 'Replaced by a newer attempt on {$a}';
$string['attempt_gone_manual']    = 'Removed on {$a}';
$string['attempt_gone_learner']   = 'You deleted this recording on {$a}';
$string['attempt_gone_missing']   = 'This recording could not be found in storage';
$string['attempt_gone_notbackedup'] = 'This recording was not included in the backup this course was restored from';

$string['watch'] = 'Watch';
$string['watch_aria'] = 'Watch the recording you made on {$a}';
$string['download'] = 'Download';
$string['download_aria'] = 'Download the recording you made on {$a}';
$string['attempt_gone_note'] = 'No longer available to watch';

$string['download_off_note'] = 'Downloading is switched off for this activity. You can watch your recordings here but cannot save a copy to your own device.';
```

Cell selection, entirely from the row:

| Row state | Cell |
|---|---|
| `storagekey` not null, `expiresat > time()`, cleanup task enabled | `attempt_deletes_on` with `userdate(expiresat)` |
| `storagekey` not null, `expiresat > 0`, cleanup task **disabled** | `attempt_kept` (8.7f: never show a date the site will not honour) |
| `storagekey` not null, `expiresat > 0 && expiresat <= time()` | `attempt_deletes_due` |
| `storagekey` not null, `expiresat = 0` | `attempt_kept` |
| `storagekey` null, `mediadeletedat > 0` | `attempt_deleted_on`, or the `attempt_gone_*` string for the row's `mediagonereason` |
| `storagekey` null, `mediadeletedat = 0` (migrated, D8) | `attempt_deleted` |
| `status` in (`uploading`, `abandoned`), key never set | `attempt_never_uploaded` |

`attempt_deletes_due` is not a corner case to skip. Cron runs once a day. SOLA renders `userdate($r->expires_at, ...)` whenever `expires_at > 0` (`soapbox_present.php:255-257`) with no comparison to now, so on any site where cron is late a learner is shown a deletion date in the past.

**Two changes from SOLA's table.** The header `soapbox:col_deletes = 'Deletes on'` (`lang:1755`) becomes the neutral `col_recording`, because on a keep forever site the old one is a `<th>` promising deletion over a column that never contains a date. And the footer note goes: SOLA states retention twice on one page, in the how to card (`templates/soapbox_present.mustache:110-113`) and again under the table (`:206`). Two near identical statements is accretion, not emphasis. The policy is stated once, in the callout.

`download_off_note` appears **once under the table, not per row**, because repeating it against every attempt is noise, and on a screen reader it is noise repeated N times.

### 9.6 The feedback panel

```php
$string['feedback_media_gone'] = 'The recording for this attempt has been deleted. Your scores, written feedback and transcript below are kept.';

// D21: the rewritten summary, never the raw note.
$string['visual_summary_heading'] = 'Body language and camera presence';
$string['visual_summary_note'] = 'This summary was written by the AI from six still frames taken from your recording. It describes what those frames showed, so that you can see what your body language score was based on.';

// Section 6 (b): the deterministic fallback bands.
$string['visual_fallback_bothstrong'] = 'Your gestures and your camera presence both came through clearly in this recording. The comments on those two criteria say what worked.';
$string['visual_fallback_onestrong'] = 'Your {$a->strong} came through clearly. {$a->weak} was the weaker of the two visual criteria here, and the comment on that criterion says what to change next time.';
$string['visual_fallback_bothweak'] = 'Both visual criteria scored in the lower part of the range for this recording. The two comments below say what to change before your next take.';
$string['visual_fallback_mixed'] = 'Your gestures and your camera presence were both assessed for this recording. The comments on those two criteria say what to keep and what to change.';
$string['visual_fallback_partial'] = 'Part of the visual feedback could not be judged from this recording.';

// Section 4.7: replaces a criterion comment the gate rejected.
$string['visual_criterion_withheld'] = 'The written comment for this criterion was not shown, because an automatic check found it did not meet the standard for feedback about a person. This criterion has been left out of your score rather than counted against you.';

// Section 6 (a1) and (a2).
$string['visual_not_assessed'] = 'Body language and camera presence were not assessed for this attempt, because no video was recorded or the camera view could not be read. Those criteria were left out of your score rather than marked down. Record with your camera on, with your head, shoulders and hands in the picture, to get feedback on them.';
$string['visual_not_analysed'] = 'Body language and camera presence could not be analysed for this attempt. Nothing you did caused this and there is nothing to fix. Those criteria were left out of your score rather than marked down, and your score is worked out from the other criteria.';
```

`visual_summary_note` says six frames because that is what ships (`amd/src/soapbox_frames.js:47-52`). C5 in DECISIONS.md exists because the prior plan said nine at 512 px. Do not write a learner facing number the code does not produce.

`visual_not_analysed` contains no advice, for the reason in section 6 (a2).

`feedback_media_gone` is what makes D22 survivable on a keep forever site too, because `storedattempts` pruning still removes media that retention would not.

### 9.7 Admin and teacher strings

```php
$string['setting_retentiondays'] = 'Delete recordings after (days)';
$string['setting_retentiondays_desc'] = 'How long a recording is kept before it is deleted automatically. 0 means recordings are never deleted automatically and are kept until someone removes them, which is the default. Transcripts, scores and feedback are always kept. When this is not 0, learners are shown the deletion date against every attempt. Changing this affects recordings made from now on. Recordings that already exist keep the deletion date they already have. Use the apply retention tool to change them.';

$string['setting_allowlearnerdownload'] = 'Learners can download their own recordings';
$string['setting_allowlearnerdownload_desc'] = 'When on, a learner sees a Download link against each of their own attempts while the recording still exists. When off, they can watch a recording here but cannot save a copy. This does not affect staff, who download from the submissions report under a separate capability. On S3 storage the link is a signed URL that keeps working for up to 15 minutes after it is issued, so turning this off is not instant for links already handed out.';

$string['setting_combination_warning'] = 'Recordings on this site are deleted after {$a} days and learners cannot download them, so a learner has no way to keep their own presentation. The activity tells them this in plain words before they record, because they would otherwise find out after the recording had gone. Scores, written feedback and transcripts are not affected and are kept. If you want the short retention window but not that outcome, turn learner download back on.';

$string['setting_visualdatadays'] = 'Delete AI body language notes after (days)';
$string['setting_visualdatadays_desc'] = 'The AI writes a short note describing what it saw in the still frames, which is what the body language criteria are scored from. This clock is separate from the recording retention above, so the note is deleted even on a site that keeps recordings forever. There is no option to keep it forever and the minimum is 1 day. The short summary shown to the learner is feedback and is kept with their score. Default 30.';

$string['setting_storevisualevidence'] = 'Keep the AI raw body language note for staff';
$string['setting_storevisualevidence_desc'] = 'When on, the AI raw description of what was visible is stored on the attempt and can be read by staff with the view visual evidence capability, and is included in a data request. When off, it is used to score the criteria and then discarded. Turn it off on a site with no staff who grade, where nothing would ever read it.';

$string['setting_visualsummaryjudge'] = 'Check body language feedback with a second AI model';
$string['setting_visualsummaryjudge_desc'] = 'Before body language feedback is shown to a learner, a small model checks that it describes what the speaker did and not what the speaker is like. The word list checks that run alongside it exist for English only, so in every other language this is the only check there is. Leave it on.';

$string['setting_deletewarndays'] = 'Warn learners this many days before deletion';
$string['setting_deletewarndays_desc'] = 'Sends a message this many days before a recording is deleted. 0 sends no message. Has no effect when recordings are not deleted automatically. On a self paced site a learner who visits weekly may otherwise never see the page inside the window.';

$string['setting_retention_s3_note'] = 'This site stores recordings in an S3 bucket. PresenterAI cannot read your bucket lifecycle configuration, so a lifecycle rule on the recording prefix will keep deleting objects whatever this setting says, and on a versioned bucket a delete leaves a noncurrent version behind. PresenterAI can state what it did; the bucket policy decides the rest.';

// Module form.
$string['retentiondays_inst'] = 'Delete recordings after';
$string['retentiondays_inst_help'] = 'Use the site default, keep recordings until someone removes them, or set a number of days. When a number is set, learners see the deletion date against every attempt, and a recording already made keeps the date it was given, so changing this here does not move an existing deletion date.';
$string['retentiondays_locked'] = 'Recordings on this site are deleted after {$a}. Changing this per activity needs the set retention capability.';

$string['visual_raw_heading'] = 'Raw body language observation (staff only)';
$string['visual_raw_note'] = 'Unreviewed AI output. It is what the two visual criteria were scored from. It is not shown to the learner and it is deleted after {$a} days.';
```

`setting_combination_warning` renders only when the combination is actually selected, in three places: on the site settings page as an `admin_setting_description` directly under the download setting; on the module form as a `static` element under the retention field, which is the more important of the two because the teacher configuring the instance is the person who sees the consequence; and in the activity's admin health line alongside the storage self test, so it is visible without opening the settings page.

**An honest limitation.** A Moodle `admin_setting` description is rendered once, server side, at page load, and reads the **saved** values. An admin who changes the dropdown and has not yet saved will not see the warning appear or disappear live. Making it live needs JS on the settings page, which is out of proportion. The module form placement does not have this problem in the same way, because `mod_form` can use `hideIf` and is re-rendered on validation.

**It is a warning, not a block**, for the reason in 8.7b.

### 9.8 Where each string renders

| String | Before recording | Attempt list | Feedback panel | Elsewhere |
|---|---|---|---|---|
| `record_*_heading`, `record_*_body` | callout, sibling of the table | | | |
| `record_nodl_contact` | appended to a `_nodl_` body when a support contact exists | | | |
| `record_prune_warning` | when `storedattempts > 0` and at the cap | | | |
| `privacy_*` (9 clauses) | assembled paragraph below the callout | | | also the data registry page |
| `col_recording` | | `<th>` | | |
| `attempt_*` (11) | | cell, one per row | | |
| `watch`, `watch_aria`, `download`, `download_aria` | | actions cell, per row | | |
| `attempt_gone_note` | | actions cell, replacing the links | | |
| `download_off_note` | | once below the table, only when download is off and at least one row still has media | | |
| `feedback_media_gone` | | | top of the panel, when `storagekey` is null | |
| `visual_summary_heading`, `visual_summary_note` | | | above the summary | |
| `visual_fallback_*` (5) | | | in place of the summary, section 6 (b) | |
| `visual_criterion_withheld` | | | in place of one criterion comment | |
| `visual_not_assessed`, `visual_not_analysed` | | | in place of the panel's visual section | |
| `setting_*` | | | | site settings page |
| `retentiondays_inst*` | | | | module form |
| `visual_raw_*` | | | | teacher grading screen |

---

## 10. The i18n cost, stated as a number

SOLA ships 46 `lang` directories, 45 of them non English. PresenterAI inherits that expectation, subject to open question 9.22.

**Learner facing. Never on a translation allowlist.** The 34 keys in 9.3 to 9.6, less the admin ones. At 45 locales that is **1,530 translated strings for this feature alone.** That is the real cost and it belongs in front of Tom as a number rather than as "translation work".

SOLA's own rule, verbatim from `tests/lang_completeness_test.php:330-333`: "Nothing a LEARNER sees is in this list: the eighteen soapbox: strings this release adds are translated into all 45 locales, because a self-paced learner with no instructor reads the feedback page as the entire product."

**Allowlist eligible.** Everything in 9.7 plus the CLI output of `apply_retention.php`.

**A judgement call, flagged rather than asserted.** `retentiondays_inst`, `retentiondays_inst_help` and `retentiondays_locked` are module form strings seen by a teacher. SOLA's written rule covers learners only and SOLA's practice puts teacher surfaces on the allowlist. Recommendation: translate them anyway, because PresenterAI is a public plugin (D1) and a Moodle site in Brazil has Portuguese speaking teachers, where Saylor's admin pages have exactly one reader. This is a recommendation, not a rule that exists.

**A trap worth naming.** `privacy:metadata:*` strings are not admin strings. A learner reaches them through Moodle's data registry when they make their own data request. SOLA has 68 privacy strings sitting in `IDENTICAL_TO_ENGLISH_BACKLOG` (`tests/lang_completeness_test.php:796`), which is the worse failure mode: present in every locale file, so a parity test passes and "46/46 with zero missing keys" is true, while the learner reads English. PresenterAI needs both gates from day one, the parity gate and the identical to English gate, or it accrues the same debt invisibly.

**What does not translate as a lang string, and must be decided rather than discovered.**

*The summary itself* is per attempt model output, generated in the learner's Moodle language by the `{LANGUAGE}` line in prompt 2, resolved as section 3.4 specifies. Free at generation, and it is what makes the two points below real problems rather than theoretical ones.

*The deny list is per language and will not be written for 45 of them.* This is the honest cost of layer 2 and it belongs on the settings page, not in a postmortem. English ships at v1; where no list exists, layers 2 and 3 are skipped and layer 4 carries the gate alone.

*The prompts stay in English*, all three, with the output language named inside prompt 2. Translating a system prompt into 46 languages is 46 things to keep in sync and degrades instruction following in most of them.

---

## 11. Accessibility

The defect this section exists to avoid repeating, from SOLA commit `2f0b1c48` of 19 September 2026: all three renderers of the not assessed badge put the explanation in a `title` attribute on a span that already had visible text. An element with its own text takes its accessible name from that text, so the title was decorative on hover and most screen readers never announced it. The string was named `soapbox:not_assessed_aria`, so the intent was that assistive technology would read it, and it did not. Fixed by a visually hidden sibling, visible at `templates/soapbox_present.mustache:182`.

Ten rules for this feature.

1. **No explanatory text in a `title` attribute, ever.** If it matters it is visible text or `accesshide` text. If it does not matter, do not write it.
2. **`accesshide`, not `sr-only`, not `visually-hidden`.** `accesshide` is Moodle core's own class. `sr-only` is Bootstrap 4 and `visually-hidden` is its Bootstrap 5 rename, and PresenterAI declares support across 4.5 to 5.x, which spans that rename.
3. **`aria-label` is allowed only where it replaces a name with strictly more information.** The commit rejected it for the badge because it would have replaced "Not assessed" rather than adding to it. The opposite case is `download_aria` and `watch_aria`: the visible text is "Download" in every row, and a screen reader user hears "Download, Download, Download". Replacing that with "Download the recording you made on 14 September 2026" loses nothing and gains the row identity. The rule is about whether information is lost, not about the attribute. A blanket ban would be the wrong lesson from that commit.
4. **The policy callout is body text in reading order, before the record button.** Not a tooltip, not an info icon, not a modal, not a JS alert.
5. **Drop `role="note"`.** SOLA puts it on the callout (`templates/soapbox_present.mustache:110`). It is a valid ARIA role with thin support and it adds nothing over a real heading plus a paragraph. A real `<h4>` puts the notice in the heading outline, where a screen reader user can find it by jumping headings.
6. **The recording state cell is never empty.** An empty `<td>` is announced as blank, which is ambiguous between "no deletion date" and "not applicable". That ambiguity is the whole reason `attempt_kept` exists as a string rather than rendering `''`. SOLA renders `''` when `expires_at` is 0 (`soapbox_present.php:255-257`), which today can only happen on a malformed row and under D22 becomes the common case.
7. **`download_off_note` is associated with the table by `aria-describedby` on the `<table>`**, so a user does not hunt for a Download link that is not there and find the explanation only afterwards.
8. **Anything that changes without a page load goes in an `aria-live="polite"` region that is in the DOM at page load.** That covers "scoring finished" and a learner deleting their own attempt. A live region injected at the same moment as its content is frequently not announced.
9. **Colour is never the only signal.** A deleted or expired row must not be conveyed by `text-muted` alone. SOLA greys the not assessed row (`templates/soapbox_present.mustache:180`) but carries a badge plus `accesshide` text alongside, which is why it passes. Keep the word.
10. **Ship the guard test.** Commit `2f0b1c48` added `tests/soapbox_not_assessed_a11y_test.php`, 125 lines. PresenterAI's equivalent asserts that no learner facing key appears inside a `title=` attribute in any template; that the recording state cell renders a non empty string in all seven row states; and that `accesshide` is used rather than either Bootstrap name. That last assertion has a known false positive shape, recorded in the same commit: the first version matched the bare words and failed on a comment three lines above the markup, so match a class attribute, not a word.

---

## 12. What the adversarial pass found

The first draft of this design was attacked by a second pass that read the same six SOLA files, the rubric definitions, the lang strings, the browser frame sampler and Moodle core's AI subsystem. Sixteen findings. This section records all of them, including the ones that are not fixed, because a design document that lists only the objections it defeated is less useful than one that lists the objections it could not.

### 12.1 The finding this design cannot answer, and what happened to it

**The gate is pointed at the words. The discrimination is in the marks.**

A learner who uses a wheelchair records an attempt. The vision prompt forbids naming disability, so the model does not name it. It writes what it sees: seated throughout, hands low and often below the frame edge, posture square to the camera, eyes on the lens. Walk that through all five layers of section 4.

- Layer 0: confidence high, zero unusable frames, the sheet is well lit. Passes.
- Layer 1: 45 words, no shared 8 word shingle. Passes.
- Layer 2: contains no hard term, even after the assistive equipment group was added, because the sentence names none of that equipment. Passes.
- Layer 3: contains "hands", "posture", "eyes". Passes.
- Layer 4: the judge is told to **accept** an item that says what the hands, posture and gaze did and what effect that had, "even where the effect is a negative one. Negative is not a reason to reject." This item is exactly that. Passes.

Output: "Your hands stayed low and mostly out of the picture, so your gestures did not register. Try raising them into frame." Alongside it, `Body Language & Gestures: 2`, and under `IMPLEMENTATION-PLAN.md:604` that 2 becomes a gradebook number.

The gate is working perfectly. Five layers, all satisfied, and the result is a disabled learner being told to move differently and marked down when they do not. The same walk holds for a tremor, which the shipped rubric penalises by name as "fidgeting" (`classes/rubric_manager.php:86-87`), for an autistic learner who does not look at lenses, and for facial paralysis against "facial expression that matches what you are saying" (`:96`).

**This is not a phrasing problem and nothing in this document fixes it.** D21 asks how to phrase what the camera saw. The harm is in `classes/rubric_manager.php:80-100` making "open hands that mark structure", "a steady stance", "weight that stays settled" and "looking at the camera lens" into scored criteria, and in plan section 6 turning that score into a grade Soapbox never had. PresenterAI does not make this feature safer than Soapbox. It makes it graded.

It is recorded as **open question 9.17** in DECISIONS.md Part 4, with the recommendation that body language ships unscored in v1. It is not resolved here because it is not a design choice inside D21; it is a product decision about what the activity marks, and inventing an answer would be the thing this document is supposed to stop.

The related finding, that there is no learner opt out and the machinery for one already exists and is free, is **open question 9.18**.

### 12.2 What changed in response

| # | Finding | Change |
|---|---|---|
| F2 | No learner opt out from body language assessment, and `classes/rubric_manager.php:255-257` plus `compute_overall()` already make one free | Escalated as open question 9.18, with a recommendation to ship it and two conditions: frames not uploaded when ticked, and the page stating the score is computed over the remaining criteria |
| F4 | The first draft stripped the explanation and kept the mark | A gate rejection on a visual criterion now sets `assessed = false`, so the criterion leaves both sums. Section 4.7 |
| F5 | The deny list hard rejects `hair` and `clothing`, which the shipped rubric scores by name as distracting habits (`classes/rubric_manager.php:86-87`) | "hair or clothing" is struck from the seed criterion text. A habit involving a learner's own hair or clothing is too close to appearance to be worth a mark and would fire disproportionately on textured hair, headwear and religious dress. "Playing with an object" stays, because an object is not a person. Section 13 |
| F6 | The layer 4 default was off in English, which is where the euphemism failure lives | `visualsummaryjudge` defaults on everywhere. Section 4.6 |
| F7 | Layer 0 was entirely model self report, so it gated nothing an adversarial failure would trip | A server side per cell luminance and variance check on the contact sheet, computed before the vision call. Plus "six moments" and a ban on "throughout" and "for most of" in both prompts. Section 4.2 |
| F8 | Fail closed on an unreachable judge silently degraded every attempt scored during an outage, permanently | A rejection and an outage are now separate paths and separate events. An outage throws so the adhoc task requeues. Section 4.7 |
| F9 | On the core AI route there is no schema, so layer 1's guarantee is unenforceable | Combined with F10 below: the core AI route does not carry visual evidence at all. Section 3.6 |
| F10 | `core_ai\manager` stores the full prompt forever in `ai_action_generate_text`, outside every clock D5 built, and there is no `ai/db/` directory in 4.5 to prune it | The raw note is never put in a prompt sent through the core AI route. Section 3.6 |
| F11 | The rejection event carried no text, making the stated tuning plan impossible | `presenterai_gatelog`, staff only, 7 day clock, declared in privacy, named on the settings page. Section 5.3. Who reads it is open question 9.19 |
| F12 | The raw note is justified by readers who may not exist, and the export route is near vacuous within 30 days | `storevisualevidence` site setting, default 1, which is what D21 decided. A site with no graders turns it off. Section 5.2, and what Saylor sets is open question 9.19 |
| F13 | `current_language()` does not work inside an adhoc task and would deterministically give learner B learner A's language | Resolved from the loaded user record as `$user->lang ?: $CFG->lang`. Section 3.4 |
| F14 | The not assessed string tells a rate limited learner their camera could not be read, then tells them to do the thing they already did | Split into `visual_not_assessed` and `visual_not_analysed`, the second carrying no advice. Sections 6 (a2) and 9.6 |
| F15 | Two whole categories missing from the deny list, assistive equipment and most headwear, and `frame` was simultaneously a deny term and a layer 3 observable | Both groups added, and the `frame` collision resolved in favour of the observable with `chair back`, `headrest` and `armrest` carrying the assistive case. Section 4.4 |
| F16 | "Say only that the frame is shared" manufactured a euphemism the gate is built to miss | The vision prompt now says to ignore any other person completely. Section 3.3 |

Two smaller corrections also landed, as C6 and C7 in DECISIONS.md: the learner facing channel already exists through the criterion feedback strings, and there is no `json_parser` class in SOLA.

### 12.3 What is accepted as a known limitation

These are not fixed and are not going to be. They are here so nobody discovers them later and thinks they were missed.

**Layer 2 and layer 3 exist in English only at v1.** Forty five locales get layer 1 and layer 4 and nothing else. Layer 4 is the honest mitigation and it is why the setting defaults on, but "the deny list covers this" is a statement that is true on one of forty six sites' worth of languages.

**Layer 2 will produce false positives and there is no way to know the rate without running it.** "Glasses" is in "you reached for your glass of water". "Wall" is in "you drifted toward the wall as you spoke". The tiering makes the setting words survivable and does nothing for the hard ones. The first fifty runs need reading, which is what `presenterai_gatelog` is for and which needs someone to read it.

**The deny list will always be incomplete.** Two whole categories were found missing by one adversarial read. The number of categories nobody has thought of yet is not zero, and the design cannot tell you what they are. This is the strongest argument for layer 4 and the reason it is not optional in practice.

**Layer 0 is only partly evidence.** The luminance check catches darkness. Blur, distance and crop are still the model's own self report, and a model confident enough to describe posture from six unreadable thumbnails will report `high` and `0`. There is no cheap objective signal for those three and none is proposed.

**Six stills across twelve minutes do not support a claim about a talk.** The prompts now forbid "throughout" and "for most of", and a model that wants to say it will find another way. The summary is a claim about six moments presented next to a criterion score that reads as a claim about a presentation, and no wording fully closes that gap.

**On S3 the download switch is enforced at issue time only.** Turning it off leaves a window equal to the TTL, 900 seconds, for links already handed out. A presigned URL is a bearer token. The admin help says so; the limitation is real.

**The plugin cannot see an S3 lifecycle rule and cannot override one.** `s3lifecycledays` is an admin declaration. `reconcile_media` repairs the database after the fact, on a sample, which means a learner can see "kept until deleted" against an object the bucket removed last night, until the sample reaches that row.

**The settings page warning for the deletion on plus download off combination cannot update live.** It reads saved values at page render. An admin mid edit sees the stale state.

**The privacy export hands a learner the raw note**, which is the exact text D21 decided was too raw to show them. The product position is "we show you a checked version, and the route to the unchecked one is a data request". Defensible in law, uncomfortable as a product, and part of why `storevisualevidence` exists.

**On the core AI scoring route there is no body language feedback at all**, by decision, for the two reasons in section 3.6. A site that configures core AI and nothing else gets the five spoken criteria and the `visual_not_analysed` string. That is a real reduction in what the settings page can promise and it is stated at the point of choosing.

**Every remedy in this document that routes to a staff member routes to nobody at Saylor.** The grading screen, the raw note capability, the gate rejection report and the admin health line all assume a reader who holds `teacher`, `editingteacher` or `manager` in a course. At Saylor nobody does (`IMPLEMENTATION-PLAN.md:612`). Open question 9.19 exists to force an answer rather than let the design keep quietly assuming one.

---

## 13. What this asks of the implementation plan

| Where | Change |
|---|---|
| `IMPLEMENTATION-PLAN.md:105` | `storedattempts` DEFAULT `2` to `0`, and drop the `max(1, ...)` floor at `classes/task/soapbox_cleanup.php:93`. Keep forever is not honoured while pruning defaults on |
| Section 3.3 fields | Add `mediagonereason` char(20) NOT NULL DEFAULT '' |
| Section 3.5 fields | Add `visualsummary` text NOT NULL="false" to `presenterai_score` |
| `IMPLEMENTATION-PLAN.md:212` | Replace index `statusexp (status, expiresat)` with `expiresat` plus `(status, timecreated)` |
| New table | `presenterai_gatelog`, staff only, 7 day clock (section 5.3). This makes it nine tables, not eight |
| Section 4.5 | Add the grace floor, `--notify` and `--from=none` to `cli/apply_retention.php`, and the "no implicit change ever shortens" principle |
| Section 4.5 | Add `allowlearnerdownload`, `deletewarndays`, `storevisualevidence`, `visualsummaryjudge`, `s3lifecycledays` and `backupmaxmediabytes` to the settings list, alongside the `visualdatadays` section 3.3 already requires |
| Section 4.5, new | Add the `reconcile_media` scheduled task and the explicit S3 orphan deletion obligation on instance deletion, course deletion and course reset |
| Section 5.8 | Strike the claim that `json_parser` already exists. It is a class phase 3 will write |
| Section 5.8 / new | State that the core AI scoring route carries no visual evidence and produces no body language feedback |
| Section 6 | Add the rule that a gate rejection sets a visual criterion to `assessed = false` |
| Section 9.1 | Now decided. See D21 |
| Section 9.2 | Now decided: 0, keep forever. Saylor sets 7 explicitly on both sites, which matches carried decision 7 |
| Section 9, new | Add 9.17 to 9.22 from DECISIONS.md Part 4 |
| Phase 1 list (`:752`) | `delete_recording` needs its two capabilities and the media only semantics. Add the six capabilities from section 7.3 |
| Phase 3 list (`:760`) | Add `summary_gate`, the per language deny list data files, the judge call, the luminance check, `visual_summary_rejected`, `visual_summary_judge_unavailable` and the CI fixture set from section 4.1 |
| Rubric seed data | Strike "hair or clothing" from the Body Language & Gestures criterion description carried from `classes/rubric_manager.php:86-87` |

---

## 14. What could not be established

- **Whether the Saylor bucket `saylor-soapbox-prod` is versioned, and whether the IAM policy grants `s3:GetBucketVersioning` or `s3:GetLifecycleConfiguration`.** This decides whether the storage self test can detect either condition or must rely entirely on the admin declaration. Plan section 9.16 records bucket, prefix, region, retention and `allowstealth`, but not the policy.
- **Whether a bucket lifecycle rule currently exists on that bucket and at what age.** Plan section 9.3 is still open. Section 9.16 notes the bucket is dedicated and empty, which makes removing any rule at cutover cheap, but does not say whether one is there.
- **Whether GD is guaranteed present**, which the layer 0 luminance check needs. It must degrade to a skip with a debugging note rather than a fatal, and the storage self test must report when it is unavailable.
- **The core Moodle line references for file deletion timing and trash cleanup** are carried from `IMPLEMENTATION-PLAN.md` section 4.5. Core was not re-read to verify those line numbers. The consequence for learner facing wording holds either way: on the filesystem backend, deletion is honestly "unreachable within seconds, gone from disk within about a day", not "gone".
- **The false positive rate of the layer 2 deny list.** It cannot be estimated from the code and needs real output. Section 5.3 exists to make measuring it possible.
