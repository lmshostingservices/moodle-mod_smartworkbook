<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AI Smart Workbook - Version information.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_smartworkbook';
$plugin->version   = 2026072300236;
$plugin->requires  = 2022112800;
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0.74'; // FIX-TABLE-MERGE (v1.0.65): Fixed DOCX conversion producing raw HTML dump instead of interactive questions. Root cause: html-type blocks (Word tables) were unconditionally stored as dochtml, so API-extracted questions were buried at the end via the safety-net loop. Fix: for each html block, strip tags + normalise text, check whether any API question text is a substring — if matches found, insert those as interactive text/long/heading questions in document order instead of a dochtml block; pure display tables with no matching questions still become dochtml. // FIX-GEN-MODEL-ANSWERS (v1.0.64): Fixed "Generate Model Answers" failing every time. Root cause: workbooks built via DOCX parsing store all blocks as dochtml/heading types, which are filtered out — leaving q_list empty. Server returned {success:true,answers:[]} and PHP's empty([]) check misfired as failure. Fix: pre-flight check in PHP returns a clear "No answerable questions found" message when q_list is empty; response check now only fails on success=false (not on empty answers array). // SECTION-ICONS (v1.0.38): Added beautiful section icon badges (Practical, Learning Intention, Success Criteria, Recipe, Group Task, Extension, Article, Prior Knowledge, Wordsearch, Presentation, Resources, Lesson Plan, Worksheet, Task, Question, Stage, Topic, Assessment + more) that auto-detect from heading text and render inline SVG icons in the Moodle primary theme colour. New .sw-section-block/.sw-section-badge CSS for a card-based section header aesthetic. Multi-column structured table builder + student view (from v1.0.37). // STRUCTURED-TABLE (v1.0.37): Added multi-column structured table question type. Teacher can define column headers, mark cells as editable vs fixed, and set header background colour. Student view renders a polished table (.sw-stable*) with auto-save per cell (debounced 800 ms) and full saveAll() support. AMD build files updated with bindStructuredTableAutoSave(). // FIX-QINDEX (v1.0.10): Fixed question numbering in student view — q_index was incremented for heading-type rows before the heading check and continue, causing every real question number to be offset by the number of headings above it (e.g. Q1→Q3, Q4→Q7). Moved q_index++ to after the heading branch so only non-heading items are counted. // FIX-UI (v1.0.9): Fixed "AI Mark All Submitted" button text cutoff (added white-space:normal + explicit line-height). Fixed "Generate Model Answers" not reloading page on success — now auto-reloads after 800ms instead of showing a manual "reload" alert. // FIX-UI (v1.0.9): Fixed "AI Mark All Submitted" button text cutoff (added white-space:normal + explicit line-height). Fixed "Generate Model Answers" not reloading page on success — now auto-reloads after 800ms instead of showing a manual "reload" alert. // FIX-CREDENTIALS (v1.0.8): Fixed smartworkbook_get_api_credentials() — was using table_exists('local_aiconfig') which always returns false (no such table exists; local_aiconfig uses config_plugins). Also used wrong key names site_id/api_key instead of siteid/apikey. Result: credentials were always empty, every AI action failed. Now reads get_config('local_aiconfig','siteid') and get_config('local_aiconfig','apikey') directly. // FIX-GRADEPASS (v1.0.7): Added gradepass field to mod_form.php (Grade section), data_preprocessing() loads current gradepass from the grade item, validation() enforces 0..grademax range, smartworkbook_grade_item_update() in lib.php now persists gradepass to the gradebook. Fixes "This activity does not have a valid grade to pass set" error in Completion conditions. No DB schema changes. // FIX-LANGSTRING-GRADE (v1.0.6): Added $string['grade'] = 'Grade' to lang/en/smartworkbook.php. The mod_form.php grade section header called get_string('grade') with no component — Moodle displayed [[grade]] on the activity settings page because the string was absent from the plugin lang file. No DB schema changes. // FIX-EMPTY-CLASSES (v1.0.5): Removed empty classes/ and classes/event/ directories. Moodle's plugin validator rejects ZIPs that contain directory entries with no files inside — throws "Extracted file not found" on install. The plugin has no custom event classes (index.php uses core \core\event\course_module_instance_list_viewed). Savepoint 2026070900105. Added require_once(filelib.php) to ajax.php — \curl class used in convert, ai_mark and generate_model_answers actions is defined in filelib.php which is NOT auto-loaded in the Moodle AJAX bootstrap. Without this, all three actions throw a fatal "Class not found" error. Savepoint 2026070900104.
$plugin->supported = [401, 500];
