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
 * AI Smart Workbook - Library functions.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function smartworkbook_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        default: return null;
    }
}

function smartworkbook_add_instance($data, ?object $mform = null) {
    global $DB;

    $data->timecreated  = time();
    $data->timemodified = time();
    $data->status       = 'setup';

    $data->id = $DB->insert_record('smartworkbook', $data);

    smartworkbook_grade_item_update($data);

    return $data->id;
}

function smartworkbook_update_instance($data, ?object $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    $DB->update_record('smartworkbook', $data);

    smartworkbook_grade_item_update($data);

    return true;
}

function smartworkbook_delete_instance($id) {
    global $DB;

    if (!$wb = $DB->get_record('smartworkbook', ['id' => $id])) {
        return false;
    }

    $questions = $DB->get_records('smartworkbook_question', ['workbookid' => $id], '', 'id');
    foreach ($questions as $q) {
        $DB->delete_records('smartworkbook_response', ['questionid' => $q->id]);
    }

    $submissions = $DB->get_records('smartworkbook_submission', ['workbookid' => $id], '', 'id');
    foreach ($submissions as $s) {
        $DB->delete_records('smartworkbook_mark', ['submissionid' => $s->id]);
    }

    $DB->delete_records('smartworkbook_question',   ['workbookid' => $id]);
    $DB->delete_records('smartworkbook_submission', ['workbookid' => $id]);
    $DB->delete_records('smartworkbook',            ['id' => $id]);

    smartworkbook_grade_item_delete($wb);

    return true;
}

function smartworkbook_grade_item_update($instance, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = [
        'itemname'  => clean_param($instance->name, PARAM_TEXT),
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => 100,   // Always 100 — raw marks are scaled to a percentage before writing to the gradebook.
        'grademin'  => 0,
    ];

    // Persist grade-to-pass when provided (enables "Passing grade" completion condition).
    if (isset($instance->gradepass)) {
        $item['gradepass'] = grade_floatval(unformat_float($instance->gradepass));
    }

    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/smartworkbook',
        $instance->course,
        'mod',
        'smartworkbook',
        $instance->id,
        0,
        $grades,
        $item
    );
}

function smartworkbook_grade_item_delete($instance) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update(
        'mod/smartworkbook',
        $instance->course,
        'mod',
        'smartworkbook',
        $instance->id,
        0,
        null,
        ['deleted' => 1]
    );
}

function smartworkbook_update_grades($instance, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($userid) {
        $submission = $DB->get_record('smartworkbook_submission', [
            'workbookid' => $instance->id,
            'userid'     => $userid,
        ]);

        if ($submission && $submission->grade !== null) {
            $grade = new stdClass();
            $grade->userid    = $userid;
            $grade->rawgrade  = $submission->grade;
            smartworkbook_grade_item_update($instance, $grade);
        } else if ($nullifnone) {
            $grade = new stdClass();
            $grade->userid   = $userid;
            $grade->rawgrade = null;
            smartworkbook_grade_item_update($instance, $grade);
        }
    } else {
        smartworkbook_grade_item_update($instance);
    }
}

function smartworkbook_extend_navigation(navigation_node $navnode, $course, $module, $cm) {
}

function smartworkbook_extend_settings_navigation($settings, $navref) {
}

function mod_smartworkbook_get_fontawesome_icon_map() {
    return [
        'mod_smartworkbook:icon' => 'fa-book-open',
    ];
}

/**
 * Get site_id and api_key from local_aiconfig (if installed).
 *
 * local_aiconfig stores credentials in Moodle's config_plugins table under
 * component 'local_aiconfig' with keys 'siteid' and 'apikey' (no underscore).
 * There is NO custom DB table — table_exists('local_aiconfig') always returns
 * false and must NOT be used here.
 */
function smartworkbook_get_api_credentials() {
    // Primary: read from central local_aiconfig plugin (canonical key names).
    $site_id = trim((string)(get_config('local_aiconfig', 'siteid') ?: ''));
    $api_key = trim((string)(get_config('local_aiconfig', 'apikey') ?: ''));

    // Fallback: plugin-level overrides (support both underscore and no-underscore variants).
    if (empty($site_id)) {
        $site_id = trim((string)(get_config('smartworkbook', 'siteid') ?: get_config('smartworkbook', 'site_id') ?: ''));
    }
    if (empty($api_key)) {
        $api_key = trim((string)(get_config('smartworkbook', 'apikey') ?: get_config('smartworkbook', 'api_key') ?: ''));
    }

    return [$site_id, $api_key];
}

/**
 * Calculate the percentage grade for a submission.
 */
function smartworkbook_calc_grade($total_marks, $max_marks, $grade_max = 100) {
    if (empty($max_marks) || $max_marks <= 0) {
        return 0;
    }
    return round(($total_marks / $max_marks) * $grade_max, 5);
}

// ============================================================
// Section icon / badge helpers (used by view.php, teacher.php, ajax.php)
// ============================================================

/**
 * Detect section type keyword from a plain-text heading string.
 * Returns a slug like 'practical', 'learning-intention', etc.
 */
function smartworkbook_section_type(string $text): string {
    $t = strtolower(trim(strip_tags($text)));
    $map = [
        'learning intention'      => 'learning-intention',
        'success criteria'        => 'success-criteria',
        'practical'               => 'practical',
        'taste test'              => 'practical',
        'cooking activity'        => 'practical',
        'recipe'                  => 'recipe',
        'ingredients'             => 'recipe',
        'method'                  => 'recipe',
        'group task'              => 'group-task',
        'extension task'          => 'extension',
        'extension'               => 'extension',
        'article activity'        => 'article',
        'article'                 => 'article',
        'reading'                 => 'article',
        'prior knowledge'         => 'prior-knowledge',
        'wordsearch'              => 'wordsearch',
        'word search'             => 'wordsearch',
        'powerpoint'              => 'presentation',
        'presenting to the class' => 'presentation',
        'presentation'            => 'presentation',
        'teacher resource'        => 'resources',
        'student resource'        => 'resources',
        'lesson resource'         => 'resources',
        'real world connection'   => 'resources',
        'resources'               => 'resources',
        'lesson plan'             => 'lesson-plan',
        'lesson overview'         => 'lesson-overview',
        'lesson summary'          => 'lesson-plan',
        'check your knowledge'    => 'assessment',
        'assessment'              => 'assessment',
        'summative'               => 'assessment',
        'worksheet'               => 'worksheet',
        'annotation'              => 'task',
        'instructions'            => 'task',
        'activity'                => 'task',
        'task'                    => 'task',
        'reflection'              => 'question',
        'discussion'              => 'question',
        'question'                => 'question',
        'supply chain'            => 'stage',
        'stage'                   => 'stage',
        'topic outline'           => 'topic',
        'topic summary'           => 'topic',
        'topic'                   => 'topic',
        'lesson'                  => 'lesson',
        'introduction'            => 'lesson',
        'note'                    => 'note',
        'summary'                 => 'topic',
    ];
    foreach ($map as $keyword => $type) {
        if (strpos($t, $keyword) !== false) {
            return $type;
        }
    }
    return 'default';
}

/**
 * Return an inline SVG string for the given section type.
 * Icons use fill="currentColor" (Heroicons Solid style — flat filled shapes)
 * so they adopt the parent colour (white on primary-coloured badge).
 */
function smartworkbook_section_svg(string $type): string {
    // Heroicons Solid 24x24 — fill="currentColor", no stroke.
    // Matches the flat filled icon style used in Word document section headers.
    $a = 'width="20" height="20" viewBox="0 0 24 24" fill="currentColor"';
    $p = [
        // Target/bullseye → Learning Intention
        'learning-intention' => '<path fill-rule="evenodd" d="M14.615 1.595a.75.75 0 0 1 .359.852L12.982 9.75h7.268a.75.75 0 0 1 .548 1.262l-10.5 11.25a.75.75 0 0 1-1.272-.71l1.992-7.302H3.818a.75.75 0 0 1-.548-1.262l10.5-11.25a.75.75 0 0 1 .845-.143Z" clip-rule="evenodd"/>',
        // Filled check circle → Success Criteria
        'success-criteria'   => '<path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd"/>',
        // Filled fire/flame → Practical cooking activity
        'practical'          => '<path fill-rule="evenodd" d="M12.963 2.286a.75.75 0 0 0-1.071-.136 9.742 9.742 0 0 0-3.539 6.177A7.547 7.547 0 0 1 6.648 6.61a.75.75 0 0 0-1.152-.082A9 9 0 1 0 15.68 4.534a7.46 7.46 0 0 1-2.717-2.248ZM15.75 14.25a3.75 3.75 0 1 1-7.313-1.172c.628.465 1.35.81 2.133 1a5.99 5.99 0 0 1 1.925-3.545 3.75 3.75 0 0 1 3.255 3.717Z" clip-rule="evenodd"/>',
        // Filled beaker/measuring jug → Recipe (matches Word doc measuring jug icon exactly)
        'recipe'             => '<path fill-rule="evenodd" d="M10.5 3.798v5.02a3 3 0 0 1-.879 2.121l-2.377 2.377a9.845 9.845 0 0 1 5.091 1.013 8.315 8.315 0 0 0 5.713.636l.285-.071-3.954-3.955a3 3 0 0 1-.879-2.121v-5.02a23.614 23.614 0 0 0-3 0Zm4.5.138a.75.75 0 0 0 .093-1.495A24.837 24.837 0 0 0 12 2.25a25.048 25.048 0 0 0-3.093.191A.75.75 0 0 0 9 3.936v4.882a1.5 1.5 0 0 1-.44 1.06l-6.293 6.294c-1.62 1.621-.903 4.475 1.471 4.88 2.686.46 5.447.698 8.262.698 2.816 0 5.576-.239 8.262-.697 2.373-.406 3.092-3.26 1.47-4.881L15.44 9.879A1.5 1.5 0 0 1 15 8.818V4.936h.001Z" clip-rule="evenodd"/>',
        // Filled user group → Group Task
        'group-task'         => '<path fill-rule="evenodd" d="M8.25 6.75a3.75 3.75 0 1 1 7.5 0 3.75 3.75 0 0 1-7.5 0ZM15.75 9.75a3 3 0 1 1 6 0 3 3 0 0 1-6 0ZM2.25 9.75a3 3 0 1 1 6 0 3 3 0 0 1-6 0ZM6.31 15.117A6.745 6.745 0 0 1 12 12a6.745 6.745 0 0 1 6.709 7.498.75.75 0 0 1-.372.568A12.696 12.696 0 0 1 12 21.75c-2.305 0-4.47-.612-6.337-1.684a.75.75 0 0 1-.372-.568 6.787 6.787 0 0 1 1.019-4.38Z" clip-rule="evenodd"/><path d="M5.082 14.254a8.287 8.287 0 0 0-1.308 5.135 9.687 9.687 0 0 1-1.706-.57.75.75 0 0 1-.402-.643 3.75 3.75 0 0 1 3.016-3.381l.4.459Zm13.836 0 .4-.459a3.75 3.75 0 0 1 3.016 3.381.75.75 0 0 1-.402.643 9.687 9.687 0 0 1-1.706.57 8.287 8.287 0 0 0-1.308-5.135Z"/>',
        // Filled star → Extension
        'extension'          => '<path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.006 5.404.434c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.434 2.082-5.006Z" clip-rule="evenodd"/>',
        // Filled newspaper → Article Activity
        'article'            => '<path fill-rule="evenodd" d="M4.125 3C3.089 3 2.25 3.84 2.25 4.875V18a3 3 0 0 0 3 3h15a3 3 0 0 1-3-3V4.875C17.25 3.839 16.41 3 15.375 3H4.125ZM12 9.75a.75.75 0 0 0 0 1.5h1.5a.75.75 0 0 0 0-1.5H12Zm-.75-2.25a.75.75 0 0 1 .75-.75h1.5a.75.75 0 0 1 0 1.5H12a.75.75 0 0 1-.75-.75ZM6 12.75a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5H6Zm-.75 3.75a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5H6a.75.75 0 0 1-.75-.75ZM6 6.75a.75.75 0 0 0-.75.75v3c0 .414.336.75.75.75h3a.75.75 0 0 0 .75-.75v-3A.75.75 0 0 0 9 6.75H6Z" clip-rule="evenodd"/><path d="M18.75 6.75h1.875c.621 0 1.125.504 1.125 1.125V18a1.5 1.5 0 0 1-3 0V6.75Z"/>',
        // Filled lightbulb → Prior Knowledge
        'prior-knowledge'    => '<path d="M12 .75a8.25 8.25 0 0 0-4.135 15.39c.686.398 1.115 1.008 1.134 1.623a.75.75 0 0 0 .577.706c.352.083.71.148 1.074.195.323.041.6-.218.6-.544v-4.661a6.714 6.714 0 0 1-.937-.171.75.75 0 1 1 .374-1.453 5.261 5.261 0 0 0 2.626 0 .75.75 0 1 1 .374 1.452 6.712 6.712 0 0 1-.937.172v4.66c0 .327.277.586.6.545.364-.047.722-.112 1.074-.195a.75.75 0 0 0 .577-.706c.02-.615.448-1.225 1.134-1.623A8.25 8.25 0 0 0 12 .75Z"/><path fill-rule="evenodd" d="M9.013 19.9a.75.75 0 0 1 .877-.597 11.319 11.319 0 0 0 4.22 0 .75.75 0 1 1 .28 1.473 12.819 12.819 0 0 1-4.78 0 .75.75 0 0 1-.597-.876ZM9.754 22.344a.75.75 0 0 1 .824-.668 13.682 13.682 0 0 0 2.844 0 .75.75 0 1 1 .156 1.492 15.156 15.156 0 0 1-3.156 0 .75.75 0 0 1-.668-.824Z" clip-rule="evenodd"/>',
        // Filled magnifying glass → Wordsearch
        'wordsearch'         => '<path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 1 0 0 13.5 6.75 6.75 0 0 0 0-13.5ZM2.25 10.5a8.25 8.25 0 1 1 14.59 5.28l4.69 4.69a.75.75 0 1 1-1.06 1.06l-4.69-4.69A8.25 8.25 0 0 1 2.25 10.5Z" clip-rule="evenodd"/>',
        // Filled presentation chart → Presentation
        'presentation'       => '<path fill-rule="evenodd" d="M2.25 2.25a.75.75 0 0 0 0 1.5H3v10.5a3 3 0 0 0 3 3h1.21l-1.172 3.513a.75.75 0 0 0 1.424.474l.329-.987h8.418l.33.987a.75.75 0 0 0 1.422-.474l-1.17-3.513H18a3 3 0 0 0 3-3V3.75h.75a.75.75 0 0 0 0-1.5H2.25Zm17.25 1.5H4.5v10.5a1.5 1.5 0 0 0 1.5 1.5h12a1.5 1.5 0 0 0 1.5-1.5V3.75Zm-11.03 3.22a.75.75 0 0 1 1.06 0l1.72 1.72 3.22-3.22a.75.75 0 1 1 1.06 1.06l-3.75 3.75a.75.75 0 0 1-1.06 0l-1.72-1.72-1.72 1.72a.75.75 0 0 1-1.06-1.06l2.25-2.25Z" clip-rule="evenodd"/>',
        // Filled open book → Resources
        'resources'          => '<path d="M11.25 4.533A9.707 9.707 0 0 0 6 3a9.735 9.735 0 0 0-3.25.555.75.75 0 0 0-.5.707v14.25a.75.75 0 0 0 1 .707A8.237 8.237 0 0 1 6 18.75c1.995 0 3.823.707 5.25 1.886V4.533ZM12.75 20.636A8.214 8.214 0 0 1 18 18.75c.966 0 1.89.166 2.75.47a.75.75 0 0 0 1-.708V4.262a.75.75 0 0 0-.5-.707A9.735 9.735 0 0 0 18 3a9.707 9.707 0 0 0-5.25 1.533v16.103Z"/>',
        // Filled calendar days → Lesson Plan
        'lesson-plan'        => '<path fill-rule="evenodd" d="M6.75 2.25A.75.75 0 0 1 7.5 3v1.5h9V3A.75.75 0 0 1 18 3v1.5h.75a3 3 0 0 1 3 3v11.25a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3V7.5a3 3 0 0 1 3-3H6V3a.75.75 0 0 1 .75-.75Zm13.5 9a1.5 1.5 0 0 0-1.5-1.5H5.25a1.5 1.5 0 0 0-1.5 1.5v7.5a1.5 1.5 0 0 0 1.5 1.5h13.5a1.5 1.5 0 0 0 1.5-1.5v-7.5Zm-6.75 3.75a.75.75 0 1 0-1.5 0 .75.75 0 0 0 1.5 0Zm-3 0a.75.75 0 1 0-1.5 0 .75.75 0 0 0 1.5 0Zm6 0a.75.75 0 1 0-1.5 0 .75.75 0 0 0 1.5 0Z" clip-rule="evenodd"/>',
        // Filled eye → Lesson Overview
        'lesson-overview'    => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path fill-rule="evenodd" d="M1.323 11.447C2.811 6.976 7.028 3.75 12.001 3.75c4.97 0 9.185 3.223 10.675 7.69.12.362.12.752 0 1.113-1.487 4.471-5.705 7.697-10.677 7.697-4.97 0-9.186-3.223-10.675-7.69a1.762 1.762 0 0 1 0-1.113ZM17.25 12a5.25 5.25 0 1 1-10.5 0 5.25 5.25 0 0 1 10.5 0Z" clip-rule="evenodd"/>',
        // Filled document text → Worksheet
        'worksheet'          => '<path fill-rule="evenodd" d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0 0 16.5 9h-1.875a1.875 1.875 0 0 1-1.875-1.875V5.25A3.75 3.75 0 0 0 9 1.5H5.625ZM7.5 15a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 7.5 15Zm.75-6.75a.75.75 0 0 0 0 1.5H12a.75.75 0 0 0 0-1.5H8.25Z" clip-rule="evenodd"/><path d="M12.971 1.816A5.23 5.23 0 0 1 14.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0 0 1 3.434 1.279 9.768 9.768 0 0 0-6.963-6.963Z"/>',
        // Filled clipboard list → Task/Activity
        'task'               => '<path fill-rule="evenodd" d="M7.502 6h7.128A3.375 3.375 0 0 1 18 9.375v9.375a3 3 0 0 0 3-3V6.108c0-1.505-1.125-2.811-2.664-2.94a48.972 48.972 0 0 0-.673-.05A3 3 0 0 0 15 1.5h-1.5a3 3 0 0 0-2.663 1.618c-.225.015-.45.032-.673.05C8.662 3.295 7.554 4.542 7.502 6ZM13.5 3A1.5 1.5 0 0 0 12 4.5h4.5A1.5 1.5 0 0 0 15 3h-1.5Z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M3 9.375C3 8.339 3.84 7.5 4.875 7.5h9.75c1.036 0 1.875.84 1.875 1.875v11.25c0 1.035-.84 1.875-1.875 1.875h-9.75A1.875 1.875 0 0 1 3 20.625V9.375ZM6 12a.75.75 0 0 1 .75-.75h.008a.75.75 0 0 1 0 1.5H6.75A.75.75 0 0 1 6 12Zm2.25 0a.75.75 0 0 1 .75-.75h3.75a.75.75 0 0 1 0 1.5H9a.75.75 0 0 1-.75-.75Zm-2.25 3a.75.75 0 0 1 .75-.75h.008a.75.75 0 0 1 0 1.5H6.75A.75.75 0 0 1 6 15Zm2.25 0a.75.75 0 0 1 .75-.75h3.75a.75.75 0 0 1 0 1.5H9a.75.75 0 0 1-.75-.75Zm-2.25 3a.75.75 0 0 1 .75-.75h.008a.75.75 0 0 1 0 1.5H6.75A.75.75 0 0 1 6 18Zm2.25 0a.75.75 0 0 1 .75-.75h3.75a.75.75 0 0 1 0 1.5H9a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd"/>',
        // Filled chat bubble with question mark → Question/Reflection/Discussion
        'question'           => '<path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0 1 12 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 0 1-3.476.383.39.39 0 0 0-.297.17l-2.755 4.133a.75.75 0 0 1-1.248 0l-2.755-4.133a.39.39 0 0 0-.297-.17 48.9 48.9 0 0 1-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97ZM6.75 8.25a.75.75 0 0 1 .75-.75h9a.75.75 0 0 1 0 1.5h-9a.75.75 0 0 1-.75-.75Zm.75 2.25a.75.75 0 0 0 0 1.5H12a.75.75 0 0 0 0-1.5H7.5Z" clip-rule="evenodd"/>',
        // Filled layers/stack → Stage (matches the tractor icon concept of numbered stages)
        'stage'              => '<path d="M11.644 1.59a.75.75 0 0 1 .712 0l9.75 5.25a.75.75 0 0 1 0 1.32l-9.75 5.25a.75.75 0 0 1-.712 0l-9.75-5.25a.75.75 0 0 1 0-1.32l9.75-5.25Z"/><path d="m3.265 10.602 7.668 4.129a2.25 2.25 0 0 0 2.134 0l7.668-4.13 1.37.739a.75.75 0 0 1 0 1.32l-9.75 5.25a.75.75 0 0 1-.71 0l-9.75-5.25a.75.75 0 0 1 0-1.32l1.37-.738Z"/><path d="m10.933 19.231-7.668-4.13-1.37.739a.75.75 0 0 0 0 1.32l9.75 5.25c.221.12.489.12.71 0l9.75-5.25a.75.75 0 0 0 0-1.32l-1.37-.738-7.668 4.13a2.25 2.25 0 0 1-2.134-.001Z"/>',
        // Filled bookmark square → Lesson/Introduction
        'lesson'             => '<path fill-rule="evenodd" d="M6 3a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3h12a3 3 0 0 0 3-3V6a3 3 0 0 0-3-3H6Zm1.5 1.5a.75.75 0 0 0-.75.75V16.5a.75.75 0 0 0 1.28.53l3.72-3.72 3.72 3.72a.75.75 0 0 0 1.28-.53V5.25a.75.75 0 0 0-.75-.75h-8.5Z" clip-rule="evenodd"/>',
        // Filled folder → Topic/Summary
        'topic'              => '<path d="M19.5 21a3 3 0 0 0 3-3v-4.5a3 3 0 0 0-3-3h-15a3 3 0 0 0-3 3V18a3 3 0 0 0 3 3h15ZM1.5 10.146V6a3 3 0 0 1 3-3h5.379a2.25 2.25 0 0 1 1.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 0 1 3 3v1.146A4.483 4.483 0 0 0 19.5 12h-15a4.483 4.483 0 0 0-3 1.146Z"/>',
        // Filled document → Note
        'note'               => '<path fill-rule="evenodd" d="M5.625 1.5H9a3.75 3.75 0 0 1 3.75 3.75v1.875c0 1.036.84 1.875 1.875 1.875H16.5a3.75 3.75 0 0 1 3.75 3.75v7.875c0 1.035-.84 1.875-1.875 1.875H5.625a1.875 1.875 0 0 1-1.875-1.875V3.375c0-1.036.84-1.875 1.875-1.875ZM9.75 17.25a.75.75 0 0 0-1.5 0V18a.75.75 0 0 0 1.5 0v-.75Zm2.25-3a.75.75 0 0 1 .75.75V18a.75.75 0 0 1-1.5 0v-3a.75.75 0 0 1 .75-.75Zm3.75-1.5a.75.75 0 0 0-1.5 0V18a.75.75 0 0 0 1.5 0v-4.5Z" clip-rule="evenodd"/><path d="M14.25 5.25a5.23 5.23 0 0 0-1.279-3.434 9.768 9.768 0 0 1 6.963 6.963A5.23 5.23 0 0 0 16.5 7.5h-1.875a.375.375 0 0 1-.375-.375V5.25Z"/>',
        // Filled clipboard check → Assessment
        'assessment'         => '<path fill-rule="evenodd" d="M7.502 6h7.128A3.375 3.375 0 0 1 18 9.375v9.375a3 3 0 0 0 3-3V6.108c0-1.505-1.125-2.811-2.664-2.94a48.972 48.972 0 0 0-.673-.05A3 3 0 0 0 15 1.5h-1.5a3 3 0 0 0-2.663 1.618c-.225.015-.45.032-.673.05C8.662 3.295 7.554 4.542 7.502 6ZM13.5 3A1.5 1.5 0 0 0 12 4.5h4.5A1.5 1.5 0 0 0 15 3h-1.5Z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M3 9.375C3 8.339 3.84 7.5 4.875 7.5h9.75c1.036 0 1.875.84 1.875 1.875v11.25c0 1.035-.84 1.875-1.875 1.875h-9.75A1.875 1.875 0 0 1 3 20.625V9.375Zm9.586 4.594a.75.75 0 0 0-1.172-.938l-2.476 3.096-.908-.907a.75.75 0 0 0-1.06 1.06l1.5 1.5a.75.75 0 0 0 1.116-.062l3-3.75Z" clip-rule="evenodd"/>',
        // Filled information circle → Default
        'default'            => '<path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm8.706-1.442c1.146-.573 2.437.463 2.126 1.706l-.709 2.836.042-.02a.75.75 0 0 1 .67 1.34l-.04.022c-1.147.573-2.438-.463-2.127-1.706l.71-2.836-.042.02a.75.75 0 1 1-.671-1.34l.041-.022ZM12 9a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Z" clip-rule="evenodd"/>',
    ];
    $inner = $p[$type] ?? $p['default'];
    return '<svg ' . $a . '>' . $inner . '</svg>';
}

/**
 * Extract an 11-char YouTube video ID from any YouTube URL variant.
 * Returns null when the URL is not a recognisable YouTube link.
 */
function smartworkbook_youtube_id(string $url): ?string {
    if (preg_match(
        '/(?:youtube\.com\/(?:watch\?(?:[^&\s]*&)*v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_\-]{11})/',
        $url, $m
    )) {
        return $m[1];
    }
    return null;
}

/**
 * Build the full section badge HTML block.
 * $plain_text = plain text of the heading title (for type detection)
 * $display_html = HTML to show inside the badge (may contain <strong>, <em> etc.)
 */
function smartworkbook_section_badge_html(string $plain_text, string $display_html): string {
    $type = smartworkbook_section_type($plain_text);
    $svg  = smartworkbook_section_svg($type);
    return '<div class="sw-section-badge" data-swtype="' . htmlspecialchars($type, ENT_QUOTES | ENT_HTML5) . '">'
         . '<span class="sw-section-icon">' . $svg . '</span>'
         . '<span class="sw-section-title-text">' . $display_html . '</span>'
         . '</div>';
}
