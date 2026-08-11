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
 * AI Smart Workbook - Main view page (student).
 * v1.0.1 — re-answer flow, table qtype renderer.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm       = get_coursemodule_from_id('smartworkbook', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/smartworkbook/view.php', ['id' => $id]);
$PAGE->set_title(format_string($workbook->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->requires->css('/mod/smartworkbook/styles.css');

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$can_grade  = has_capability('mod/smartworkbook:grade', $context);
$can_manage = has_capability('mod/smartworkbook:manage', $context);
$can_submit = has_capability('mod/smartworkbook:submit', $context);

$preview = optional_param('preview', 0, PARAM_INT);

echo $OUTPUT->header();

// Teacher sees management dashboard (unless previewing as student)
if ($can_manage && !$preview) {
    echo '<script>window.location.href="' . (new moodle_url('/mod/smartworkbook/teacher.php', ['id' => $id]))->out(false) . '";</script>';
    echo '<p>' . get_string('teacherdashboard', 'smartworkbook') . ' - <a href="' .
         (new moodle_url('/mod/smartworkbook/teacher.php', ['id' => $id]))->out() . '">' .
         get_string('teacherdashboard', 'smartworkbook') . '</a></p>';
    echo $OUTPUT->footer();
    exit;
}

// Students: check if published (preview bypasses this gate)
if ($workbook->status !== 'published' && !$preview) {
    echo $OUTPUT->box(get_string('notyetpublished', 'smartworkbook'), 'generalbox notifymessage');
    echo $OUTPUT->footer();
    exit;
}

// Load questions
$questions = $DB->get_records('smartworkbook_question', ['workbookid' => $workbook->id], 'sortorder ASC');

if (empty($questions)) {
    echo $OUTPUT->box(get_string('notyetpublished', 'smartworkbook'), 'generalbox notifymessage');
    echo $OUTPUT->footer();
    exit;
}

// In preview mode all submission state is suppressed — teacher sees blank read-only form
if ($preview) {
    $submission         = null;
    $is_submitted       = false;
    $is_reanswer        = false;
    $is_grades_released = false;
    $responses          = [];
    $marks              = [];
    $reanswer_qids      = [];
} else {
    // Load student submission
    $submission = $DB->get_record('smartworkbook_submission', [
        'workbookid' => $workbook->id,
        'userid'     => $USER->id,
    ]);

    // Determine submission state
    $is_submitted       = $submission && in_array($submission->status, ['submitted', 'ai_marked', 'grades_released']);
    $is_reanswer        = $submission && $submission->status === 'reanswer';
    $is_grades_released = $submission && $submission->status === 'grades_released';

    // Load student responses (keyed by questionid)
    $responses = [];
    if ($submission) {
        $recs = $DB->get_records('smartworkbook_response', [
            'workbookid' => $workbook->id,
            'userid'     => $USER->id,
        ]);
        foreach ($recs as $r) {
            $responses[$r->questionid] = $r->responsetext;
        }
    }

    // Load marks — needed when grades released OR re-answer mode (to know which questions are flagged)
    $marks = [];
    $reanswer_qids = []; // question IDs flagged for re-answer
    if ($submission && ($is_grades_released || $is_reanswer)) {
        $recs = $DB->get_records('smartworkbook_mark', ['submissionid' => $submission->id]);
        foreach ($recs as $m) {
            $marks[$m->questionid] = $m;
            if ($m->status === 'reanswer') {
                $reanswer_qids[$m->questionid] = true;
            }
        }
    }
}

// ---- Marks / passing grade data ----------------------------------------
// Sum question marks (headings and dochtml structural blocks excluded)
$total_q_marks = 0.0;
foreach ($questions as $q) {
    if ($q->qtype !== 'heading' && $q->qtype !== 'dochtml') {
        $total_q_marks += (float)$q->marks;
    }
}

// Fetch gradepass and grademax from the Moodle grade item
$grade_item_obj = grade_item::fetch([
    'itemtype'     => 'mod',
    'itemmodule'   => 'smartworkbook',
    'iteminstance' => $workbook->id,
    'courseid'     => $course->id,
]);
$gradepass = 0.0;
$grademax  = (float)($workbook->grade ?? 100);
if ($grade_item_obj) {
    $gradepass = (float)$grade_item_obj->gradepass;
    $grademax  = max(1, (float)$grade_item_obj->grademax);
}
// -------------------------------------------------------------------------

// Heading
echo '<div class="sw-workbook-wrap" data-cmid="' . (int)$cm->id . '" data-wbid="' . (int)$workbook->id . '">';

// Preview banner — only shown to teachers in preview mode
if ($preview) {
    $teacher_url = (new moodle_url('/mod/smartworkbook/teacher.php', ['id' => $id]))->out(false);
    echo '<div class="sw-preview-banner">';
    echo '<div class="sw-preview-banner-icon">';
    echo '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">';
    echo '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    echo '</svg>';
    echo '</div>';
    echo '<div class="sw-preview-banner-body">';
    echo '<strong>Teacher Preview</strong> &mdash; This is how your workbook appears to students. ';
    echo 'Autosave and submission are disabled in preview mode. ';
    echo 'To experience the full student workflow, <strong>log in to Moodle as a real student</strong>.';
    echo '</div>';
    echo '<a href="' . $teacher_url . '" class="sw-preview-banner-back btn btn-sm btn-outline-secondary">';
    echo '&larr; Back to Dashboard';
    echo '</a>';
    echo '</div>';
}

echo '<h2 class="sw-workbook-title">' . format_string($workbook->name) . '</h2>';

if (!empty($workbook->intro)) {
    echo $OUTPUT->box(format_module_intro('smartworkbook', $workbook, $cm->id), 'generalbox sw-intro', 'intro');
}

// Marks / passing grade banner — shown to students before they start
if ($total_q_marks > 0) {
    echo '<div class="sw-marks-banner">';
    echo '<div class="sw-marks-banner-inner">';

    // Total marks
    echo '<div class="sw-marks-banner-item">';
    echo '<span class="sw-marks-banner-label">Total marks</span>';
    echo '<span class="sw-marks-banner-value">' . $total_q_marks . '</span>';
    echo '</div>';

    // Passing grade (only shown when gradepass is set)
    if ($gradepass > 0) {
        $pass_pct        = round(($gradepass / $grademax) * 100, 1);
        $pass_raw_marks  = round(($gradepass / $grademax) * $total_q_marks, 1);
        echo '<div class="sw-marks-banner-divider"></div>';
        echo '<div class="sw-marks-banner-item">';
        echo '<span class="sw-marks-banner-label">Pass mark</span>';
        echo '<span class="sw-marks-banner-value">' . $pass_raw_marks . ' / ' . $total_q_marks . '</span>';
        echo '</div>';
        echo '<div class="sw-marks-banner-divider"></div>';
        echo '<div class="sw-marks-banner-item sw-marks-banner-instruction">';
        echo 'You need to score <strong>' . $pass_pct . '%</strong>';
        echo ' (' . $pass_raw_marks . '&nbsp;/&nbsp;' . $total_q_marks . ' marks) to successfully complete this activity.';
        echo '</div>';
    }

    echo '</div>'; // .sw-marks-banner-inner
    echo '</div>'; // .sw-marks-banner
}

// Student name (read-only display)
if (!empty($workbook->showstudentname)) {
    echo '<div class="sw-meta-block">';
    echo '<div class="sw-meta-field sw-meta-field-readonly">';
    echo '<span class="sw-meta-label">' . get_string('studentname_label', 'smartworkbook') . ':</span>';
    echo '<span class="sw-meta-value">' . s(fullname($USER)) . '</span>';
    echo '</div>';
    echo '</div>';
}

// Group members (editable name inputs)
$num_gm = (int)($workbook->numgroupmembers ?? 0);
if ($num_gm > 0) {
    $meta_data = [];
    if ($submission && !empty($submission->meta_json)) {
        $decoded = json_decode($submission->meta_json, true);
        if (is_array($decoded)) {
            $meta_data = $decoded;
        }
    }
    $gm_readonly = ($is_submitted && !$is_reanswer);
    echo '<div class="sw-meta-block sw-group-members-block" data-cmid="' . (int)$cm->id . '">';
    echo '<div class="sw-meta-heading">' . get_string('groupmembers_label', 'smartworkbook') . '</div>';
    for ($i = 1; $i <= $num_gm; $i++) {
        $val = s($meta_data['member_' . $i] ?? '');
        $ro  = $gm_readonly ? ' readonly' : '';
        echo '<div class="sw-meta-field">';
        echo '<span class="sw-meta-label">' . get_string('groupmember_n', 'smartworkbook', $i) . ':</span>';
        echo '<input class="sw-group-member-input" type="text" data-key="member_' . $i . '" value="' . $val . '"' . $ro . ' placeholder="Enter name...">';
        echo '</div>';
    }
    echo '<span class="sw-meta-save-indicator" id="sw-gm-saved" style="display:block;min-height:1.2em;"></span>';
    echo '</div>';
}

// Grades released: show score card
if ($is_grades_released && $submission) {
    $earned = round((float)($submission->total_marks ?? 0), 2);
    $max    = round((float)($submission->max_marks ?? 0), 2);
    $pct    = $max > 0 ? round(($earned / $max) * 100, 1) : 0;
    echo '<div class="sw-score-card">';
    echo '  <div class="sw-score-label">Your grade</div>';
    echo '  <div class="sw-score-value">' . $earned . ' / ' . $max . ' marks (' . $pct . '%)</div>';
    if (!empty($submission->teacher_feedback)) {
        echo '  <div class="sw-score-feedback">' . format_text($submission->teacher_feedback, FORMAT_HTML) . '</div>';
    }
    echo '</div>';
}

// Re-answer notice
if ($is_reanswer) {
    echo '<div class="sw-notice sw-notice-warning">';
    echo get_string('reanswer_notice', 'smartworkbook');
    echo '</div>';
}

// Submitted (awaiting marking) notice
if ($is_submitted && !$is_grades_released && !$is_reanswer) {
    echo '<div class="sw-notice sw-notice-info">' . get_string('submitted', 'smartworkbook') . '</div>';
}

// Questions — consecutive heading-type items are grouped into a single
// flowing instruction block so they render like document body text rather
// than as individual bold underlined lines.
$q_array  = array_values((array)$questions);
$q_count  = count($q_array);
$qi       = 0;   // index into $q_array
$q_index  = 0;   // sequential question number (headings excluded)

while ($qi < $q_count) {
    $q = $q_array[$qi];

    // ── Rich HTML block (dochtml): pre-stored HTML display block ──
    if ($q->qtype === 'dochtml') {
        echo '<div class="sw-doc-html-block">';
        echo $q->questiontext; // HTML stored in DB from original import — never user-entered
        echo '</div>';
        $qi++;
        continue;
    }

    // ── Embedded image block (display-only, no marks/answer) ─────────────
    if ($q->qtype === 'image') {
        if (!empty($q->questiontext)) {
            echo '<div class="sw-image-block">';
            echo '<img src="' . s($q->questiontext) . '" class="sw-embedded-img" alt="">';
            echo '</div>';
        }
        $qi++;
        continue;
    }

    // ── YouTube video block (display-only, no marks/answer) ──────────────
    if ($q->qtype === 'video') {
        if (!empty($q->questiontext)) {
            $vid = smartworkbook_youtube_id($q->questiontext);
            if ($vid) {
                echo '<div class="sw-video-block">';
                echo '<div class="sw-video-responsive">';
                echo '<iframe class="sw-youtube-embed"'
                    . ' src="https://www.youtube.com/embed/' . s($vid) . '"'
                    . ' frameborder="0"'
                    . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"'
                    . ' allowfullscreen></iframe>';
                echo '</div>';
                echo '</div>';
            }
        }
        $qi++;
        continue;
    }

    // ── Instruction block: collect ALL consecutive heading items ───────────
    if ($q->qtype === 'heading') {
        // Peek ahead past this heading group to detect whether any answerable
        // questions follow.  If nothing follows (orphaned trailing heading),
        // advance $qi silently and skip rendering — an empty section block at
        // the bottom is confusing and looks like a duplication.
        $peek = $qi;
        while ($peek < $q_count && $q_array[$peek]->qtype === 'heading') {
            $peek++;
        }
        $has_following_questions = false;
        for ($pk = $peek; $pk < $q_count; $pk++) {
            if (!in_array($q_array[$pk]->qtype, ['heading', 'dochtml', 'image', 'video'])) {
                $has_following_questions = true;
                break;
            }
        }

        $block = [];
        while ($qi < $q_count && $q_array[$qi]->qtype === 'heading') {
            $block[] = format_text($q_array[$qi]->questiontext, FORMAT_HTML);
            $qi++;
        }

        if ($has_following_questions) {
            $first_plain = strip_tags($block[0] ?? '');
            $sec_type_v  = smartworkbook_section_type($first_plain);
            echo '<div class="sw-section-block" data-swtype="' . htmlspecialchars($sec_type_v, ENT_QUOTES | ENT_HTML5) . '">';
            echo smartworkbook_section_badge_html($first_plain, $block[0] ?? '');
            if (count($block) > 1) {
                echo '<div class="sw-section-body">';
                for ($bi = 1; $bi < count($block); $bi++) {
                    echo '<p class="sw-section-para">' . $block[$bi] . '</p>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        continue; // $qi already advanced by inner while
    }

    // ── Answerable question ────────────────────────────────────────────────
    $answer   = $responses[$q->id] ?? '';
    $mark_rec = $marks[$q->id] ?? null;

    $q_index++;

    // Determine read-only per question
    $read_only = $is_reanswer
        ? !isset($reanswer_qids[$q->id])
        : $is_submitted;

    echo '<div class="sw-question" data-qid="' . (int)$q->id . '" data-qtype="' . s($q->qtype) . '">';

    // Question header
    echo '<div class="sw-question-header">';
    $label = !empty($q->label)
        ? '<span class="sw-q-label">' . s($q->label) . '</span> '
        : '<span class="sw-q-label">Q' . $q_index . '</span> ';
    echo $label;
    echo '<span class="sw-q-marks sw-q-marks-avail">' . $q->marks . ' mark' . ($q->marks != 1 ? 's' : '') . '</span>';
    echo '</div>';

    echo '<div class="sw-question-body">' . format_text($q->questiontext, FORMAT_HTML) . '</div>';

    // Re-answer flag (shown above the input when this question is flagged)
    if ($is_reanswer && isset($reanswer_qids[$q->id])) {
        echo '<div class="sw-reanswer-flag">' . get_string('reanswerflag', 'smartworkbook') . '</div>';
    }

    // Answer area
    if ($q->qtype === 'yesno') {
        $yes_checked = ($answer === 'Yes') ? ' checked' : '';
        $no_checked  = ($answer === 'No') ? ' checked' : '';
        echo '<div class="sw-answer sw-yesno" ' . ($read_only ? 'data-readonly="1"' : '') . '>';
        echo '<label><input type="radio" name="yesno_' . $q->id . '" value="Yes"' . $yes_checked . ($read_only ? ' disabled' : '') . '> Yes</label>';
        echo '<label><input type="radio" name="yesno_' . $q->id . '" value="No"' . $no_checked . ($read_only ? ' disabled' : '') . '> No</label>';
        echo '</div>';
    } else if ($q->qtype === 'rating') {
        echo '<div class="sw-answer sw-rating" ' . ($read_only ? 'data-readonly="1"' : '') . '>';
        for ($r = 1; $r <= 5; $r++) {
            $checked = ($answer == $r) ? ' checked' : '';
            echo '<label class="sw-rating-label"><input type="radio" name="rating_' . $q->id . '" value="' . $r . '"' . $checked . ($read_only ? ' disabled' : '') . '> ' . $r . '</label>';
        }
        echo '</div>';
    } else if ($q->qtype === 'table') {
        // Try to detect a structured multi-column table from model_answer JSON.
        $tbl_def = null;
        if (!empty($q->model_answer)) {
            $tbl_decoded = json_decode($q->model_answer, true);
            if (is_array($tbl_decoded) && !empty($tbl_decoded['sw_table'])) {
                $tbl_def = $tbl_decoded;
            }
        }

        // Parse student response (format depends on table type).
        $resp_data = [];
        if (!empty($answer)) {
            $rd = json_decode($answer, true);
            if (is_array($rd)) {
                $resp_data = $rd;
            }
        }

        if ($tbl_def) {
            // ── Structured multi-column table ──────────────────────────────
            $hdr_bg  = preg_replace('/[^#a-zA-Z0-9]/', '', $tbl_def['header_bg'] ?? '#334155');
            $headers = $tbl_def['headers'] ?? [];
            $rows    = $tbl_def['rows'] ?? [];
            $has_edit = false;
            foreach ($rows as $tr) {
                foreach ($tr as $tc) { if (!empty($tc['e'])) { $has_edit = true; break 2; } }
            }
            $ro_attr = $read_only ? ' readonly' : '';

            echo '<div class="sw-answer-wrap sw-stable-wrap">';
            echo '<table class="sw-stable" data-qid="' . (int)$q->id . '">';
            if ($headers) {
                echo '<thead><tr>';
                foreach ($headers as $th) {
                    echo '<th class="sw-stable-th" style="background:' . $hdr_bg . '">' . s($th) . '</th>';
                }
                echo '</tr></thead>';
            }
            echo '<tbody>';
            foreach ($rows as $ri => $row) {
                echo '<tr class="' . ($ri % 2 === 0 ? 'sw-stable-even' : 'sw-stable-odd') . '">';
                foreach ($row as $ci => $cell) {
                    $is_edit   = !empty($cell['e']);
                    $cell_key  = $ri . ',' . $ci;
                    if ($is_edit) {
                        $stu_val = s($resp_data[$cell_key] ?? '');
                        echo '<td class="sw-stable-td sw-stable-td-input">';
                        echo '<input class="sw-stable-cell sw-table-cell"'
                            . ' type="text"'
                            . ' data-qid="' . (int)$q->id . '"'
                            . ' data-key="' . $cell_key . '"'
                            . ' value="' . $stu_val . '"'
                            . $ro_attr
                            . ' placeholder="...">';
                        echo '</td>';
                    } else {
                        echo '<td class="sw-stable-td sw-stable-td-fixed">' . s($cell['v'] ?? '') . '</td>';
                    }
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
            if ($has_edit) {
                echo '<span class="sw-save-indicator" id="sw-saved-' . (int)$q->id . '"></span>';
            }
            echo '</div>';

        } else {
            // ── Legacy 2-column table (no structured definition) ───────────
            $num_rows = max(3, min(10, (int)$q->marks));
            $ro_attr  = $read_only ? ' readonly' : '';
            echo '<div class="sw-answer-wrap sw-table-answer-wrap">';
            echo '<table class="sw-table-input-grid" data-qid="' . (int)$q->id . '">';
            echo '<thead><tr><th>Item</th><th>Response</th></tr></thead><tbody>';
            for ($row = 0; $row < $num_rows; $row++) {
                $item_val = s($resp_data[$row]['item'] ?? '');
                $resp_val = s($resp_data[$row]['response'] ?? '');
                echo '<tr>';
                echo '<td><input class="sw-table-cell" data-qid="' . (int)$q->id . '" data-row="' . $row . '" data-col="item" type="text" value="' . $item_val . '"' . $ro_attr . ' placeholder="Item ' . ($row + 1) . '"></td>';
                echo '<td><input class="sw-table-cell" data-qid="' . (int)$q->id . '" data-row="' . $row . '" data-col="response" type="text" value="' . $resp_val . '"' . $ro_attr . ' placeholder="Response..."></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<span class="sw-save-indicator" id="sw-saved-' . (int)$q->id . '"></span>';
            echo '</div>';
        }
    } else {
        $rows = ($q->qtype === 'long') ? 6 : 3;
        echo '<div class="sw-answer-wrap">';
        echo '<textarea class="sw-answer-text" data-qid="' . (int)$q->id . '" rows="' . $rows . '" placeholder="Type your answer here..."' . ($read_only ? ' readonly' : '') . '>' . s($answer) . '</textarea>';
        echo '<span class="sw-save-indicator" id="sw-saved-' . (int)$q->id . '"></span>';
        echo '</div>';
    }

    // Per-question feedback (grades released)
    if ($is_grades_released && $mark_rec) {
        $final_mark   = $mark_rec->teacher_mark ?? $mark_rec->ai_mark ?? 0;
        $comment      = $mark_rec->teacher_comment ?? $mark_rec->ai_comment ?? '';
        $was_reanswer = ($mark_rec->status === 'reanswer');

        echo '<div class="sw-feedback-block">';
        if ($was_reanswer) {
            echo '<div class="sw-reanswer-flag">' . get_string('reanswerflag', 'smartworkbook') . '</div>';
        }
        echo '<div class="sw-mark-awarded">' . round((float)$final_mark, 2) . ' / ' . $q->marks . ' marks</div>';
        if ($comment) {
            echo '<div class="sw-mark-comment">' . format_text($comment, FORMAT_HTML) . '</div>';
        }
        echo '</div>';
    }

    echo '</div>'; // .sw-question
    $qi++;
}

// Submit / Resubmit button (hidden in preview mode)
$show_submit = !$preview && !$is_submitted && $can_submit;
$show_resubmit = !$preview && $is_reanswer && $can_submit;

if ($show_submit || $show_resubmit) {
    $btn_label = $is_reanswer
        ? get_string('resubmitworkbook', 'smartworkbook')
        : get_string('submitworkbook', 'smartworkbook');
    echo '<div class="sw-submit-row">';
    echo '<button class="btn btn-secondary sw-save-all-btn" type="button">';
    echo get_string('saveprogress', 'smartworkbook');
    echo '</button>';
    echo '<button class="btn btn-primary btn-lg sw-submit-btn" id="sw-submit-btn" data-cmid="' . (int)$cm->id . '">';
    echo $btn_label;
    echo '</button>';
    echo '</div>';
}

echo '</div>'; // .sw-workbook-wrap

// Pass data to AMD — disabled in preview mode (no autosave/submission behaviour needed)
if (!$preview) {
    $PAGE->requires->js_call_amd('mod_smartworkbook/smartworkbook', 'init', [
        (int)$cm->id,
        (int)$workbook->id,
        $is_submitted,
        $is_reanswer,
    ]);
}

echo $OUTPUT->footer();
