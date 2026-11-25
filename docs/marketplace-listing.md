PresenterAI is a Moodle activity in which a learner records a video or audio presentation in the browser, optionally presents a PDF slide deck while speaking, and receives a transcript, a rubric score and written feedback on every criterion. It is for the practice that is hard to give at scale: a learner who may have nobody to ask gets specific, usable feedback on how they spoke and what to change next time.

Watch a 2-minute demo of version 0.8 on Saylor University's development site: https://youtu.be/LkVn4rngX7I

HOW IT WORKS

1. The learner opens the activity, picks a topic if the teacher offered a choice, and optionally uploads a PDF slide deck that is rendered in the page.
2. They record in the browser with no plugin or app. A timer shows the minimum and maximum length the teacher set, and each slide advance is recorded against the elapsed time.
3. The recording uploads in chunks. In the background it is transcribed and scored against a rubric.
4. The learner reads an overall comment, a score, feedback for each criterion that names something observed and one concrete action, and a few things to do differently next time. Video attempts can also get feedback on body language and slide design.
5. Playback shows the recording with the slides re-synced beside it, including when seeking. The score reaches the Moodle gradebook and a teacher can override it per criterion.

WHAT TEACHERS GET

- Rubric presets or a custom course rubric, plus speaking levels for English learners.
- Attempt limits. A failed AI scoring run does not use up an attempt, and a teacher can grade by hand or rescore from the grading page.
- An optional "review before release" setting (off by default) that holds the AI's score and feedback until a teacher has checked it.
- Nothing is assessed from evidence that doesn't exist. If the visual evidence is too weak to judge body language, those criteria are removed from the score and the learner is told why.

AI AND PRIVACY

- Choose Moodle's core AI subsystem or Claude, OpenAI or Gemini configured in the plugin, plus a Whisper-compatible speech-to-text endpoint. Core AI is text-only, so on that route there is no body-language or slide-design feedback and no still frames are taken from recordings.
- All AI work runs in scheduled or ad hoc tasks, never in the learner's web request. Every call is logged with provider, model, token counts and estimated cost, and the Submissions page shows each learner's estimated spend. Each learner's AI calls are rate limited per hour; there is no site-wide spend cap, so set a monthly limit with your AI provider if you need one.
- Store recordings in Moodle file storage (the default, carried by course backup) or an S3-compatible bucket with presigned uploads. Automatic deletion after a retention period you set is optional; the transcript, score and feedback are kept.
- The privacy API covers every item stored: export on a subject access request, deletion on an erasure request, and removal with the course or activity.
- Media leaves your site only for the storage, speech-to-text and AI providers you choose. Nothing is sent to Saylor.

MOVING FROM SOLA'S SOAPBOX

If your site uses the Soapbox feature of the SOLA course assistant plugin (local_ai_course_assistant), an importer included from 0.5.0 copies its assignments, topics, attempts, transcripts and scores into PresenterAI activities, course by course. It is a command line tool: it does a dry run first, verifies its work and can be undone. Recordings kept in S3 aren't copied: the new activities point at the same objects. It needs SOLA 7.12.1 or later on the same site and never writes to SOLA's tables. PresenterAI itself works without SOLA.

REQUIREMENTS

Moodle 4.5 or later, PHP 8.1 to 8.3, a modern browser with MediaRecorder, and Ghostscript for rendering slide decks (core's existing pathtogs setting). ffmpeg is optional and lets older or unusual recordings over OpenAI's 25 MB transcription limit still be transcribed. English only for now.

STATUS

Version 0.8.3 is a beta (0.4.0 was the first public release). 0.5.0 added the importer for SOLA's Soapbox feature. 0.8.3 is a security release: a learner can no longer raise their score by getting the AI to leave rubric criteria out of its answer, and S3 uploads always carry a size limit. Developed at Saylor University and open source under the GNU GPL v3 or later. Source, issues and releases: https://github.com/saylordotorg/moodle-mod_presenterai
