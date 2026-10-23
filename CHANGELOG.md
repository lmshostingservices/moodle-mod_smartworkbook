# Changelog

## 1.0.78 (2026-10-23)

- Send the LMS Labs grading-check API key in the `X-Api-Key` header instead of the URL.
- Preserve all backup, privacy, schema, access-control, autosave and grading fixes supplied in 1.0.77.

## 1.0.77 (2026-10-23)

### Critical fixes
- **Backup/duplicate/Sharing Cart crash (HTTP 500 / "undefined" popup).** `backup_smartworkbook_activity_task`
  declared a *static* `define_settings()`, a PHP compile-time fatal error. Every operation that backs up a course
  or activity containing a Smart Workbook failed with a blank HTTP 500: Duplicate, Sharing Cart, course
  backup/import/copy, automated backups and the recycle bin. The task now implements `define_my_settings()` /
  `define_my_steps()` as required by `backup_activity_task`.
- **Cross-course question overwrite (stored XSS).** `save_questions`, `save_image` and `save_video` accepted any
  question id. They now only update questions belonging to the workbook whose capability was checked.
- **Student answers executed as HTML in the teacher marking consoles.** Student answers, student names, AI
  comments/confidence and server error text are now HTML-escaped.

### Security / integrity
- `save_mark` verifies the submission belongs to the workbook.
- `ajax.php` now calls `require_login($course, false, $cm)` for every action (hidden activities, availability
  restrictions, suspended enrolments and other courses are enforced).
- `view.php?preview=1` is limited to users with `mod/smartworkbook:manage`.
- Re-answer mode: the server only accepts changes to questions the teacher flagged.
- `generate_model_answers` only updates questions in this workbook; tolerates missing `answers`.
- DOCX parsing: per-request temp directory (no /tmp leak), 50 MB `document.xml` limit, `LIBXML_NONET`.

### Grading
- AI re-marking no longer discards teacher overrides: totals are recomputed from all marks
  (`smartworkbook_recalc_submission_totals()`), shared with manual marking.
- AI marking refuses submissions whose grades are already released.
- Platform passing-percentage override now updates the grade item (previously wrote to a non-existent
  `smartworkbook.gradepass` column and silently failed).

### Robustness
- `convert` refuses to replace questions once students have answered (prevents orphaned answers) and runs in a
  database transaction.
- `delete_question` also removes that question's responses and marks.
- Concurrent autosave/submit/save_meta no longer throw unique-index write errors.
- Submit flushes pending autosaves first, so the last typed answer is never lost.

### Moodle compliance
- `index.php` crashed ("Cannot instantiate abstract class course_module_instance_list_viewed"). Added
  `\mod_smartworkbook\event\course_module_instance_list_viewed` and `course_module_viewed` (view logging).
- Privacy API: replaced the incorrect `null_provider` with a full provider (metadata incl. external AI Grader
  service, export, delete for user / users / context).
- Removed the false `FEATURE_COMPLETION_HAS_RULES` claim (no custom completion rules exist); declared
  `FEATURE_MOD_PURPOSE` (assessment).
- `view.php` uses a server-side `redirect()` for teachers and checks `mod/smartworkbook:view`.
- `db/upgrade.php`: new step reconciles existing tables, fields and indexes with `db/install.xml` (repairs schema
  drift on long-lived installs; idempotent).
- AMD module rebuilt; unused `core/ajax` dependency and stray `amd/build/smartworkbook.js` removed.

Tested on Moodle 4.5.15, 5.0.11 and 5.1.8 (PostgreSQL 16, PHP 8.3): fresh install, upgrade from 1.0.76,
course page/editing, activity chooser, duplicate, course backup + restore with user data, privacy export/delete,
student and teacher AJAX flows.

## 1.0.76 (2026-10-15)

- Restore release evidence with version-aligned archive metadata and an immutable new release identity.
- Preserve all runtime code from the previously distributed 1.0.75 archive.
