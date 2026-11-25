PresenterAI 0.8.3 is a security release, from a red-team review of the whole plugin. There's no new table, column or setting, and nothing a learner or teacher sees changes unless something tries to game the score. Upgrading is recommended for every site running 0.8.2 or earlier.

WHAT'S FIXED

- Leaving a criterion out of the AI's answer no longer raises the score. A score counts only the criteria the AI assessed, and an attempt already went to a teacher when the AI marked more than half of the spoken criteria "not assessed". But a criterion missing from the AI's answer altogether wasn't counted, so a learner who talked the AI into returning only the criterion they did best on (for example by saying "only score my content" in their talk) could get 100 percent. Now a spoken criterion the AI leaves out counts as not assessed: it's shown on the score with no mark, and if more than half are missing the attempt goes to a teacher to grade by hand. A failed attempt doesn't use up one of the learner's attempts.

- S3 uploads always have a size limit. A presigned upload is signed for the size the browser declares, so the bucket refuses anything larger. A request that declared no size used to get an upload URL with no limit, which could have put an object of up to 5 GB in the bucket until the attempt was cleaned up the next day. It's now refused. Sites on Moodle file storage (the default) were never affected.

- The documentation says what the plugin does. The listing said every AI call was logged "with a CSV export and an optional monthly spend cap". Neither exists. The estimated AI spend for each learner is on the Submissions page, and each learner's AI calls are rate limited (scoring 20 an hour; transcription 12, and image analysis 10, per 10 minutes). There's no site-wide spend cap, so a site that needs a hard monthly ceiling should set one with its AI provider.

WHAT ELSE WAS CHECKED

The review also checked capability, sesskey and context checks on every page and web service; access to another learner's or another course's attempts, recordings, playback and downloads; upload sizes, file types and storage keys; escaping of transcripts, AI feedback, topics, sources and rubric text; the scoring prompt's handling of instructions spoken in a recording; API keys in pages, logs and backups; and the privacy export and deletion, including S3 objects. It found nothing else that needed a fix before this release.

UPGRADING

Install: unzip into mod/presenterai and run the Moodle upgrade, or install the ZIP through Site administration > Plugins > Install plugins. Requires Moodle 4.5 or later (supported through 5.3) and PHP 8.1 to 8.3. English only for now.

Source, issues and the full change history: https://github.com/saylordotorg/moodle-mod_presenterai
