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
 * AI Smart Workbook - Teacher dashboard.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm       = get_coursemodule_from_id('smartworkbook', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/smartworkbook:manage', $context);

// ── Platform manual-grading availability check ──────────────────────────────
// Asks the AI Grader platform whether manual grading is permitted for this
// client/course/activity. Defaults to true (allowed) on any error or timeout.
$platform_mg_allowed      = true;
$platform_passing_pct     = null;  // integer 1-100 or null (no platform override)
try {
    $sw_apikey = get_config('local_aiconfig', 'apikey');
    if ($sw_apikey) {
        $sw_curl = new \curl(['cache' => false]);
        $sw_curl->setopt([
            'CURLOPT_TIMEOUT'        => 4,
            'CURLOPT_CONNECTTIMEOUT' => 3,
        ]);
        $sw_qs = http_build_query([
            'apiKey'       => $sw_apikey,
            'courseId'     => $cm->course,
            'cmId'         => $cm->id,
            'courseName'   => $course->fullname,
            'activityName' => $workbook->name,
        ]);
        $sw_resp = $sw_curl->get('https://lms-labs.com/api/smartworkbook/grading-check?' . $sw_qs);
        if ($sw_resp) {
            $sw_data = @json_decode($sw_resp, true);
            if (isset($sw_data['manual_grading_allowed'])) {
                $platform_mg_allowed = (bool)$sw_data['manual_grading_allowed'];
            }
            if (isset($sw_data['passing_percentage']) && is_numeric($sw_data['passing_percentage'])) {
                $platform_passing_pct = (int)$sw_data['passing_percentage'];
                // Apply to Moodle gradebook (grademax=100 for smartworkbook, so pct = gradepass directly).
                $current_gradepass = isset($workbook->gradepass) ? (float)$workbook->gradepass : 0.0;
                if (abs($current_gradepass - $platform_passing_pct) > 0.01) {
                    $workbook->gradepass = (float)$platform_passing_pct;
                    $DB->set_field('smartworkbook', 'gradepass', $platform_passing_pct, ['id' => $workbook->id]);
                    smartworkbook_grade_item_update($workbook);
                }
            }
        }
    }
} catch (\Throwable $sw_ex) {
    // Silently default to allowed on any error.
}
// If platform disallows manual grading, treat this workbook as AI-mode.
$effective_manual_grading = (!empty($workbook->manual_grading) && $platform_mg_allowed);

$PAGE->set_url('/mod/smartworkbook/teacher.php', ['id' => $id]);
$PAGE->set_title(format_string($workbook->name) . ' - ' . get_string('teacherdashboard', 'smartworkbook'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->requires->css('/mod/smartworkbook/styles.css');

$questions  = $DB->get_records('smartworkbook_question', ['workbookid' => $workbook->id], 'sortorder ASC');
$q_count    = count($questions);
$total_marks = array_sum(array_map(function ($q){ return (float)$q->marks; }, $questions));

$status_label = [
    'setup'     => get_string('status_setup', 'smartworkbook'),
    'ready'     => get_string('status_ready', 'smartworkbook'),
    'published' => get_string('status_published', 'smartworkbook'),
];

echo $OUTPUT->header();
?>
<div class="sw-teacher-wrap" id="sw-teacher" data-cmid="<?php echo (int)$cm->id; ?>" data-wbid="<?php echo (int)$workbook->id; ?>">

    <!-- HEADER -->
    <div class="sw-teacher-header">
        <div>
            <h2 class="sw-teacher-title"><?php echo format_string($workbook->name); ?></h2>
        </div>
        <div style="display:-webkit-box;display:-ms-flexbox;display:flex;gap:10px;-webkit-box-align:center;-ms-flex-align:center;align-items:center;-ms-flex-wrap:wrap;flex-wrap:wrap;">
            <?php if ($q_count > 0): ?>
            <button type="button" id="sw-view-student-btn" class="btn btn-outline-secondary btn-sm sw-preview-btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:5px;">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                </svg>
                <?php echo get_string('viewasstudent', 'smartworkbook'); ?>
            </button>
            <?php endif; ?>
            <span class="sw-status-badge sw-status-<?php echo s($workbook->status); ?>" id="sw-status-badge">
                <span class="sw-status-badge-dot"></span>
                <?php echo get_string('status_' . $workbook->status, 'smartworkbook'); ?>
            </span>
        </div>
    </div>

    <?php if (!empty($workbook->intro)): ?>
    <div class="sw-intro">
        <?php echo $OUTPUT->box(format_module_intro('smartworkbook', $workbook, $cm->id), 'generalbox'); ?>
    </div>
    <?php endif; ?>

    <!-- STATS STRIP -->
    <div class="sw-stats-strip">
        <div class="sw-stat-card">
            <div class="sw-stat-label">Questions</div>
            <div class="sw-stat-value sw-stat-value-accent" id="sw-stat-questions"><?php echo $q_count > 0 ? $q_count : '&mdash;'; ?></div>
        </div>
        <div class="sw-stat-card">
            <div class="sw-stat-label">Total Marks</div>
            <div class="sw-stat-value" id="sw-stat-marks"><?php echo $total_marks > 0 ? $total_marks : '&mdash;'; ?></div>
        </div>
        <div class="sw-stat-card">
            <div class="sw-stat-label">Submissions</div>
            <div class="sw-stat-value sw-stat-value-green" id="sw-stat-submissions">&mdash;</div>
        </div>
        <div class="sw-stat-card">
            <div class="sw-stat-label">Awaiting Mark</div>
            <div class="sw-stat-value sw-stat-value-amber" id="sw-stat-awaiting">&mdash;</div>
        </div>
    </div>

    <!-- HOW TO USE CARD -->
    <div class="sw-howto-card" id="sw-howto-card">
        <div class="sw-howto-header" id="sw-howto-toggle" role="button" tabindex="0" aria-expanded="true">
            <div class="sw-howto-header-left">
                <svg class="sw-howto-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
                </svg>
                <span class="sw-howto-title">How to use AI Smart Workbook</span>
                <span class="sw-howto-badge">Quick Start</span>
            </div>
            <div class="sw-howto-header-right">
                <button type="button" class="sw-howto-dismiss-btn" id="sw-howto-dismiss">Got it, hide</button>
                <svg class="sw-howto-chevron" id="sw-howto-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="18 15 12 9 6 15"/>
                </svg>
            </div>
        </div>
        <div class="sw-howto-body" id="sw-howto-body">
            <div class="sw-howto-steps">

                <div class="sw-howto-step">
                    <div class="sw-howto-step-num">1</div>
                    <div class="sw-howto-step-content">
                        <div class="sw-howto-step-title">Add questions &amp; content</div>
                        <div class="sw-howto-step-desc">Click <strong>+ Add Question</strong> in the editor below to add questions one by one. Set the type (short answer, extended response, yes/no, rating, table, heading, image or video), enter the question text, and assign marks. Click <strong>Save Changes</strong> when done.</div>
                    </div>
                </div>

                <div class="sw-howto-step">
                    <div class="sw-howto-step-num">2</div>
                    <div class="sw-howto-step-content">
                        <div class="sw-howto-step-title">Generate model answers (optional)</div>
                        <div class="sw-howto-step-desc">Click <strong>Generate Model Answers</strong> to have AI write polished answers for every question. Costs <strong>3 credits</strong>. Review and edit them — these are what the marking engine compares student answers against.</div>
                    </div>
                </div>

                <div class="sw-howto-step">
                    <div class="sw-howto-step-num">3</div>
                    <div class="sw-howto-step-content">
                        <div class="sw-howto-step-title">Preview what students see</div>
                        <div class="sw-howto-step-desc">Click <strong>View as Student</strong> (top right) at any time to see an exact preview of the workbook as students experience it — all questions, headings and layout visible in read-only mode.</div>
                    </div>
                </div>

                <div class="sw-howto-step">
                    <div class="sw-howto-step-num">4</div>
                    <div class="sw-howto-step-content">
                        <div class="sw-howto-step-title">Configure settings</div>
                        <div class="sw-howto-step-desc">In the <strong>Workbook Settings</strong> card below, toggle the student name field on/off and choose how many group member name slots to show (0–6). Changes save instantly.</div>
                    </div>
                </div>

                <div class="sw-howto-step">
                    <div class="sw-howto-step-num">5</div>
                    <div class="sw-howto-step-content">
                        <div class="sw-howto-step-title">Publish to students</div>
                        <div class="sw-howto-step-desc">Click <strong>Publish to Students</strong> when you're ready. The workbook goes live immediately — students see it the next time they open the activity. You can unpublish at any time.</div>
                    </div>
                </div>

                <div class="sw-howto-step">
                    <div class="sw-howto-step-num">6</div>
                    <div class="sw-howto-step-content">
                        <div class="sw-howto-step-title">Students answer &amp; submit</div>
                        <div class="sw-howto-step-desc">Students open the activity in Moodle and type answers directly — everything auto-saves as they go. They click <strong>Submit Workbook</strong> when finished. The submission count on your stats strip updates live.</div>
                    </div>
                </div>

                <div class="sw-howto-step">
                    <div class="sw-howto-step-num">7</div>
                    <div class="sw-howto-step-content">
                        <div class="sw-howto-step-title">AI mark &amp; release</div>
                        <div class="sw-howto-step-desc">Click <strong>AI Mark All Submitted</strong> (costs <strong>5 credits</strong> per student). Review each AI suggestion — tick, adjust or override — then click <strong>Release Feedback</strong>. Marks go straight to the Moodle gradebook.</div>
                    </div>
                </div>

            </div>

            <div class="sw-howto-footer">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:5px;opacity:.7;">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                Stats strip (top): <strong>Questions</strong> — total questions detected &nbsp;|&nbsp; <strong>Total Marks</strong> — sum of all marks &nbsp;|&nbsp; <strong>Submissions</strong> — students who submitted &nbsp;|&nbsp; <strong>Awaiting Mark</strong> — not yet marked
            </div>
        </div>
    </div>

    <!-- UPLOAD CARD -->
    <div class="sw-upload-card" id="sw-upload-card">

        <div class="sw-upload-card-header">
            <div class="sw-upload-card-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-3px;margin-right:7px;opacity:.75;">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="12" y1="18" x2="12" y2="12"/>
                    <line x1="9" y1="15" x2="12" y2="12"/>
                    <line x1="15" y1="15" x2="12" y2="12"/>
                </svg>
                Upload &amp; Convert Workbook
            </div>
            <div class="sw-upload-card-meta">Upload your teacher version (.docx or .pdf) with model answers — AI extracts all questions, headings &amp; instructions automatically &nbsp;&middot;&nbsp; <strong>Max&nbsp;40&nbsp;MB</strong> &nbsp;&middot;&nbsp; 15 credits per conversion</div>
        </div>

        <label class="sw-drop-zone" id="sw-drop-zone" for="sw-file-input">
            <svg class="sw-drop-icon" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="16 16 12 12 8 16"/>
                <line x1="12" y1="12" x2="12" y2="21"/>
                <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
            </svg>
            <div class="sw-drop-primary">Drop file here or <span class="sw-drop-browse">click to browse</span></div>
            <div class="sw-drop-types">.docx &nbsp;&bull;&nbsp; .pdf</div>
            <input type="file" id="sw-file-input" class="sw-file-input" accept=".docx,.pdf">
        </label>

        <div id="sw-file-chosen-wrap" class="sw-file-chosen-wrap" style="display:none;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;opacity:.6;">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <span class="sw-file-chosen" id="sw-file-chosen"></span>
            <button class="btn btn-primary" id="sw-convert-btn" style="margin-left:auto;flex-shrink:0;">
                Convert with AI
            </button>
        </div>

        <div class="sw-progress" id="sw-progress" style="display:none;">
            <div class="sw-progress-bar-wrap">
                <div class="sw-progress-bar" id="sw-progress-bar"></div>
            </div>
            <div class="sw-progress-label" id="sw-progress-label">Converting workbook &mdash; please wait...</div>
        </div>
    </div>

    <!-- QUESTIONS EDITOR -->
    <div class="sw-q-editor-wrap" id="sw-q-editor">
        <div class="sw-q-editor-header">
            <div>
                <h3 class="sw-q-editor-title">Questions &amp; Content</h3>
                <?php if ($q_count > 0): ?>
                <div class="sw-q-editor-subtitle"><?php echo $q_count; ?> items &mdash; <?php echo $total_marks; ?> total marks &mdash; click any element to edit</div>
                <?php else: ?>
                <div class="sw-q-editor-subtitle">No questions yet &mdash; click <strong>+ Add Question</strong> to get started</div>
                <?php endif; ?>
            </div>
            <div style="display:-webkit-box;display:-ms-flexbox;display:flex;-webkit-box-align:center;-ms-flex-align:center;align-items:center;gap:14px;-ms-flex-wrap:wrap;flex-wrap:wrap;">
                <?php if ($q_count > 0): ?>
                <div class="sw-editor-tabs">
                    <button type="button" id="sw-tab-dv" class="sw-editor-tab sw-tab-active">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                        Document View
                    </button>
                    <button type="button" id="sw-tab-ql" class="sw-editor-tab">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px;"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        Question List
                    </button>
                </div>
                <?php endif; ?>
                <div class="sw-q-editor-actions">
                    <button class="btn btn-outline-secondary btn-sm" id="sw-add-question-btn">+ Add Question</button>
                    <?php if ($q_count > 0): ?>
                    <button class="btn btn-outline-secondary btn-sm" id="sw-gen-answers-btn">Generate Model Answers</button>
                    <button class="btn btn-primary btn-sm" id="sw-save-questions-btn">Save Changes</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== DOCUMENT VIEW PANE ===== -->
        <div id="sw-dv-wrap" class="sw-dv-wrap"<?php echo $q_count === 0 ? ' style="display:none"' : ''; ?>>
            <div class="sw-dv-paper" id="sw-dv-paper">
            <?php
            $dv_qnum = 0;
            foreach ($questions as $dv_q):
                $dv_is_heading = ($dv_q->qtype === 'heading');
                $dv_is_dochtml = ($dv_q->qtype === 'dochtml');
                $dv_is_image   = ($dv_q->qtype === 'image');
                $dv_is_video   = ($dv_q->qtype === 'video');
                $dv_is_display = $dv_is_image || $dv_is_video;
                if (!$dv_is_heading && !$dv_is_display && !$dv_is_dochtml) $dv_qnum++;

                $dv_cls = 'sw-dv-item';
                if ($dv_is_heading)      $dv_cls .= ' sw-dv-item-heading';
                elseif ($dv_is_dochtml)  $dv_cls .= ' sw-dv-item-dochtml';
                elseif ($dv_is_image)    $dv_cls .= ' sw-dv-item-image';
                elseif ($dv_is_video)    $dv_cls .= ' sw-dv-item-video';
                else                     $dv_cls .= ' sw-dv-item-question';
            ?>
            <div class="<?php echo $dv_cls; ?>"
                 data-qid="<?php echo (int)$dv_q->id; ?>"
                 data-qtype="<?php echo s($dv_q->qtype); ?>">

                <?php if ($dv_is_dochtml): ?>
                <div class="sw-dv-dochtml-body"><?php echo $dv_q->questiontext; ?></div>
                <div class="sw-dv-dochtml-actions">
                    <button type="button" class="sw-dv-delete-btn" data-qid="<?php echo (int)$dv_q->id; ?>">Remove</button>
                </div>

                <?php elseif ($dv_is_heading): ?>
                <div class="sw-dv-heading-body"><?php echo format_text($dv_q->questiontext, FORMAT_HTML); ?></div>
                <div class="sw-dv-item-edit-hint">Click to edit</div>

                <?php elseif ($dv_is_image): ?>
                <?php if (!empty($dv_q->questiontext)): ?>
                <img src="<?php echo s($dv_q->questiontext); ?>" class="sw-dv-image-preview" alt="">
                <?php else: ?>
                <div class="sw-dv-image-empty">No image yet &mdash; click to upload</div>
                <?php endif; ?>
                <div class="sw-dv-item-edit-hint">Click to edit</div>

                <?php elseif ($dv_is_video): ?>
                <?php $dv_vid = smartworkbook_youtube_id($dv_q->questiontext ?? ''); ?>
                <?php if ($dv_vid): ?>
                <div class="sw-video-responsive"><iframe class="sw-youtube-embed"
                    src="https://www.youtube.com/embed/<?php echo s($dv_vid); ?>"
                    frameborder="0" allowfullscreen></iframe></div>
                <?php else: ?>
                <div class="sw-dv-video-empty">No video yet &mdash; click to add YouTube URL</div>
                <?php endif; ?>
                <div class="sw-dv-item-edit-hint">Click to edit</div>

                <?php else: ?>
                <div class="sw-dv-q-header">
                    <span class="sw-dv-q-num"><?php echo !empty($dv_q->label) ? s($dv_q->label) : 'Q'.$dv_qnum; ?></span>
                    <span class="sw-dv-q-marks"><?php echo $dv_q->marks; ?> <?php echo $dv_q->marks == 1 ? 'mark' : 'marks'; ?></span>
                </div>
                <div class="sw-dv-q-text"><?php echo format_text($dv_q->questiontext, FORMAT_HTML); ?></div>
                <?php if ($dv_q->qtype === 'yesno'): ?>
                <div class="sw-dv-answer-placeholder sw-dv-yesno-placeholder">
                    <span class="sw-dv-yesno-opt">Yes</span><span class="sw-dv-yesno-opt">No</span>
                </div>
                <?php elseif ($dv_q->qtype === 'rating'): ?>
                <div class="sw-dv-answer-placeholder sw-dv-rating-placeholder">
                    <?php for ($dv_r = 1; $dv_r <= 5; $dv_r++): ?><span class="sw-dv-rating-dot"></span><?php endfor; ?>
                    <span class="sw-dv-rating-label">1 &ndash; 5</span>
                </div>
                <?php elseif ($dv_q->qtype === 'table'):
                    $dv_tbl = null;
                    if (!empty($dv_q->model_answer)) {
                        $dv_tbl_dec = json_decode($dv_q->model_answer, true);
                        if (is_array($dv_tbl_dec) && !empty($dv_tbl_dec['sw_table'])) {
                            $dv_tbl = $dv_tbl_dec;
                        }
                    }
                    if ($dv_tbl):
                        $dv_hdr_bg = preg_replace('/[^#a-zA-Z0-9]/', '', $dv_tbl['header_bg'] ?? '#334155');
                ?>
                <div class="sw-answer-wrap sw-stable-wrap sw-dv-table-live">
                    <table class="sw-stable">
                        <?php if (!empty($dv_tbl['headers'])): ?>
                        <thead><tr>
                        <?php foreach ($dv_tbl['headers'] as $dv_th): ?>
                            <th class="sw-stable-th" style="background:<?php echo $dv_hdr_bg; ?>"><?php echo s($dv_th); ?></th>
                        <?php endforeach; ?>
                        </tr></thead>
                        <?php endif; ?>
                        <tbody>
                        <?php foreach ($dv_tbl['rows'] as $dv_ri => $dv_row): ?>
                        <tr class="<?php echo ($dv_ri % 2 === 0 ? 'sw-stable-even' : 'sw-stable-odd'); ?>">
                            <?php foreach ($dv_row as $dv_cell): ?>
                            <?php if (!empty($dv_cell['e'])): ?>
                            <td class="sw-stable-td sw-stable-td-input"><input class="sw-stable-cell" type="text" readonly placeholder="..."></td>
                            <?php else: ?>
                            <td class="sw-stable-td sw-stable-td-fixed"><?php echo s($dv_cell['v'] ?? ''); ?></td>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else:
                    $dv_tbl_rows = max(3, min(10, (int)$dv_q->marks));
                ?>
                <div class="sw-answer-wrap sw-table-answer-wrap sw-dv-table-live">
                    <table class="sw-table-input-grid">
                        <thead><tr><th>Item</th><th>Response</th></tr></thead>
                        <tbody>
                        <?php for ($dv_tr = 0; $dv_tr < $dv_tbl_rows; $dv_tr++): ?>
                        <tr>
                            <td><input class="sw-table-cell" type="text" readonly placeholder="Item <?php echo $dv_tr+1; ?>"></td>
                            <td><input class="sw-table-cell" type="text" readonly placeholder="Response..."></td>
                        </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="sw-dv-answer-placeholder">
                    <div class="sw-dv-answer-lines">
                        <div class="sw-dv-answer-line"></div>
                        <div class="sw-dv-answer-line sw-dv-answer-line-short"></div>
                        <?php if ($dv_q->qtype === 'long'): ?><div class="sw-dv-answer-line"></div><div class="sw-dv-answer-line sw-dv-answer-line-short"></div><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="sw-dv-item-edit-hint">Click to edit</div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
            </div><!-- .sw-dv-paper -->
        </div><!-- #sw-dv-wrap -->

        <!-- ===== QUESTION LIST PANE (existing card editor) ===== -->
        <div id="sw-q-editor-body"<?php echo $q_count === 0 ? '' : ' style="display:none"'; ?>>
        <ul class="sw-q-list" id="sw-q-list">
        <?php $qnum = 0; foreach ($questions as $q):
            $is_heading  = ($q->qtype === 'heading');
            $is_display  = in_array($q->qtype, ['image', 'video']);
            $is_dochtml  = ($q->qtype === 'dochtml');
            if (!$is_heading && !$is_display && !$is_dochtml) $qnum++;
        ?>
            <li class="sw-q-item<?php echo ($is_heading || $is_dochtml) ? ' sw-q-item-heading-row' : ''; ?><?php echo $is_display ? ' sw-q-item-display-row' : ''; ?>"
                data-qid="<?php echo (int)$q->id; ?>"
                data-qtype="<?php echo s($q->qtype); ?>"
                draggable="true">
                <div class="sw-q-item-header">
                    <span class="sw-q-drag-handle" title="Drag to reorder">&#8942;&#8942;</span>
                    <span class="sw-q-item-num<?php echo ($is_heading || $is_dochtml) ? ' sw-q-item-num-heading' : ''; ?><?php echo $is_display ? ' sw-q-item-num-display' : ''; ?>">
                        <?php
                        if ($is_heading)             echo 'HDG';
                        elseif ($is_dochtml)         echo 'SEC';
                        elseif ($q->qtype==='image') echo 'IMG';
                        elseif ($q->qtype==='video') echo 'VID';
                        else                         echo 'Q'.$qnum;
                        ?>
                    </span>
                    <div class="sw-q-item-body">
                        <div class="sw-q-field-row">
                            <?php if (!$is_dochtml): ?>
                            <span class="sw-q-field-label">Label</span>
                            <input type="text" class="sw-q-inline-input sw-q-label-input" data-qid="<?php echo (int)$q->id; ?>"
                                   value="<?php echo s($q->label); ?>" placeholder="e.g. Q1" style="width:72px;">
                            <span class="sw-q-field-label">Type</span>
                            <select class="sw-q-inline-select sw-q-type-select" data-qid="<?php echo (int)$q->id; ?>">
                                <option value="heading"<?php echo $q->qtype==='heading'?' selected':''; ?>>Section heading</option>
                                <option value="text"<?php echo $q->qtype==='text'?' selected':''; ?>>Short answer</option>
                                <option value="long"<?php echo $q->qtype==='long'?' selected':''; ?>>Extended response</option>
                                <option value="yesno"<?php echo $q->qtype==='yesno'?' selected':''; ?>>Yes / No</option>
                                <option value="rating"<?php echo $q->qtype==='rating'?' selected':''; ?>>Rating scale</option>
                                <option value="table"<?php echo $q->qtype==='table'?' selected':''; ?>>Table</option>
                                <option value="image"<?php echo $q->qtype==='image'?' selected':''; ?>>Embedded image</option>
                                <option value="video"<?php echo $q->qtype==='video'?' selected':''; ?>>YouTube video</option>
                            </select>
                            <?php if (!$is_heading): ?>
                            <span class="sw-q-field-label">Marks</span>
                            <input type="number" class="sw-q-inline-input sw-q-item-marks-input" data-qid="<?php echo (int)$q->id; ?>"
                                   value="<?php echo round((float)$q->marks, 2); ?>" min="0.5" step="0.5" style="width:66px;">
                            <?php endif; ?>
                            <?php else: ?>
                            <span style="font-size:0.82rem;color:#64748b;font-style:italic;">HTML display block &mdash; read-only</span>
                            <?php endif; ?>
                            <button class="sw-q-delete-btn" data-qid="<?php echo (int)$q->id; ?>" title="Delete this row">&#10005;</button>
                        </div>
                        <div class="sw-q-rte-wrap">
                            <?php if (!$is_dochtml): ?>
                            <div class="sw-rte-toolbar">
                                <button type="button" class="sw-rte-btn" data-cmd="bold"        title="Bold"><strong>B</strong></button>
                                <button type="button" class="sw-rte-btn" data-cmd="italic"      title="Italic"><em>I</em></button>
                                <button type="button" class="sw-rte-btn" data-cmd="underline"   title="Underline"><u>U</u></button>
                                <span class="sw-rte-sep"></span>
                                <button type="button" class="sw-rte-btn" data-cmd="formatBlock" data-val="H2" title="Heading 2">H2</button>
                                <button type="button" class="sw-rte-btn" data-cmd="formatBlock" data-val="H3" title="Heading 3">H3</button>
                                <button type="button" class="sw-rte-btn" data-cmd="formatBlock" data-val="P"  title="Normal paragraph">P</button>
                                <span class="sw-rte-sep"></span>
                                <label class="sw-rte-color-label" title="Text colour">
                                    <span class="sw-rte-color-icon">A</span>
                                    <input type="color" class="sw-rte-color-input" value="#000000">
                                </label>
                                <span class="sw-rte-sep"></span>
                                <button type="button" class="sw-rte-btn" data-cmd="removeFormat" title="Clear formatting">&#10005; fmt</button>
                            </div>
                            <div class="sw-q-text-area sw-q-text-input sw-q-rte-editor"
                                 contenteditable="true"
                                 data-qid="<?php echo (int)$q->id; ?>"
                                 data-placeholder="<?php echo $is_heading ? 'Section heading text...' : 'Question text...'; ?>"
                                 <?php echo $is_display ? 'style="display:none"' : ''; ?>
                            ><?php echo ($is_display ? '' : $q->questiontext); ?></div>
                            <?php else: ?>
                            <div class="sw-dochtml-preview" style="pointer-events:none;margin-top:6px;overflow-x:auto;">
                                <?php echo $q->questiontext; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- IMAGE ZONE: paste / upload an image (shown for image qtype) -->
                        <div class="sw-img-zone" data-qid="<?php echo (int)$q->id; ?>"
                             style="<?php echo $q->qtype === 'image' ? '' : 'display:none'; ?>">
                            <?php if ($q->qtype === 'image' && !empty($q->questiontext)): ?>
                            <img class="sw-img-preview" src="<?php echo s($q->questiontext); ?>" alt="Embedded image">
                            <?php else: ?>
                            <img class="sw-img-preview" src="" alt="" style="display:none">
                            <?php endif; ?>
                            <div class="sw-img-paste-area" tabindex="0">
                                <span class="sw-paste-msg">Click here, then press <kbd>Ctrl+V</kbd> to paste an image &mdash; or drag &amp; drop a file</span>
                            </div>
                            <label class="sw-img-upload-label">
                                Upload image file
                                <input type="file" class="sw-img-file-input" accept="image/png,image/jpeg,image/gif,image/webp">
                            </label>
                            <span class="sw-img-status"></span>
                            <!-- Preserves the data URI across save_questions calls -->
                            <input type="hidden" class="sw-img-data-input"
                                   value="<?php echo ($q->qtype === 'image') ? s($q->questiontext) : ''; ?>">
                        </div>

                        <!-- VIDEO ZONE: enter a YouTube URL (shown for video qtype) -->
                        <div class="sw-video-zone" data-qid="<?php echo (int)$q->id; ?>"
                             style="<?php echo $q->qtype === 'video' ? '' : 'display:none'; ?>">
                            <input type="url" class="sw-video-url-input"
                                   placeholder="Paste YouTube URL here (e.g. https://www.youtube.com/watch?v=...)"
                                   data-qid="<?php echo (int)$q->id; ?>"
                                   value="<?php echo ($q->qtype === 'video') ? s($q->questiontext) : ''; ?>">
                            <div class="sw-video-preview">
                                <?php if ($q->qtype === 'video' && !empty($q->questiontext)):
                                    $pv_vid = smartworkbook_youtube_id($q->questiontext);
                                    if ($pv_vid): ?>
                                <div class="sw-video-responsive">
                                    <iframe src="https://www.youtube.com/embed/<?php echo s($pv_vid); ?>"
                                            frameborder="0" allowfullscreen class="sw-youtube-embed"></iframe>
                                </div>
                                <?php endif; endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
                <?php if (!$is_heading && !$is_display && !$is_dochtml): ?>
                <div class="sw-q-sub-grid" style="padding-left:52px;">
                    <div class="sw-q-sub-grid-col">
                        <!-- Plain model answer (non-table types) -->
                        <label class="sw-q-sub-label sw-model-plain-lbl"<?php echo $q->qtype === 'table' ? ' style="display:none"' : ''; ?>>Model answer</label>
                        <!-- Table builder label (table type only) -->
                        <label class="sw-q-sub-label sw-model-table-lbl"<?php echo $q->qtype !== 'table' ? ' style="display:none"' : ''; ?>>Table Builder</label>
                        <!-- Single model_answer textarea — stores JSON for table type, plain text otherwise -->
                        <textarea class="sw-q-text-area sw-q-model-input" data-qid="<?php echo (int)$q->id; ?>" rows="2"
                                  placeholder="Model answer for AI marking..."
                                  <?php echo $q->qtype === 'table' ? 'style="display:none"' : ''; ?>><?php echo s($q->model_answer ?? ''); ?></textarea>
                        <!-- Table builder UI (shown for table type) -->
                        <div class="sw-te-wrap" data-qid="<?php echo (int)$q->id; ?>"<?php echo $q->qtype !== 'table' ? ' style="display:none"' : ''; ?>></div>
                        <div class="sw-te-controls"<?php echo $q->qtype !== 'table' ? ' style="display:none"' : ''; ?>>
                            <button type="button" class="sw-te-ctrl-btn" data-action="add-row">+ Row</button>
                            <button type="button" class="sw-te-ctrl-btn" data-action="del-row">- Row</button>
                            <button type="button" class="sw-te-ctrl-btn" data-action="add-col">+ Column</button>
                            <button type="button" class="sw-te-ctrl-btn" data-action="del-col">- Column</button>
                            <label class="sw-te-color-lbl">Header colour&nbsp;<input type="color" class="sw-te-hdr-color" value="#334155"></label>
                        </div>
                        <p class="sw-te-hint"<?php echo $q->qtype !== 'table' ? ' style="display:none"' : ''; ?>>&#128274; = pre-filled &nbsp;&bull;&nbsp; &#9999; = student fills. Click icon to toggle.</p>
                    </div>
                    <div class="sw-q-sub-grid-col">
                        <label class="sw-q-sub-label">Rubric / marking notes</label>
                        <textarea class="sw-q-text-area sw-q-rubric-input" data-qid="<?php echo (int)$q->id; ?>" rows="2"
                                  placeholder="Marking guidance for AI and teacher..."><?php echo s($q->rubric_notes ?? ''); ?></textarea>
                    </div>
                </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if ($q_count === 0): ?>
        <li class="sw-q-empty-state" style="list-style:none;padding:40px 24px;text-align:center;color:#94a3b8;">
            <div style="font-size:2rem;margin-bottom:12px;opacity:0.4;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </div>
            <div style="font-size:0.95rem;font-weight:600;color:#64748b;margin-bottom:6px;">No questions yet</div>
            <div style="font-size:0.84rem;color:#94a3b8;">Click <strong>+ Add Question</strong> above to add your first question.</div>
        </li>
        <?php endif; ?>
        </ul>
        </div><!-- #sw-q-editor-body -->
    </div><!-- .sw-q-editor-wrap -->

    <!-- FLOATING EDIT PANEL -->
    <div id="sw-fp" class="sw-fp" style="display:none;" role="dialog" aria-modal="true">
        <div class="sw-fp-header">
            <span class="sw-fp-badge sw-fp-badge-question" id="sw-fp-badge">Q</span>
            <span class="sw-fp-title" id="sw-fp-title">Edit</span>
            <button type="button" class="sw-fp-close" id="sw-fp-close" aria-label="Close">&times;</button>
        </div>
        <div class="sw-fp-body" id="sw-fp-body"></div>
        <div class="sw-fp-footer">
            <button type="button" class="btn btn-sm btn-outline-danger" id="sw-fp-delete">Delete</button>
            <div style="margin-left:auto;display:-webkit-box;display:-ms-flexbox;display:flex;gap:8px;">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="sw-fp-cancel">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="sw-fp-save">Save</button>
            </div>
        </div>
    </div>
    <div id="sw-fp-backdrop" class="sw-fp-backdrop" style="display:none;" aria-hidden="true"></div>

    <!-- WORKBOOK SETTINGS -->
    <?php if ($q_count > 0): ?>
    <div class="sw-publish-card sw-settings-card" id="sw-settings-card">
        <div class="sw-publish-card-info">
            <div class="sw-publish-card-title">Workbook Settings</div>
            <div class="sw-publish-card-sub">Configure header fields, group work options, and how submissions are graded.</div>
        </div>
        <div class="sw-settings-fields">
            <label class="sw-settings-toggle-label">
                <input type="checkbox" id="sw-setting-studentname" class="sw-settings-checkbox"
                       <?php echo !empty($workbook->showstudentname) ? 'checked' : ''; ?>>
                <span>Show student name field</span>
            </label>
            <div class="sw-settings-row">
                <label class="sw-settings-label" for="sw-setting-groupmembers">Group member slots</label>
                <select id="sw-setting-groupmembers" class="sw-settings-select">
                    <option value="0" <?php echo ((int)($workbook->numgroupmembers ?? 0) === 0) ? 'selected' : ''; ?>>None (hidden)</option>
                    <?php for ($si = 1; $si <= 6; $si++): ?>
                    <option value="<?php echo $si; ?>" <?php echo ((int)($workbook->numgroupmembers ?? 0) === $si) ? 'selected' : ''; ?>>
                        <?php echo $si; ?> member<?php echo $si > 1 ? 's' : ''; ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="sw-settings-divider"></div>
            <?php if ($platform_mg_allowed): ?>
            <label class="sw-settings-toggle-label sw-settings-toggle-grading">
                <input type="checkbox" id="sw-setting-manualgrading" class="sw-settings-checkbox"
                       <?php echo !empty($workbook->manual_grading) ? 'checked' : ''; ?>>
                <span>
                    <strong>Manual grading mode</strong>
                    <span class="sw-settings-sub-hint">Disable AI marking — use the grading checklist instead. No AI credits used.</span>
                </span>
            </label>
            <?php else: ?>
            <div class="sw-mg-disabled-notice">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:5px;opacity:.6;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Manual grading is disabled by your platform administrator for this activity.
            </div>
            <?php endif; ?>
            <span class="sw-settings-saved" id="sw-settings-saved" style="display:none;">&#10003; Saved</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- PUBLISH / UNPUBLISH -->
    <?php if ($q_count > 0): ?>
    <?php if ($workbook->status !== 'published'): ?>
    <div class="sw-publish-card" id="sw-publish-section">
        <div class="sw-publish-card-info">
            <div class="sw-publish-card-title">Ready to publish?</div>
            <div class="sw-publish-card-sub">Save your questions first, then publish to make this workbook available to students.</div>
        </div>
        <div style="display:-webkit-box;display:-ms-flexbox;display:flex;gap:10px;-webkit-box-align:center;-ms-flex-align:center;align-items:center;-ms-flex-wrap:wrap;flex-wrap:wrap;">
            <span class="sw-publish-note" id="sw-publish-note"></span>
            <button class="btn btn-success" id="sw-publish-btn">Publish to Students</button>
        </div>
    </div>
    <?php else: ?>
    <div class="sw-publish-card sw-unpublish-card" id="sw-publish-section">
        <div class="sw-publish-card-info">
            <div class="sw-publish-card-title">Workbook is published</div>
            <div class="sw-publish-card-sub">Students can currently access and submit this workbook. Unpublish to take it offline.</div>
        </div>
        <div style="display:-webkit-box;display:-ms-flexbox;display:flex;gap:10px;-webkit-box-align:center;-ms-flex-align:center;align-items:center;-ms-flex-wrap:wrap;flex-wrap:wrap;">
            <span class="sw-publish-note" id="sw-publish-note"></span>
            <button class="btn btn-warning" id="sw-unpublish-btn">Unpublish</button>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- SUBMISSIONS -->
    <div class="sw-submissions-wrap" id="sw-submissions-wrap">
        <div class="sw-sub-header">
            <div>
                <h3 class="sw-sub-title">Student Submissions</h3>
                <div class="sw-sub-count" id="sw-sub-count">Loading...</div>
            </div>
            <?php if (!$effective_manual_grading): ?>
            <button class="btn btn-primary btn-sm sw-ai-mark-all-btn" id="sw-ai-mark-all-btn">
                AI Mark All Submitted
                <small style="display:block;font-size:0.72rem;font-weight:400;opacity:0.85;">(5 credits each)</small>
            </button>
            <?php else: ?>
            <span class="sw-manual-mode-badge">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Manual Grading Mode
            </span>
            <?php endif; ?>
        </div>
        <div id="sw-submissions-body">
            <table class="sw-sub-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Status</th>
                        <th>Score</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="sw-sub-tbody">
                    <tr><td colspan="5" style="padding:32px;text-align:center;color:#94a3b8;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- STUDENT PREVIEW OVERLAY -->
    <?php if ($q_count > 0):
        // Calculate total marks (excluding headings, dochtml, image, video)
        $pv_total_q_marks = 0.0;
        foreach ($questions as $pv_q_tmp) {
            if (!in_array($pv_q_tmp->qtype, ['heading', 'dochtml', 'image', 'video'])) {
                $pv_total_q_marks += (float)$pv_q_tmp->marks;
            }
        }
        $pv_q_array  = array_values((array)$questions);
        $pv_q_count  = count($pv_q_array);
    ?>
    <div class="sw-student-preview-overlay" id="sw-student-preview-overlay">
        <div class="sw-student-preview-inner">

            <!-- Banner -->
            <div class="sw-preview-banner" style="margin-bottom:24px;">
                <div class="sw-preview-banner-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                </div>
                <div class="sw-preview-banner-body">
                    <strong>Teacher Preview</strong> &mdash; This is how your workbook appears to students.
                    Autosave and submission are disabled in preview mode.
                    To see the real experience, <strong>log in to Moodle as a real student</strong>.
                </div>
                <button type="button" id="sw-student-preview-close" class="sw-preview-banner-back btn btn-sm">
                    &times; Close Preview
                </button>
            </div>

            <!-- Workbook content rendered in student style -->
            <div class="sw-workbook-wrap">
                <h2 class="sw-workbook-title"><?php echo format_string($workbook->name); ?></h2>

                <!-- Student name (preview, visibility controlled by settings toggle) -->
                <div class="sw-meta-block" id="sw-pv-studentname-block"
                     style="<?php echo empty($workbook->showstudentname) ? 'display:none;' : ''; ?>">
                    <span class="sw-meta-label"><?php echo get_string('studentname_label', 'smartworkbook'); ?>:</span>
                    <input class="sw-student-name-input" type="text" readonly
                           value="<?php echo s(fullname($USER)); ?>"
                           style="color:#94a3b8;background:transparent;border:none;cursor:default;font-style:italic;">
                </div>

                <!-- Group members (preview) -->
                <div class="sw-meta-block sw-group-members-block" id="sw-pv-groupmembers-block"
                     style="<?php echo ((int)($workbook->numgroupmembers ?? 0) === 0) ? 'display:none;' : ''; ?>">
                    <div class="sw-meta-heading"><?php echo get_string('groupmembers_label', 'smartworkbook'); ?></div>
                    <?php for ($pv_gm = 1; $pv_gm <= 6; $pv_gm++): ?>
                    <div class="sw-meta-row" id="sw-pv-gm-row-<?php echo $pv_gm; ?>"
                         style="<?php echo ($pv_gm > (int)($workbook->numgroupmembers ?? 0)) ? 'display:none;' : ''; ?>">
                        <span class="sw-meta-label"><?php echo get_string('groupmember_n', 'smartworkbook', $pv_gm); ?>:</span>
                        <input class="sw-group-member-input" type="text" readonly placeholder="Enter name...">
                    </div>
                    <?php endfor; ?>
                </div>

                <?php if ($pv_total_q_marks > 0): ?>
                <div class="sw-marks-banner">
                    <div class="sw-marks-banner-inner">
                        <div class="sw-marks-banner-item">
                            <span class="sw-marks-banner-label">Total marks</span>
                            <span class="sw-marks-banner-value"><?php echo $pv_total_q_marks; ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                $pv_qi      = 0;
                $pv_q_index = 0;
                while ($pv_qi < $pv_q_count):
                    $pv_q = $pv_q_array[$pv_qi];

                    // ── Rich HTML block (dochtml) ───────────────────────────
                    if ($pv_q->qtype === 'dochtml'):
                        echo '<div class="sw-doc-html-block">' . $pv_q->questiontext . '</div>';
                        $pv_qi++;
                        continue;
                    endif;

                    // ── Embedded image block (display-only) ─────────────────
                    if ($pv_q->qtype === 'image'):
                        if (!empty($pv_q->questiontext)):
                            echo '<div class="sw-image-block"><img src="' . s($pv_q->questiontext) . '" class="sw-embedded-img" alt=""></div>';
                        endif;
                        $pv_qi++;
                        continue;
                    endif;

                    // ── YouTube video block (display-only) ──────────────────
                    if ($pv_q->qtype === 'video'):
                        if (!empty($pv_q->questiontext)):
                            $pv_yvid = smartworkbook_youtube_id($pv_q->questiontext);
                            if ($pv_yvid):
                                echo '<div class="sw-video-block"><div class="sw-video-responsive">'
                                    . '<iframe class="sw-youtube-embed" src="https://www.youtube.com/embed/' . s($pv_yvid) . '" frameborder="0" allowfullscreen></iframe>'
                                    . '</div></div>';
                            endif;
                        endif;
                        $pv_qi++;
                        continue;
                    endif;

                    // ── Consecutive heading group ───────────────────────────
                    if ($pv_q->qtype === 'heading'):
                        // Peek ahead: suppress orphaned trailing heading groups that
                        // have no answerable questions following them.
                        $pv_peek = $pv_qi;
                        while ($pv_peek < $pv_q_count && $pv_q_array[$pv_peek]->qtype === 'heading'):
                            $pv_peek++;
                        endwhile;
                        $pv_has_following = false;
                        for ($pv_pk = $pv_peek; $pv_pk < $pv_q_count; $pv_pk++):
                            if (!in_array($pv_q_array[$pv_pk]->qtype, ['heading', 'dochtml', 'image', 'video'])):
                                $pv_has_following = true;
                                break;
                            endif;
                        endfor;

                        $pv_block = [];
                        while ($pv_qi < $pv_q_count && $pv_q_array[$pv_qi]->qtype === 'heading'):
                            $pv_block[] = format_text($pv_q_array[$pv_qi]->questiontext, FORMAT_HTML);
                            $pv_qi++;
                        endwhile;

                        if ($pv_has_following):
                            $pv_first_plain = strip_tags($pv_block[0] ?? '');
                            echo '<div class="sw-section-block">';
                            echo smartworkbook_section_badge_html($pv_first_plain, $pv_block[0] ?? '');
                            if (count($pv_block) > 1):
                                echo '<div class="sw-section-body">';
                                for ($pv_bi = 1; $pv_bi < count($pv_block); $pv_bi++):
                                    echo '<p class="sw-section-para">' . $pv_block[$pv_bi] . '</p>';
                                endfor;
                                echo '</div>';
                            endif;
                            echo '</div>';
                        endif;
                        continue;
                    endif;

                    // ── Answerable question ─────────────────────────────────
                    $pv_q_index++;
                    echo '<div class="sw-question" data-qtype="' . s($pv_q->qtype) . '">';

                    // Header
                    echo '<div class="sw-question-header">';
                    $pv_label = !empty($pv_q->label)
                        ? '<span class="sw-q-label">' . s($pv_q->label) . '</span> '
                        : '<span class="sw-q-label">Q' . $pv_q_index . '</span> ';
                    echo $pv_label;
                    echo '<span class="sw-q-marks sw-q-marks-avail">' . $pv_q->marks . ' mark' . ($pv_q->marks != 1 ? 's' : '') . '</span>';
                    echo '</div>';

                    // Body
                    echo '<div class="sw-question-body">' . format_text($pv_q->questiontext, FORMAT_HTML) . '</div>';

                    // Answer area (all read-only / disabled)
                    if ($pv_q->qtype === 'yesno'):
                        echo '<div class="sw-answer sw-yesno" data-readonly="1">';
                        echo '<label><input type="radio" name="pv_yesno_' . $pv_q->id . '" value="Yes" disabled> Yes</label>';
                        echo '<label><input type="radio" name="pv_yesno_' . $pv_q->id . '" value="No" disabled> No</label>';
                        echo '</div>';
                    elseif ($pv_q->qtype === 'rating'):
                        echo '<div class="sw-answer sw-rating" data-readonly="1">';
                        for ($pv_r = 1; $pv_r <= 5; $pv_r++):
                            echo '<label class="sw-rating-label"><input type="radio" name="pv_rating_' . $pv_q->id . '" value="' . $pv_r . '" disabled> ' . $pv_r . '</label>';
                        endfor;
                        echo '</div>';
                    elseif ($pv_q->qtype === 'table'):
                        // Check for structured table definition.
                        $pv_tbl = null;
                        if (!empty($pv_q->model_answer)) {
                            $pv_tbl_dec = json_decode($pv_q->model_answer, true);
                            if (is_array($pv_tbl_dec) && !empty($pv_tbl_dec['sw_table'])) {
                                $pv_tbl = $pv_tbl_dec;
                            }
                        }
                        if ($pv_tbl):
                            $pv_hdr_bg = preg_replace('/[^#a-zA-Z0-9]/', '', $pv_tbl['header_bg'] ?? '#334155');
                            echo '<div class="sw-answer-wrap sw-stable-wrap">';
                            echo '<table class="sw-stable">';
                            if (!empty($pv_tbl['headers'])):
                                echo '<thead><tr>';
                                foreach ($pv_tbl['headers'] as $pv_th):
                                    echo '<th class="sw-stable-th" style="background:' . $pv_hdr_bg . '">' . s($pv_th) . '</th>';
                                endforeach;
                                echo '</tr></thead>';
                            endif;
                            echo '<tbody>';
                            foreach ($pv_tbl['rows'] as $pv_ri => $pv_row):
                                echo '<tr class="' . ($pv_ri % 2 === 0 ? 'sw-stable-even' : 'sw-stable-odd') . '">';
                                foreach ($pv_row as $pv_cell):
                                    if (!empty($pv_cell['e'])):
                                        echo '<td class="sw-stable-td sw-stable-td-input"><input class="sw-stable-cell" type="text" readonly placeholder="..."></td>';
                                    else:
                                        echo '<td class="sw-stable-td sw-stable-td-fixed">' . s($pv_cell['v'] ?? '') . '</td>';
                                    endif;
                                endforeach;
                                echo '</tr>';
                            endforeach;
                            echo '</tbody></table></div>';
                        else:
                            $pv_num_rows = max(3, min(10, (int)$pv_q->marks));
                            echo '<div class="sw-answer-wrap sw-table-answer-wrap">';
                            echo '<table class="sw-table-input-grid">';
                            echo '<thead><tr><th>Item</th><th>Response</th></tr></thead><tbody>';
                            for ($pv_row2 = 0; $pv_row2 < $pv_num_rows; $pv_row2++):
                                echo '<tr>';
                                echo '<td><input class="sw-table-cell" type="text" value="" readonly placeholder="Item ' . ($pv_row2 + 1) . '"></td>';
                                echo '<td><input class="sw-table-cell" type="text" value="" readonly placeholder="Response..."></td>';
                                echo '</tr>';
                            endfor;
                            echo '</tbody></table>';
                            echo '</div>';
                        endif;
                    else:
                        $pv_rows = ($pv_q->qtype === 'long') ? 6 : 3;
                        echo '<div class="sw-answer-wrap">';
                        echo '<textarea class="sw-answer-text" rows="' . $pv_rows . '" readonly placeholder="Type your answer here..."></textarea>';
                        echo '</div>';
                    endif;

                    echo '</div>'; // .sw-question
                    $pv_qi++;
                endwhile;
                ?>

            </div><!-- .sw-workbook-wrap -->
        </div><!-- .sw-student-preview-inner -->
    </div><!-- .sw-student-preview-overlay -->
    <?php endif; ?>

    <!-- MANUAL GRADING CONSOLE OVERLAY -->
    <div class="sw-console-overlay" id="sw-manual-overlay" style="display:none;">
        <div class="sw-console-panel sw-manual-panel" id="sw-manual-panel">
            <button class="sw-console-close" id="sw-manual-close" type="button">&times;</button>
            <div class="sw-console-header">
                <div class="sw-manual-header-top">
                    <h3 class="sw-console-title">Grading Checklist</h3>
                    <span class="sw-manual-mode-label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-1px;margin-right:3px;"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        Manual Grading
                    </span>
                </div>
                <p class="sw-console-student" id="sw-manual-student-name"></p>
            </div>
            <div class="sw-console-totals" id="sw-manual-totals">
                <div>
                    <div class="sw-console-totals-label">Running Total</div>
                    <div class="sw-console-totals-value" id="sw-manual-totals-display">0 / 0</div>
                </div>
                <div style="text-align:right;font-size:0.82rem;color:#94a3b8;" id="sw-manual-totals-pct"></div>
            </div>
            <div id="sw-manual-questions"></div>
            <div class="sw-console-actions" id="sw-manual-actions">
                <button class="btn btn-success" id="sw-manual-save-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:5px;"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    Save &amp; Release Grades
                </button>
            </div>
        </div>
    </div>

    <!-- AI MARKING CONSOLE OVERLAY -->
    <div class="sw-console-overlay" id="sw-console-overlay">
        <div class="sw-console-panel" id="sw-console-panel">
            <button class="sw-console-close" id="sw-console-close" type="button">&times;</button>
            <div class="sw-console-header">
                <h3 class="sw-console-title">Marking Console</h3>
                <p class="sw-console-student" id="sw-console-student-name"></p>
            </div>
            <div class="sw-console-totals" id="sw-console-totals">
                <div>
                    <div class="sw-console-totals-label">Score</div>
                    <div class="sw-console-totals-value" id="sw-totals-display">0 / 0</div>
                </div>
                <div style="text-align:right;font-size:0.82rem;color:#94a3b8;" id="sw-totals-pct"></div>
            </div>
            <div id="sw-console-questions"></div>
            <div class="sw-console-actions" id="sw-console-actions">
                <button class="btn btn-outline-secondary" id="sw-console-ai-mark-btn">AI Mark This</button>
                <button class="btn btn-warning" id="sw-console-reset-btn">Request Re-answer</button>
                <button class="btn btn-success" id="sw-console-release-btn">Release Grades</button>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var CMID          = <?php echo (int)$cm->id; ?>;
    var WB_SESS       = '<?php echo sesskey(); ?>';
    var AJAX_URL      = '<?php echo (new moodle_url('/mod/smartworkbook/ajax.php'))->out(false); ?>';
    var MANUAL_GRADING = <?php echo $effective_manual_grading ? 'true' : 'false'; ?>;
    var PLATFORM_MG_ALLOWED = <?php echo $platform_mg_allowed ? 'true' : 'false'; ?>;

    function ajax(params, cb) {
        params.sesskey = WB_SESS;
        fetch(AJAX_URL, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(params)
        }).then(function (r){ return r.json(); }).then(cb).catch(function (e){ cb({success:false,error:e.message}); });
    }

    // ---- Load submissions ----
    var STATUS_LABELS = {
        'draft':           'Draft',
        'submitted':       'Submitted',
        'ai_marked':       'AI Marked',
        'grades_released': 'Released',
        'reanswer':        'Re-answer'
    };

    function loadSubmissions() {
        ajax({action:'get_submissions', cmid:CMID}, function (data) {
            var tbody   = document.getElementById('sw-sub-tbody');
            var countEl = document.getElementById('sw-sub-count');
            var statSub = document.getElementById('sw-stat-submissions');
            var statAwt = document.getElementById('sw-stat-awaiting');

            if (!data.success || !data.submissions || data.submissions.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5"><div class="sw-empty-state"><div class="sw-empty-state-title">No submissions yet</div><div class="sw-empty-state-sub"><?php echo get_string('nosubmissions', 'smartworkbook'); ?></div></div></td></tr>';
                if (countEl)  countEl.textContent  = '0 students submitted';
                if (statSub)  statSub.textContent   = '0';
                if (statAwt)  statAwt.textContent   = '0';
                return;
            }

            var total    = data.submissions.length;
            var awaiting = data.submissions.filter(function (s){ return s.status === 'submitted'; }).length;
            if (countEl)  countEl.textContent  = total + ' student' + (total !== 1 ? 's' : '');
            if (statSub)  statSub.textContent   = total;
            if (statAwt)  statAwt.textContent   = awaiting;

            tbody.innerHTML = '';
            data.submissions.forEach(function (s) {
                var score = (s.totalmarks !== null && s.maxmarks !== null)
                    ? (parseFloat(s.totalmarks).toFixed(1) + ' / ' + parseFloat(s.maxmarks).toFixed(1))
                    : '&mdash;';
                var submitted = s.timesubmitted ? new Date(s.timesubmitted * 1000).toLocaleDateString('en-AU') : '&mdash;';
                var statusLabel = STATUS_LABELS[s.status] || s.status;
                var actions = '<div class="sw-action-btns">';
                if (MANUAL_GRADING) {
                    var gradeLabel = s.status === 'submitted' ? 'Grade' : 'Review';
                    actions += '<button class="btn btn-sm btn-outline-secondary sw-open-manual" data-sid="' + s.id + '" data-name="' + s.name + '">' + gradeLabel + '</button>';
                } else {
                    actions += '<button class="btn btn-sm btn-outline-secondary sw-open-console" data-sid="' + s.id + '" data-name="' + s.name + '">Review</button>';
                    if (s.status === 'submitted') {
                        actions += '<button class="btn btn-sm btn-primary sw-ai-mark-one" data-sid="' + s.id + '">AI Mark</button>';
                    }
                    if (s.status === 'ai_marked') {
                        actions += '<button class="btn btn-sm btn-success sw-release-one" data-sid="' + s.id + '">Release</button>';
                    }
                }
                actions += '</div>';
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td class="sw-student-name">' + s.name + '</td>' +
                    '<td><span class="sw-sub-status sw-sub-status-' + s.status + '"><span class="sw-sub-status-dot"></span>' + statusLabel + '</span></td>' +
                    '<td class="sw-score-cell">' + score + '</td>' +
                    '<td>' + submitted + '</td>' +
                    '<td>' + actions + '</td>';
                tbody.appendChild(tr);
            });
            // Bind events
            tbody.querySelectorAll('.sw-open-console').forEach(function (btn) {
                btn.addEventListener('click', function () { openConsole(btn.dataset.sid, btn.dataset.name); });
            });
            tbody.querySelectorAll('.sw-open-manual').forEach(function (btn) {
                btn.addEventListener('click', function () { openManualConsole(btn.dataset.sid, btn.dataset.name); });
            });
            tbody.querySelectorAll('.sw-ai-mark-one').forEach(function (btn) {
                btn.addEventListener('click', function () { aiMarkOne(btn.dataset.sid, btn); });
            });
            tbody.querySelectorAll('.sw-release-one').forEach(function (btn) {
                btn.addEventListener('click', function () { releaseOne(btn.dataset.sid, btn); });
            });
        });
    }

    loadSubmissions();

    // ---- How-to quick start card ----
    (function () {
        var card      = document.getElementById('sw-howto-card');
        var body      = document.getElementById('sw-howto-body');
        var toggle    = document.getElementById('sw-howto-toggle');
        var chevron   = document.getElementById('sw-howto-chevron');
        var dismissBtn = document.getElementById('sw-howto-dismiss');
        if (!card || !body || !toggle) return;

        var lsKey = 'sw_howto_dismissed_' + CMID;

        function collapse() {
            body.classList.add('sw-howto-body-hidden');
            chevron.classList.add('sw-howto-chevron-collapsed');
            toggle.setAttribute('aria-expanded', 'false');
        }
        function expand() {
            body.classList.remove('sw-howto-body-hidden');
            chevron.classList.remove('sw-howto-chevron-collapsed');
            toggle.setAttribute('aria-expanded', 'true');
        }

        // Check if permanently dismissed
        try {
            if (localStorage.getItem(lsKey) === '1') {
                card.style.display = 'none';
                return;
            }
        } catch(e) {}

        toggle.addEventListener('click', function (e) {
            if (e.target === dismissBtn) return; // handled separately
            if (body.classList.contains('sw-howto-body-hidden')) {
                expand();
            } else {
                collapse();
            }
        });
        toggle.addEventListener('keydown', function (e) {
            if (e.keyCode === 13 || e.keyCode === 32) {
                e.preventDefault();
                toggle.click();
            }
        });

        if (dismissBtn) {
            dismissBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                try { localStorage.setItem(lsKey, '1'); } catch(ex) {}
                card.style.display = 'none';
            });
        }
    })();

    // ---- File upload + conversion ----
    var fileInput   = document.getElementById('sw-file-input');
    var fileChosen  = document.getElementById('sw-file-chosen');
    var convertBtn  = document.getElementById('sw-convert-btn');
    var progress    = document.getElementById('sw-progress');
    var progressBar = document.getElementById('sw-progress-bar');
    var progressLbl = document.getElementById('sw-progress-label');
    var chosenFile  = null;

    var MAX_UPLOAD_BYTES = 40 * 1024 * 1024; // 40 MB raw — becomes ~53 MB base64, safely under 50 MB server JSON limit
    var fileChosenWrap = document.getElementById('sw-file-chosen-wrap');
    var dropZone       = document.getElementById('sw-drop-zone');

    function showFileChosen(file) {
        if (file.size > MAX_UPLOAD_BYTES) {
            fileChosenWrap.style.display = 'flex';
            fileChosen.style.color = '#ef4444';
            fileChosen.textContent = 'File too large: ' + (file.size / 1024 / 1024).toFixed(1) + ' MB. Max 40 MB.';
            convertBtn.style.display = 'none';
            chosenFile = null;
            fileInput.value = '';
            return;
        }
        chosenFile = file;
        fileChosenWrap.style.display = 'flex';
        fileChosen.style.color = '';
        fileChosen.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(1) + ' MB)';
        convertBtn.style.display = '';
    }

    fileInput.addEventListener('change', function () {
        if (fileInput.files.length === 0) return;
        showFileChosen(fileInput.files[0]);
    });

    if (dropZone) {
        dropZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            dropZone.classList.add('sw-drag-over');
        });
        dropZone.addEventListener('dragleave', function (e) {
            if (!dropZone.contains(e.relatedTarget)) {
                dropZone.classList.remove('sw-drag-over');
            }
        });
        dropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropZone.classList.remove('sw-drag-over');
            var files = e.dataTransfer && e.dataTransfer.files;
            if (files && files.length > 0) {
                showFileChosen(files[0]);
            }
        });
    }

    if (convertBtn) {
        convertBtn.addEventListener('click', function () {
            if (!chosenFile) return;
            var ext = chosenFile.name.split('.').pop().toLowerCase();
            if (ext !== 'docx' && ext !== 'pdf') {
                alert('<?php echo addslashes(get_string("error_filetype", "smartworkbook")); ?>');
                return;
            }
            if (!confirm('This will use 15 credits to convert your workbook with AI. Continue?')) return;

            convertBtn.disabled = true;
            convertBtn.innerHTML = '<span class="sw-spinner"></span> Converting...';
            progress.style.display = 'block';
            progressLbl.textContent = 'Reading file...';
            progressBar.style.width = '10%';

            var reader = new FileReader();
            reader.onload = function (e) {
                progressLbl.textContent = 'Sending to AI...';
                progressBar.style.width = '30%';

                // Chunked base64 encoding — String.fromCharCode.apply() hits
                // V8's call-stack limit for files > ~65 KB and silently truncates.
                var _bytes = new Uint8Array(e.target.result);
                var _str   = '';
                var _CHUNK = 32768;
                for (var _j = 0; _j < _bytes.length; _j += _CHUNK) {
                    _str += String.fromCharCode.apply(null, _bytes.subarray(_j, Math.min(_j + _CHUNK, _bytes.length)));
                }
                var b64 = btoa(_str);

                var form = new FormData();
                form.append('action', 'convert');
                form.append('cmid', CMID);
                form.append('sesskey', WB_SESS);
                form.append('filename', chosenFile.name);
                form.append('filecontent', b64);

                progressBar.style.width = '50%';
                progressLbl.textContent = 'AI is analysing your workbook...';

                fetch(AJAX_URL, {method:'POST', body: new URLSearchParams(Object.fromEntries(form.entries()))})
                    .then(function (r){ return r.json(); })
                    .then(function (data) {
                        progressBar.style.width = '100%';
                        if (data.success) {
                            progressLbl.textContent = data.count + ' questions detected. Reloading...';
                            setTimeout(function (){ window.location.reload(); }, 1200);
                        } else {
                            progressLbl.textContent = 'Error: ' + (data.error || 'Conversion failed.');
                            progressBar.style.background = '#ef4444';
                            convertBtn.disabled = false;
                            convertBtn.innerHTML = 'Retry Conversion';
                        }
                    });
            };
            reader.readAsArrayBuffer(chosenFile);
        });
    }

    // ---- Generate model answers ----
    var genBtn = document.getElementById('sw-gen-answers-btn');
    if (genBtn) {
        genBtn.addEventListener('click', function () {
            if (!confirm('Generate model answers for all questions? This uses 3 credits.')) return;
            genBtn.disabled = true;
            genBtn.innerHTML = '<span class="sw-spinner sw-spinner-dark"></span> Generating...';
            ajax({action:'generate_model_answers', cmid:CMID}, function (data) {
                genBtn.disabled = false;
                genBtn.textContent = 'Generate Model Answers';
                if (data.success) {
                    genBtn.textContent = data.count + ' answers generated. Reloading...';
                    setTimeout(function (){ window.location.reload(); }, 800);
                } else {
                    alert('Error: ' + (data.error || 'Generation failed.'));
                }
            });
        });
    }

    // ---- Drag-to-reorder ----
    (function () {
        var list = document.getElementById('sw-q-list');
        if (!list) return;
        var dragging = null;

        list.addEventListener('dragstart', function (e) {
            var li = e.target.closest('li.sw-q-item');
            if (!li) return;
            dragging = li;
            li.classList.add('sw-q-dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        list.addEventListener('dragend', function () {
            if (dragging) dragging.classList.remove('sw-q-dragging');
            dragging = null;
            list.querySelectorAll('.sw-q-drop-over').forEach(function (el) {
                el.classList.remove('sw-q-drop-over');
            });
            renumberItems();
        });

        list.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var target = e.target.closest('li.sw-q-item');
            if (!target || target === dragging) return;
            list.querySelectorAll('.sw-q-drop-over').forEach(function (el) {
                el.classList.remove('sw-q-drop-over');
            });
            target.classList.add('sw-q-drop-over');
            var rect = target.getBoundingClientRect();
            var mid  = rect.top + rect.height / 2;
            if (e.clientY < mid) {
                list.insertBefore(dragging, target);
            } else {
                list.insertBefore(dragging, target.nextSibling);
            }
        });

        list.addEventListener('dragleave', function (e) {
            var target = e.target.closest('li.sw-q-item');
            if (target) target.classList.remove('sw-q-drop-over');
        });

        list.addEventListener('drop', function (e) {
            e.preventDefault();
        });

        function renumberItems() {
            var qn = 0;
            list.querySelectorAll('.sw-q-item').forEach(function (li) {
                var badge = li.querySelector('.sw-q-item-num');
                if (!badge) return;
                var sel = li.querySelector('.sw-q-type-select');
                var qt = sel ? sel.value : (li.dataset.qtype || '');
                if (qt === 'dochtml') {
                    badge.textContent = 'SEC';
                } else if (qt === 'heading') {
                    badge.textContent = 'HDG';
                } else if (qt === 'image') {
                    badge.textContent = 'IMG';
                } else if (qt === 'video') {
                    badge.textContent = 'VID';
                } else {
                    qn++;
                    badge.textContent = 'Q' + qn;
                }
            });
        }

        // Re-number when type changes too
        list.addEventListener('change', function (e) {
            if (e.target.classList.contains('sw-q-type-select')) {
                var li = e.target.closest('li.sw-q-item');
                if (li) li.dataset.qtype = e.target.value;
                renumberItems();
            }
        });
    })();

    // ---- Delete question ----
    (function () {
        var list = document.getElementById('sw-q-list');
        if (!list) return;
        list.addEventListener('click', function (e) {
            var btn = e.target.closest('.sw-q-delete-btn');
            if (!btn) return;
            var li  = btn.closest('li.sw-q-item');
            if (!li) return;
            var qid = btn.dataset.qid;
            if (!confirm('Delete this row? This cannot be undone.')) return;
            btn.disabled = true;
            ajax({action:'delete_question', cmid:CMID, qid:qid}, function (data) {
                if (data.success) {
                    li.style.transition = 'opacity 0.2s';
                    li.style.opacity = '0';
                    setTimeout(function () {
                        li.parentNode && li.parentNode.removeChild(li);
                        // renumber — matches renumberItems() logic above
                        var qn = 0;
                        document.querySelectorAll('#sw-q-list .sw-q-item').forEach(function (row) {
                            var badge = row.querySelector('.sw-q-item-num');
                            if (!badge) return;
                            var sel  = row.querySelector('.sw-q-type-select');
                            var qt   = (sel ? sel.value : null) || row.dataset.qtype || '';
                            if (qt === 'dochtml') { badge.textContent = 'SEC'; }
                            else if (qt === 'heading') { badge.textContent = 'HDG'; }
                            else if (qt === 'image') { badge.textContent = 'IMG'; }
                            else if (qt === 'video') { badge.textContent = 'VID'; }
                            else { badge.textContent = 'Q' + (++qn); }
                        });
                    }, 220);
                } else {
                    btn.disabled = false;
                    alert('Could not delete: ' + (data.error || 'unknown error'));
                }
            });
        });
    })();

    // ---- Add Question button ----
    var addQBtn = document.getElementById('sw-add-question-btn');
    if (addQBtn) {
        addQBtn.addEventListener('click', function () {
            addQBtn.disabled = true;
            addQBtn.textContent = 'Adding...';
            ajax({action: 'add_question', cmid: CMID}, function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    addQBtn.disabled = false;
                    addQBtn.textContent = '+ Add Question';
                    alert('Could not add question: ' + (data.error || 'unknown error'));
                }
            });
        });
    }

    // ---- Save questions (text, type, label, marks, model answer, rubric, order) ----
    var saveQBtn = document.getElementById('sw-save-questions-btn');
    if (saveQBtn) {
        saveQBtn.addEventListener('click', function () {
            var items = document.querySelectorAll('#sw-q-list .sw-q-item');
            var questions = [];
            items.forEach(function (item) {
                var qid        = item.dataset.qid;
                var typeSelect = item.querySelector('.sw-q-type-select');
                var labelInput = item.querySelector('.sw-q-label-input');
                var textInput  = item.querySelector('.sw-q-text-input');
                var marksInput = item.querySelector('.sw-q-item-marks-input');
                var modelInput = item.querySelector('.sw-q-model-input');
                var rubricInput = item.querySelector('.sw-q-rubric-input');
                var qtype = typeSelect ? typeSelect.value : item.dataset.qtype;
                // For table type: serialize visual builder; for others: use textarea value.
                var modelAnswerVal = '';
                if (qtype === 'table' && window._swSerializeTable) {
                    modelAnswerVal = window._swSerializeTable(item) || (modelInput ? modelInput.value : '');
                } else {
                    modelAnswerVal = modelInput ? modelInput.value : '';
                }
                // For image/video types the questiontext lives in dedicated inputs, not the RTE.
                var questiontextVal = '';
                if (qtype === 'image') {
                    var imgData = item.querySelector('.sw-img-data-input');
                    questiontextVal = imgData ? imgData.value : '';
                } else if (qtype === 'video') {
                    var vidUrl = item.querySelector('.sw-video-url-input');
                    questiontextVal = vidUrl ? vidUrl.value.trim() : '';
                } else {
                    questiontextVal = textInput ? (textInput.innerHTML || '') : '';
                }
                questions.push({
                    id:           qid,
                    qtype:        qtype,
                    label:        labelInput  ? labelInput.value            : '',
                    questiontext: questiontextVal,
                    marks:        marksInput  ? (parseFloat(marksInput.value) || 1) : 1,
                    model_answer: modelAnswerVal,
                    rubric_notes: rubricInput ? rubricInput.value           : '',
                });
            });
            saveQBtn.disabled = true;
            saveQBtn.innerHTML = '<span class="sw-spinner sw-spinner-dark"></span> Saving...';
            ajax({action:'save_questions', cmid:CMID, questions:JSON.stringify(questions)}, function (data) {
                saveQBtn.disabled = false;
                saveQBtn.textContent = data.success ? 'Saved!' : 'Save Failed';
                setTimeout(function (){ saveQBtn.textContent = 'Save Changes'; }, 2000);
            });
        });
    }

    // ---- RTE toolbar binding (question text contenteditable) ----
    (function () {
        function bindRteToolbar(wrap) {
            var editor  = wrap.querySelector('.sw-q-rte-editor');
            var toolbar = wrap.querySelector('.sw-rte-toolbar');
            if (!editor || !toolbar) { return; }

            // Toolbar button clicks — preventDefault keeps focus in editor.
            toolbar.querySelectorAll('.sw-rte-btn[data-cmd]').forEach(function (btn) {
                btn.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    var cmd = btn.dataset.cmd;
                    var val = btn.dataset.val || null;
                    document.execCommand(cmd, false, val);
                    editor.focus();
                });
            });

            // Colour picker — fires on input so live preview works.
            var colorInput = toolbar.querySelector('.sw-rte-color-input');
            if (colorInput) {
                colorInput.addEventListener('input', function () {
                    document.execCommand('foreColor', false, colorInput.value);
                    editor.focus();
                });
            }

            // Focus styling — add/remove class on the wrapper.
            editor.addEventListener('focus', function () {
                wrap.classList.add('sw-rte-focused');
            });
            editor.addEventListener('blur', function (e) {
                // Don't remove focus class when the user clicks a toolbar button.
                if (toolbar.contains(e.relatedTarget)) { return; }
                wrap.classList.remove('sw-rte-focused');
            });

            // Placeholder via data-placeholder attribute.
            function updatePlaceholder() {
                if (editor.innerHTML === '' || editor.innerHTML === '<br>') {
                    editor.classList.add('sw-rte-empty');
                } else {
                    editor.classList.remove('sw-rte-empty');
                }
            }
            editor.addEventListener('input', updatePlaceholder);
            updatePlaceholder();
        }

        document.querySelectorAll('.sw-q-rte-wrap').forEach(bindRteToolbar);
    })();

    // ---- Image zone: paste, drag-drop, file upload ----
    (function () {
        function loadImageFile(file, imgZone) {
            if (!file || !file.type.match(/^image\//)) { return; }
            var reader = new FileReader();
            reader.onload = function (e) {
                var dataUri = e.target.result;
                var preview = imgZone.querySelector('.sw-img-preview');
                var hidden  = imgZone.querySelector('.sw-img-data-input');
                var status  = imgZone.querySelector('.sw-img-status');
                if (preview) { preview.src = dataUri; preview.style.display = 'block'; }
                if (hidden)  { hidden.value = dataUri; }
                if (status)  { status.textContent = 'Image loaded (\u2713)'; }
            };
            reader.readAsDataURL(file);
        }

        function bindImgZone(zone) {
            var pasteArea  = zone.querySelector('.sw-img-paste-area');
            var fileInput  = zone.querySelector('.sw-img-file-input');

            // Paste from clipboard (must click area first to focus it)
            if (pasteArea) {
                pasteArea.addEventListener('paste', function (e) {
                    var items = (e.clipboardData || e.originalEvent.clipboardData).items;
                    for (var i = 0; i < items.length; i++) {
                        if (items[i].kind === 'file') {
                            loadImageFile(items[i].getAsFile(), zone);
                            e.preventDefault();
                            return;
                        }
                    }
                });
                // Global paste fires when pasteArea is focused
                pasteArea.addEventListener('dragover', function (e) { e.preventDefault(); });
                pasteArea.addEventListener('drop', function (e) {
                    e.preventDefault();
                    var files = e.dataTransfer.files;
                    if (files.length) { loadImageFile(files[0], zone); }
                });
            }

            // File picker
            if (fileInput) {
                fileInput.addEventListener('change', function () {
                    if (fileInput.files.length) { loadImageFile(fileInput.files[0], zone); }
                });
            }
        }

        document.querySelectorAll('.sw-img-zone').forEach(bindImgZone);
    })();

    // ---- Video zone: YouTube URL + live preview ----
    (function () {
        function ytIdFromUrl(url) {
            var m = url.match(/(?:youtube\.com\/(?:watch\?(?:[^&\s]*&)*v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_\-]{11})/);
            return m ? m[1] : null;
        }

        function bindVidZone(zone) {
            var input   = zone.querySelector('.sw-video-url-input');
            var preview = zone.querySelector('.sw-video-preview');
            if (!input || !preview) { return; }

            input.addEventListener('input', function () {
                var vid = ytIdFromUrl(input.value.trim());
                if (vid) {
                    preview.innerHTML =
                        '<div class="sw-video-responsive">' +
                        '<iframe class="sw-youtube-embed" src="https://www.youtube.com/embed/' + vid + '" frameborder="0" allowfullscreen></iframe>' +
                        '</div>';
                } else {
                    preview.innerHTML = '';
                }
            });
        }

        document.querySelectorAll('.sw-video-zone').forEach(bindVidZone);
    })();

    // ---- Structured Table Builder ----
    (function () {
        var DEFAULT_HDR_BG = '#334155';

        function escH(s) {
            return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function newTable(cols, rows) {
            var headers = [];
            for (var c = 0; c < cols; c++) { headers.push('Column ' + (c + 1)); }
            var rowsArr = [];
            for (var r = 0; r < rows; r++) {
                var row = [];
                for (var c = 0; c < cols; c++) { row.push({v: '', e: c > 0}); }
                rowsArr.push(row);
            }
            return {sw_table: true, header_bg: DEFAULT_HDR_BG, headers: headers, rows: rowsArr};
        }

        function renderGrid(questionItem) {
            var teWrap  = questionItem.querySelector('.sw-te-wrap');
            if (!teWrap || !teWrap._swTable) { return; }
            var tbl     = teWrap._swTable;
            var hdrBg   = tbl.header_bg || DEFAULT_HDR_BG;
            var numCols = tbl.headers.length;

            // Update colour picker to match stored colour.
            var colorInp = questionItem.querySelector('.sw-te-hdr-color');
            if (colorInp) { colorInp.value = hdrBg; }

            var html = '<table class="sw-te-grid">';
            // Header row
            html += '<thead><tr>';
            html += '<td class="sw-te-corner" style="background:' + escH(hdrBg) + '"></td>';
            for (var ci = 0; ci < numCols; ci++) {
                html += '<th class="sw-te-th" style="background:' + escH(hdrBg) + '">';
                html += '<input class="sw-te-hdr-inp" data-col="' + ci + '" value="' + escH(tbl.headers[ci]) + '" placeholder="Col ' + (ci + 1) + '">';
                html += '</th>';
            }
            html += '</tr></thead>';
            // Data rows
            html += '<tbody>';
            for (var ri = 0; ri < tbl.rows.length; ri++) {
                var row = tbl.rows[ri];
                html += '<tr>';
                html += '<td class="sw-te-row-ctrl">';
                html += '<button type="button" class="sw-te-del-row-btn" data-row="' + ri + '" title="Remove row">&#215;</button>';
                html += '</td>';
                for (var ci2 = 0; ci2 < row.length; ci2++) {
                    var cell = row[ci2];
                    var editable = !!cell.e;
                    html += '<td class="sw-te-cell ' + (editable ? 'sw-te-cell-edit' : 'sw-te-cell-fixed') + '">';
                    html += '<input class="sw-te-cell-inp" data-row="' + ri + '" data-col="' + ci2 + '"'
                        + ' value="' + escH(cell.v || '') + '"'
                        + ' placeholder="' + (editable ? 'Student fills\u2026' : 'Pre-filled value\u2026') + '">';
                    html += '<button type="button" class="sw-te-toggle-btn" data-row="' + ri + '" data-col="' + ci2 + '"'
                        + ' title="' + (editable ? 'Make pre-filled' : 'Make student-editable') + '">';
                    html += editable ? '&#9999;' : '&#128274;';
                    html += '</button>';
                    html += '</td>';
                }
                html += '</tr>';
            }
            html += '</tbody></table>';

            teWrap.innerHTML = html;

            // Bind header inputs
            teWrap.querySelectorAll('.sw-te-hdr-inp').forEach(function (inp) {
                inp.addEventListener('input', function () {
                    tbl.headers[parseInt(inp.dataset.col, 10)] = inp.value;
                });
            });
            // Bind cell inputs
            teWrap.querySelectorAll('.sw-te-cell-inp').forEach(function (inp) {
                inp.addEventListener('input', function () {
                    tbl.rows[parseInt(inp.dataset.row, 10)][parseInt(inp.dataset.col, 10)].v = inp.value;
                });
            });
            // Toggle lock/edit
            teWrap.querySelectorAll('.sw-te-toggle-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var r = parseInt(btn.dataset.row, 10);
                    var c = parseInt(btn.dataset.col, 10);
                    tbl.rows[r][c].e = !tbl.rows[r][c].e;
                    renderGrid(questionItem);
                });
            });
            // Delete row
            teWrap.querySelectorAll('.sw-te-del-row-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    tbl.rows.splice(parseInt(btn.dataset.row, 10), 1);
                    renderGrid(questionItem);
                });
            });
        }

        function initTableEditor(questionItem) {
            var teWrap = questionItem.querySelector('.sw-te-wrap');
            if (!teWrap) { return; }

            // Try to load existing JSON from model_answer textarea.
            var modelInput = questionItem.querySelector('.sw-q-model-input');
            var tbl = null;
            if (modelInput && modelInput.value) {
                try {
                    var p = JSON.parse(modelInput.value);
                    if (p && p.sw_table) { tbl = p; }
                } catch(e) {}
            }
            if (!tbl) { tbl = newTable(3, 4); }

            teWrap._swTable = tbl;
            renderGrid(questionItem);

            // Colour picker live-update
            var colorInp = questionItem.querySelector('.sw-te-hdr-color');
            if (colorInp) {
                colorInp.value = tbl.header_bg || DEFAULT_HDR_BG;
                colorInp.addEventListener('input', function () {
                    tbl.header_bg = colorInp.value;
                    teWrap.querySelectorAll('.sw-te-th, .sw-te-corner').forEach(function (el) {
                        el.style.background = colorInp.value;
                    });
                });
            }

            // Control buttons (add/del row/col)
            var controls = questionItem.querySelector('.sw-te-controls');
            if (controls) {
                controls.addEventListener('click', function (e) {
                    var btn = e.target.closest('[data-action]');
                    if (!btn) { return; }
                    var action = btn.dataset.action;
                    if (action === 'add-row') {
                        tbl.rows.push(tbl.headers.map(function (_, ci) { return {v: '', e: ci > 0}; }));
                        renderGrid(questionItem);
                    } else if (action === 'del-row') {
                        if (tbl.rows.length > 1) { tbl.rows.pop(); renderGrid(questionItem); }
                    } else if (action === 'add-col') {
                        tbl.headers.push('Column ' + (tbl.headers.length + 1));
                        tbl.rows.forEach(function (row) { row.push({v: '', e: true}); });
                        renderGrid(questionItem);
                    } else if (action === 'del-col') {
                        if (tbl.headers.length > 1) {
                            tbl.headers.pop();
                            tbl.rows.forEach(function (row) { row.pop(); });
                            renderGrid(questionItem);
                        }
                    }
                });
            }
        }

        function serializeTableEditor(questionItem) {
            var teWrap = questionItem.querySelector('.sw-te-wrap');
            if (!teWrap || !teWrap._swTable) { return null; }
            return JSON.stringify(teWrap._swTable);
        }

        // Expose serializer for the save-questions handler.
        window._swSerializeTable = serializeTableEditor;

        // Show/hide table builder / image zone / video zone based on qtype.
        function applyQtype(questionItem, qtype) {
            var plainLbl  = questionItem.querySelector('.sw-model-plain-lbl');
            var tableLbl  = questionItem.querySelector('.sw-model-table-lbl');
            var plainTa   = questionItem.querySelector('.sw-q-model-input');
            var teWrap    = questionItem.querySelector('.sw-te-wrap');
            var teCtrls   = questionItem.querySelector('.sw-te-controls');
            var teHint    = questionItem.querySelector('.sw-te-hint');
            var rteEditor = questionItem.querySelector('.sw-q-rte-editor');
            var rteWrap   = questionItem.querySelector('.sw-q-rte-wrap');
            var imgZone   = questionItem.querySelector('.sw-img-zone');
            var vidZone   = questionItem.querySelector('.sw-video-zone');
            var subGrid   = questionItem.querySelector('.sw-q-sub-grid');
            var isTable   = (qtype === 'table');
            var isImage   = (qtype === 'image');
            var isVideo   = (qtype === 'video');
            var isDisplay = isImage || isVideo;

            // RTE / question text: hide for image/video (content is managed via their zones)
            if (rteEditor) { rteEditor.style.display = isDisplay ? 'none' : ''; }

            // Model answer / rubric sub-grid: hide for image/video (display-only blocks)
            if (subGrid)   { subGrid.style.display   = isDisplay ? 'none' : ''; }

            // Image zone
            if (imgZone)   { imgZone.style.display   = isImage ? '' : 'none'; }

            // Video zone
            if (vidZone)   { vidZone.style.display   = isVideo ? '' : 'none'; }

            // Table builder show/hide
            if (!isDisplay) {
                if (plainLbl)  { plainLbl.style.display  = isTable ? 'none' : ''; }
                if (tableLbl)  { tableLbl.style.display  = isTable ? '' : 'none'; }
                if (plainTa)   { plainTa.style.display   = isTable ? 'none' : ''; }
                if (teWrap)    { teWrap.style.display     = isTable ? '' : 'none'; }
                if (teCtrls)   { teCtrls.style.display    = isTable ? '' : 'none'; }
                if (teHint)    { teHint.style.display     = isTable ? '' : 'none'; }
            }

            if (isTable && teWrap && !teWrap._swTable) {
                initTableEditor(questionItem);
            }
            if (!isTable && plainTa) {
                try {
                    if (JSON.parse(plainTa.value) && JSON.parse(plainTa.value).sw_table) {
                        plainTa.value = '';
                    }
                } catch(e) {}
            }
        }

        // Init existing table-type questions on page load.
        document.querySelectorAll('#sw-q-list .sw-q-item').forEach(function (item) {
            var typeSelect = item.querySelector('.sw-q-type-select');
            if (typeSelect && typeSelect.value === 'table') {
                initTableEditor(item);
            }
        });

        // Listen for qtype dropdown changes.
        var qList = document.getElementById('sw-q-list');
        if (qList) {
            qList.addEventListener('change', function (e) {
                if (!e.target.classList.contains('sw-q-type-select')) { return; }
                var item = e.target.closest('.sw-q-item');
                if (item) { applyQtype(item, e.target.value); }
            });
        }

    })();

    // ---- Publish / unpublish ----
    var publishBtn   = document.getElementById('sw-publish-btn');
    var unpublishBtn = document.getElementById('sw-unpublish-btn');

    if (publishBtn) {
        publishBtn.addEventListener('click', function () {
            if (!confirm('Publish this workbook? Students will be able to access and submit answers.')) return;
            publishBtn.disabled = true;
            ajax({action:'set_status', cmid:CMID, status:'published'}, function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    publishBtn.disabled = false;
                    alert('Error: ' + (data.error || 'Could not publish.'));
                }
            });
        });
    }
    if (unpublishBtn) {
        unpublishBtn.addEventListener('click', function () {
            if (!confirm('Unpublish? Students will no longer be able to access this workbook.')) return;
            unpublishBtn.disabled = true;
            ajax({action:'set_status', cmid:CMID, status:'ready'}, function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    unpublishBtn.disabled = false;
                    alert('Error: ' + (data.error || 'Could not unpublish.'));
                }
            });
        });
    }

    // ---- AI mark all ----
    var aiMarkAllBtn = document.getElementById('sw-ai-mark-all-btn');
    if (aiMarkAllBtn) {
        aiMarkAllBtn.addEventListener('click', function () {
            ajax({action:'get_submissions', cmid:CMID}, function (data) {
                if (!data.success) return;
                var submitted = data.submissions.filter(function (s){ return s.status === 'submitted'; });
                if (submitted.length === 0) { alert('No submitted workbooks to mark.'); return; }
                if (!confirm('AI mark ' + submitted.length + ' submissions? This uses ' + (submitted.length * 5) + ' credits.')) return;
                aiMarkAllBtn.disabled = true;
                aiMarkAllBtn.innerHTML = '<span class="sw-spinner"></span> Marking...';
                var done = 0;
                function markNext(i) {
                    if (i >= submitted.length) {
                        aiMarkAllBtn.disabled = false;
                        aiMarkAllBtn.innerHTML = 'AI Mark All Submitted<small style="display:block;font-size:0.7rem;font-weight:normal;">(5 credits each)</small>';
                        loadSubmissions();
                        return;
                    }
                    ajax({action:'ai_mark', cmid:CMID, submissionid:submitted[i].id}, function () {
                        done++;
                        aiMarkAllBtn.innerHTML = '<span class="sw-spinner"></span> Marked ' + done + '/' + submitted.length;
                        markNext(i + 1);
                    });
                }
                markNext(0);
            });
        });
    }

    // ---- AI mark one ----
    function aiMarkOne(sid, btn) {
        if (!confirm('AI mark this submission? This uses 5 credits.')) return;
        var orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="sw-spinner"></span>';
        ajax({action:'ai_mark', cmid:CMID, submissionid:sid}, function (data) {
            btn.disabled = false;
            btn.innerHTML = orig;
            if (data.success) {
                loadSubmissions();
            } else {
                alert('Marking error: ' + (data.error || 'Unknown'));
            }
        });
    }

    // ---- Release one ----
    function releaseOne(sid, btn) {
        if (!confirm('Release grades to this student? They will see their marks and feedback.')) return;
        btn.disabled = true;
        ajax({action:'release_grades', cmid:CMID, submissionid:sid}, function (data) {
            btn.disabled = false;
            if (data.success) {
                loadSubmissions();
            } else {
                alert('Error: ' + (data.error || 'Could not release.'));
            }
        });
    }

    // ---- Marking console ----
    var overlay       = document.getElementById('sw-console-overlay');
    var closeBtn      = document.getElementById('sw-console-close');
    var studentName   = document.getElementById('sw-console-student-name');
    var totalsDisplay = document.getElementById('sw-totals-display');
    var qContainer    = document.getElementById('sw-console-questions');
    var aiMarkConsBtn = document.getElementById('sw-console-ai-mark-btn');
    var releaseBtn    = document.getElementById('sw-console-release-btn');
    var currentSid    = null;

    closeBtn.addEventListener('click', function () {
        overlay.style.display = 'none';
        loadSubmissions();
    });

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) { overlay.style.display = 'none'; loadSubmissions(); }
    });

    function openConsole(sid, name) {
        currentSid = sid;
        studentName.textContent = 'Student: ' + name;
        overlay.style.display = 'block';
        qContainer.innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af;">Loading...</div>';
        ajax({action:'get_marks', cmid:CMID, submissionid:sid}, function (data) {
            if (!data.success) {
                qContainer.innerHTML = '<div style="color:#ef4444;padding:20px;">Failed to load: ' + (data.error||'unknown') + '</div>';
                return;
            }
            var earned = parseFloat(data.submission.total_marks || 0).toFixed(1);
            var max    = parseFloat(data.submission.max_marks || 0).toFixed(1);
            totalsDisplay.textContent = earned + ' / ' + max;

            var pctEl = document.getElementById('sw-totals-pct');
            if (pctEl && max > 0) {
                pctEl.textContent = Math.round((earned / max) * 100) + '%';
            }

            var html = '';
            data.questions.forEach(function (q) {
                if (q.qtype === 'heading') return;
                var aiMark = q.ai_mark !== null ? q.ai_mark : '';
                var tMark  = q.teacher_mark !== null ? q.teacher_mark : (aiMark !== '' ? aiMark : '');
                var comment = q.teacher_comment || q.ai_comment || '';
                var confClass = q.ai_confidence ? 'sw-ai-conf-' + q.ai_confidence : '';

                html += '<div class="sw-mark-question" data-qid="' + q.id + '">';
                html += '  <div class="sw-mark-q-header">';
                html += '    <div class="sw-mark-q-text">' + (q.label ? '<strong>' + q.label + '</strong> &mdash; ' : '') + q.questiontext + '</div>';
                html += '    <div class="sw-mark-q-maxmarks">/ ' + q.marks + ' marks</div>';
                html += '  </div>';
                html += '  <div class="sw-mark-answer-block"><div class="sw-mark-answer-label">Student answer</div>' + (q.student_answer || '<em style="color:#94a3b8">No answer provided</em>') + '</div>';
                if (q.model_answer) {
                    html += '  <div class="sw-mark-answer-block sw-mark-answer-block-model"><div class="sw-mark-answer-label sw-mark-answer-label-model">Model answer</div>' + q.model_answer + '</div>';
                }
                if (aiMark !== '') {
                    var confBadge = q.ai_confidence ? '<span class="sw-ai-confidence ' + confClass + '">' + q.ai_confidence + '</span>' : '';
                    html += '  <div class="sw-ai-suggestion"><div class="sw-ai-suggestion-label">AI Suggestion</div>';
                    html += '  <span class="sw-ai-mark-val">' + aiMark + ' / ' + q.marks + '</span>' + confBadge;
                    if (q.ai_comment) html += '  <div style="font-size:0.84rem;color:#6b7280;margin-top:6px;line-height:1.5;">' + q.ai_comment + '</div>';
                    html += '  </div>';
                }
                html += '  <div class="sw-mark-controls">';
                html += '    <span class="sw-mark-controls-label">Mark</span>';
                html += '    <input type="number" class="sw-mark-input" data-qid="' + q.id + '" value="' + tMark + '" min="0" max="' + q.marks + '" step="0.5" placeholder="0">';
                html += '    <textarea class="sw-comment-input" rows="2" data-qid="' + q.id + '" placeholder="Add feedback comment...">' + comment + '</textarea>';
                html += '    <select class="sw-mark-status-select sw-mark-status" data-qid="' + q.id + '">';
                html += '      <option value="approved"'  + (q.mark_status==='approved'  ?' selected':'') + '>Approved</option>';
                html += '      <option value="overridden"'+ (q.mark_status==='overridden'?' selected':'') + '>Override</option>';
                html += '      <option value="reanswer"'  + (q.mark_status==='reanswer'  ?' selected':'') + '>Needs re-answer</option>';
                html += '    </select>';
                html += '    <button class="btn btn-sm btn-secondary sw-save-mark-btn" data-qid="' + q.id + '">Save</button>';
                html += '  </div>';
                html += '</div>';
            });
            qContainer.innerHTML = html || '<p style="color:#9ca3af;">No markable questions.</p>';

            // Bind save mark
            qContainer.querySelectorAll('.sw-save-mark-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var qid     = btn.dataset.qid;
                    var markEl  = qContainer.querySelector('.sw-mark-input[data-qid="' + qid + '"]');
                    var commEl  = qContainer.querySelector('.sw-comment-input[data-qid="' + qid + '"]');
                    var statEl  = qContainer.querySelector('.sw-mark-status[data-qid="' + qid + '"]');
                    var orig = btn.innerHTML;
                    btn.disabled = true;
                    ajax({
                        action: 'save_mark', cmid: CMID, submissionid: currentSid,
                        questionid: qid, mark: markEl.value,
                        comment: commEl.value, mark_status: statEl.value
                    }, function (data) {
                        btn.disabled = false;
                        if (data.success) {
                            btn.textContent = 'Saved';
                            totalsDisplay.textContent = parseFloat(data.total_earned).toFixed(1) + ' / ' + parseFloat(data.total_max).toFixed(1);
                            setTimeout(function (){ btn.innerHTML = orig; }, 1500);
                        } else {
                            btn.innerHTML = orig;
                            alert('Save failed: ' + (data.error||'unknown'));
                        }
                    });
                });
            });
        });
    }

    // Console: request re-answer
    var resetBtn = document.getElementById('sw-console-reset-btn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (!currentSid) return;
            if (!confirm('Send this workbook back to the student for re-answering?\n\nOnly questions you flagged as "Needs re-answer" will be unlocked. The student will be notified and must resubmit.')) return;
            resetBtn.disabled = true;
            ajax({action:'reset_submission', cmid:CMID, submissionid:currentSid}, function (data) {
                resetBtn.disabled = false;
                if (data.success) {
                    overlay.style.display = 'none';
                    loadSubmissions();
                    alert('Done — ' + data.flagged + ' question(s) sent back for re-answer. The student can now edit and resubmit.');
                } else {
                    alert('Could not reset: ' + (data.error || 'Unknown error.'));
                }
            });
        });
    }

    // Console: AI mark this
    if (aiMarkConsBtn) {
        aiMarkConsBtn.addEventListener('click', function () {
            if (!currentSid) return;
            if (!confirm('AI mark this submission? 5 credits will be deducted.')) return;
            aiMarkConsBtn.disabled = true;
            aiMarkConsBtn.innerHTML = '<span class="sw-spinner sw-spinner-dark"></span> Marking...';
            ajax({action:'ai_mark', cmid:CMID, submissionid:currentSid}, function (data) {
                aiMarkConsBtn.disabled = false;
                aiMarkConsBtn.textContent = 'AI Mark This';
                if (data.success) {
                    openConsole(currentSid, studentName.textContent.replace('Student: ',''));
                } else {
                    alert('Marking error: ' + (data.error||'Unknown'));
                }
            });
        });
    }

    // Console: release
    if (releaseBtn) {
        releaseBtn.addEventListener('click', function () {
            if (!currentSid) return;
            if (!confirm('Release grades to this student? They will see marks and comments.')) return;
            releaseBtn.disabled = true;
            ajax({action:'release_grades', cmid:CMID, submissionid:currentSid}, function (data) {
                releaseBtn.disabled = false;
                if (data.success) {
                    overlay.style.display = 'none';
                    loadSubmissions();
                    alert('Grades released successfully.');
                } else {
                    alert('Error: ' + (data.error||'Could not release.'));
                }
            });
        });
    }
    // ---- Workbook settings card ----
    var studentNameToggle = document.getElementById('sw-setting-studentname');
    var groupMembersSelect = document.getElementById('sw-setting-groupmembers');
    var settingsSaved = document.getElementById('sw-settings-saved');

    var manualGradingToggle = document.getElementById('sw-setting-manualgrading');

    function saveSettings() {
        if (settingsSaved) { settingsSaved.style.display = 'none'; }
        ajax({
            action:          'save_settings',
            cmid:            CMID,
            showstudentname: (studentNameToggle && studentNameToggle.checked) ? 1 : 0,
            numgroupmembers: groupMembersSelect ? parseInt(groupMembersSelect.value, 10) : 0,
            manualgrading:   (PLATFORM_MG_ALLOWED && manualGradingToggle && manualGradingToggle.checked) ? 1 : 0
        }, function (data) {
            if (data.success && settingsSaved) {
                settingsSaved.style.display = 'inline';
                setTimeout(function (){ settingsSaved.style.display = 'none'; }, 2000);
                // Reflect mode change immediately without full page reload
                if (typeof data.manual_grading !== 'undefined') {
                    MANUAL_GRADING = (data.manual_grading == 1);
                    loadSubmissions();
                }
            }
        });
    }

    function syncPreviewToSettings() {
        var pvSname  = document.getElementById('sw-pv-studentname-block');
        var pvGmBlock = document.getElementById('sw-pv-groupmembers-block');
        var numGm = groupMembersSelect ? parseInt(groupMembersSelect.value, 10) : 0;
        if (pvSname) {
            pvSname.style.display = (studentNameToggle && studentNameToggle.checked) ? '' : 'none';
        }
        if (pvGmBlock) {
            pvGmBlock.style.display = numGm > 0 ? '' : 'none';
            for (var gmi = 1; gmi <= 6; gmi++) {
                var gmRow = document.getElementById('sw-pv-gm-row-' + gmi);
                if (gmRow) { gmRow.style.display = gmi <= numGm ? '' : 'none'; }
            }
        }
    }

    if (studentNameToggle) {
        studentNameToggle.addEventListener('change', function () { syncPreviewToSettings(); saveSettings(); });
    }
    if (groupMembersSelect) {
        groupMembersSelect.addEventListener('change', function () { syncPreviewToSettings(); saveSettings(); });
    }
    if (manualGradingToggle) {
        manualGradingToggle.addEventListener('change', function () { saveSettings(); });
    }

    // ---- Manual grading console ----
    var manualOverlay    = document.getElementById('sw-manual-overlay');
    var manualClose      = document.getElementById('sw-manual-close');
    var manualStudentName = document.getElementById('sw-manual-student-name');
    var manualQContainer = document.getElementById('sw-manual-questions');
    var manualTotalsDisplay = document.getElementById('sw-manual-totals-display');
    var manualTotalsPct  = document.getElementById('sw-manual-totals-pct');
    var manualSaveBtn    = document.getElementById('sw-manual-save-btn');
    var manualCurrentSid = null;
    var manualMaxMap     = {};

    if (manualClose) {
        manualClose.addEventListener('click', function () {
            manualOverlay.style.display = 'none';
            loadSubmissions();
        });
    }
    if (manualOverlay) {
        manualOverlay.addEventListener('click', function (e) {
            if (e.target === manualOverlay) { manualOverlay.style.display = 'none'; loadSubmissions(); }
        });
    }

    function manualRecalcTotal() {
        var total = 0;
        var max   = 0;
        manualQContainer.querySelectorAll('.sw-checklist-mark-input').forEach(function (inp) {
            var qid  = inp.dataset.qid;
            var val  = parseFloat(inp.value) || 0;
            total   += val;
            max     += (manualMaxMap[qid] || 0);
        });
        if (manualTotalsDisplay) manualTotalsDisplay.textContent = total.toFixed(1) + ' / ' + max.toFixed(1);
        if (manualTotalsPct)    manualTotalsPct.textContent = max > 0 ? Math.round((total / max) * 100) + '%' : '';
    }

    function openManualConsole(sid, name) {
        manualCurrentSid = sid;
        manualStudentName.textContent = 'Student: ' + name;
        manualOverlay.style.display = 'block';
        manualQContainer.innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af;">Loading...</div>';
        ajax({action:'get_marks', cmid:CMID, submissionid:sid}, function (data) {
            if (!data.success) {
                manualQContainer.innerHTML = '<div style="color:#ef4444;padding:20px;">Failed to load: ' + (data.error||'unknown') + '</div>';
                return;
            }
            manualMaxMap = {};
            var html = '';
            data.questions.forEach(function (q) {
                if (q.qtype === 'heading' || q.qtype === 'dochtml') return;
                manualMaxMap[q.id] = q.marks;
                var tMark = q.teacher_mark !== null ? q.teacher_mark : '';
                var comment = q.teacher_comment || '';
                var isFullMarks = (tMark !== '' && parseFloat(tMark) >= parseFloat(q.marks));

                html += '<div class="sw-checklist-row" data-qid="' + q.id + '">';

                // Question header
                html += '<div class="sw-checklist-q-header">';
                html += '  <div class="sw-checklist-q-text">' + (q.label ? '<strong>' + q.label + '</strong> &mdash; ' : '') + q.questiontext + '</div>';
                html += '  <div class="sw-checklist-q-max">/ ' + q.marks + '</div>';
                html += '</div>';

                // Student answer
                if (q.student_answer) {
                    html += '<div class="sw-checklist-answer">';
                    html += '  <div class="sw-checklist-answer-label">Student answer</div>';
                    html += '  <div class="sw-checklist-answer-text">' + q.student_answer + '</div>';
                    html += '</div>';
                } else {
                    html += '<div class="sw-checklist-answer sw-checklist-answer-empty"><em>No answer provided</em></div>';
                }

                // Mark controls
                html += '<div class="sw-checklist-mark-row">';
                html += '  <label class="sw-checklist-full-label">';
                html += '    <input type="checkbox" class="sw-checklist-full-cb" data-qid="' + q.id + '" data-max="' + q.marks + '"' + (isFullMarks ? ' checked' : '') + '>';
                html += '    Full marks (' + q.marks + ')';
                html += '  </label>';
                html += '  <div class="sw-checklist-partial">';
                html += '    <span class="sw-checklist-partial-label">Awarded</span>';
                html += '    <input type="number" class="sw-checklist-mark-input" data-qid="' + q.id + '" value="' + (tMark !== '' ? parseFloat(tMark).toFixed(1) : '0.0') + '" min="0" max="' + q.marks + '" step="0.5"' + (isFullMarks ? ' disabled' : '') + '>';
                html += '  </div>';
                html += '</div>';

                // Comment
                html += '<textarea class="sw-checklist-comment" data-qid="' + q.id + '" rows="2" placeholder="Optional feedback comment...">' + comment + '</textarea>';

                html += '</div>';
            });

            manualQContainer.innerHTML = html || '<p style="color:#9ca3af;padding:20px;">No markable questions found.</p>';

            // Bind full-marks checkbox
            manualQContainer.querySelectorAll('.sw-checklist-full-cb').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var qid    = cb.dataset.qid;
                    var maxVal = parseFloat(cb.dataset.max) || 0;
                    var markInp = manualQContainer.querySelector('.sw-checklist-mark-input[data-qid="' + qid + '"]');
                    if (cb.checked) {
                        markInp.value   = maxVal.toFixed(1);
                        markInp.disabled = true;
                    } else {
                        markInp.value   = '0.0';
                        markInp.disabled = false;
                        markInp.focus();
                    }
                    manualRecalcTotal();
                });
            });

            // Bind mark input live total
            manualQContainer.querySelectorAll('.sw-checklist-mark-input').forEach(function (inp) {
                inp.addEventListener('input', function () { manualRecalcTotal(); });
            });

            manualRecalcTotal();
        });
    }

    if (manualSaveBtn) {
        manualSaveBtn.addEventListener('click', function () {
            if (!manualCurrentSid) return;
            var marks = [];
            manualQContainer.querySelectorAll('.sw-checklist-row').forEach(function (row) {
                var qid     = row.dataset.qid;
                var markInp = row.querySelector('.sw-checklist-mark-input');
                var commentEl = row.querySelector('.sw-checklist-comment');
                if (!markInp) return;
                marks.push({
                    questionid: parseInt(qid, 10),
                    mark:       parseFloat(markInp.value) || 0,
                    comment:    commentEl ? commentEl.value : ''
                });
            });
            if (marks.length === 0) { alert('No questions to grade.'); return; }
            if (!confirm('Save grades and release to student? This will update their Moodle gradebook.')) return;
            manualSaveBtn.disabled = true;
            manualSaveBtn.innerHTML = '<span class="sw-spinner"></span> Saving...';
            ajax({
                action:       'manual_mark_submission',
                cmid:         CMID,
                submissionid: manualCurrentSid,
                marks:        JSON.stringify(marks)
            }, function (data) {
                manualSaveBtn.disabled = false;
                manualSaveBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:5px;"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg> Save &amp; Release Grades';
                if (data.success) {
                    manualOverlay.style.display = 'none';
                    loadSubmissions();
                    alert('Grades saved and released to student. Total: ' + parseFloat(data.total_earned).toFixed(1) + ' / ' + parseFloat(data.total_max).toFixed(1));
                } else {
                    alert('Error saving grades: ' + (data.error || 'Unknown error.'));
                }
            });
        });
    }

    // ---- Student preview overlay ----
    var previewOverlay = document.getElementById('sw-student-preview-overlay');
    var viewStudentBtn = document.getElementById('sw-view-student-btn');
    var previewClose   = document.getElementById('sw-student-preview-close');

    if (viewStudentBtn && previewOverlay) {
        viewStudentBtn.addEventListener('click', function () {
            previewOverlay.style.display = 'block';
            document.body.style.overflow = 'hidden';
            previewOverlay.scrollTop = 0;
        });
    }
    if (previewClose && previewOverlay) {
        previewClose.addEventListener('click', function () {
            previewOverlay.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && previewOverlay && previewOverlay.style.display !== 'none') {
            previewOverlay.style.display = 'none';
            document.body.style.overflow = '';
        }
    });

    // ================================================================
    // DOCUMENT VIEW — tab switching
    // ================================================================
    (function () {
        var tabDv  = document.getElementById('sw-tab-dv');
        var tabQl  = document.getElementById('sw-tab-ql');
        var paneDv = document.getElementById('sw-dv-wrap');
        var paneQl = document.getElementById('sw-q-editor-body');
        if (!tabDv || !tabQl) { return; }

        var LS_KEY = 'sw_view_tab_' + CMID;
        var active = 'dv';
        try { var _s = localStorage.getItem(LS_KEY); if (_s === 'ql') { active = 'ql'; } } catch(e) {}

        function setTab(tab) {
            active = tab;
            try { localStorage.setItem(LS_KEY, tab); } catch(e) {}
            if (tab === 'dv') {
                tabDv.classList.add('sw-tab-active');
                tabQl.classList.remove('sw-tab-active');
                if (paneDv) { paneDv.style.display = ''; }
                if (paneQl) { paneQl.style.display = 'none'; }
            } else {
                tabQl.classList.add('sw-tab-active');
                tabDv.classList.remove('sw-tab-active');
                if (paneQl) { paneQl.style.display = ''; }
                if (paneDv) { paneDv.style.display = 'none'; }
            }
        }
        setTab(active);
        tabDv.addEventListener('click', function () { setTab('dv'); });
        tabQl.addEventListener('click', function () { setTab('ql'); });
    })();

    // ================================================================
    // DOCUMENT VIEW — floating edit panel
    // ================================================================
    (function () {
        var fpEl       = document.getElementById('sw-fp');
        var fpBackdrop = document.getElementById('sw-fp-backdrop');
        var fpBadge    = document.getElementById('sw-fp-badge');
        var fpTitle    = document.getElementById('sw-fp-title');
        var fpBody     = document.getElementById('sw-fp-body');
        var fpClose    = document.getElementById('sw-fp-close');
        var fpCancel   = document.getElementById('sw-fp-cancel');
        var fpSaveBtn  = document.getElementById('sw-fp-save');
        var fpDeleteBtn= document.getElementById('sw-fp-delete');
        if (!fpEl) { return; }

        var _curQid  = null;
        var _curItem = null;

        // ---- helpers ----
        function _esc(s)   { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
        function _escT(s)  { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        // ---- open / close ----
        function openPanel(qid, item) {
            _curQid  = qid;
            _curItem = item;
            document.querySelectorAll('.sw-dv-item.sw-dv-selected').forEach(function (el) {
                el.classList.remove('sw-dv-selected');
            });
            item.classList.add('sw-dv-selected');
            var qtype     = item.dataset.qtype;
            var isHeading = (qtype === 'heading');
            var isDochtml = (qtype === 'dochtml');
            var isImage   = (qtype === 'image');
            var isVideo   = (qtype === 'video');
            if (isHeading) {
                fpBadge.textContent = 'HDG';
                fpBadge.className   = 'sw-fp-badge sw-fp-badge-heading';
                fpTitle.textContent = 'Edit Heading';
            } else if (isDochtml) {
                fpBadge.textContent = 'BLOCK';
                fpBadge.className   = 'sw-fp-badge sw-fp-badge-dochtml';
                fpTitle.textContent = 'HTML Display Block';
            } else if (isImage) {
                fpBadge.textContent = 'IMG';
                fpBadge.className   = 'sw-fp-badge sw-fp-badge-image';
                fpTitle.textContent = 'Edit Image';
            } else if (isVideo) {
                fpBadge.textContent = 'VID';
                fpBadge.className   = 'sw-fp-badge sw-fp-badge-video';
                fpTitle.textContent = 'Edit Video';
            } else {
                var numEl = item.querySelector('.sw-dv-q-num');
                fpBadge.textContent = numEl ? numEl.textContent : 'Q';
                fpBadge.className   = 'sw-fp-badge sw-fp-badge-question';
                fpTitle.textContent = 'Edit Question';
            }
            buildBody(qid, qtype);
            fpEl.style.display = '-webkit-box';
            fpEl.style.display = '-ms-flexbox';
            fpEl.style.display = 'flex';
            fpBackdrop.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closePanel() {
            fpEl.style.display = 'none';
            fpBackdrop.style.display = 'none';
            document.body.style.overflow = '';
            if (_curItem) { _curItem.classList.remove('sw-dv-selected'); }
            _curQid  = null;
            _curItem = null;
        }

        // ---- build panel body ----
        function buildBody(qid, qtype) {
            var isHeading = (qtype === 'heading');
            var isDochtml = (qtype === 'dochtml');
            var isImage   = (qtype === 'image');
            var isVideo   = (qtype === 'video');
            var isDisplay = isImage || isVideo;

            var cardItem = document.querySelector('#sw-q-list .sw-q-item[data-qid="' + qid + '"]');
            var label = '', questiontext = '', marks = 1, model_answer = '', rubric_notes = '', vidUrl = '', imgData = '';

            if (cardItem) {
                var ci_label  = cardItem.querySelector('.sw-q-label-input');
                var ci_text   = cardItem.querySelector('.sw-q-text-input');
                var ci_marks  = cardItem.querySelector('.sw-q-item-marks-input');
                var ci_model  = cardItem.querySelector('.sw-q-model-input');
                var ci_rubric = cardItem.querySelector('.sw-q-rubric-input');
                var ci_type   = cardItem.querySelector('.sw-q-type-select');
                var ci_img    = cardItem.querySelector('.sw-img-data-input');
                var ci_vid    = cardItem.querySelector('.sw-video-url-input');
                if (ci_label)  { label        = ci_label.value; }
                if (ci_text)   { questiontext = ci_text.innerHTML; }
                if (ci_marks)  { marks        = parseFloat(ci_marks.value) || 1; }
                if (ci_model)  { model_answer = ci_model.value; }
                if (ci_rubric) { rubric_notes = ci_rubric.value; }
                if (ci_type)   { qtype        = ci_type.value; isHeading = (qtype === 'heading'); isImage = (qtype === 'image'); isVideo = (qtype === 'video'); isDisplay = isImage || isVideo; }
                if (ci_img)    { imgData      = ci_img.value; }
                if (ci_vid)    { vidUrl       = ci_vid.value; }
            }

            var h = '';

            if (isDochtml) {
                h += '<div class="sw-fp-section"><div class="sw-fp-dochtml-notice">This is a display-only HTML block. It cannot be edited here. Click Delete to remove it, or Close to go back.</div></div>';
            } else {
                // ---- Type selector (not heading) ----
                if (!isHeading) {
                    h += '<div class="sw-fp-row">';
                    h += '<label class="sw-fp-label">Type</label>';
                    h += '<select class="sw-fp-select" id="sw-fp-type">';
                    var opts = [['text','Short answer'],['long','Extended response'],['yesno','Yes / No'],['rating','Rating scale'],['table','Table'],['heading','Section heading'],['image','Embedded image'],['video','YouTube video']];
                    for (var oi = 0; oi < opts.length; oi++) {
                        h += '<option value="' + opts[oi][0] + '"' + (opts[oi][0] === qtype ? ' selected' : '') + '>' + opts[oi][1] + '</option>';
                    }
                    h += '</select></div>';

                    // Label + Marks
                    h += '<div class="sw-fp-row sw-fp-row-2col">';
                    h += '<div><label class="sw-fp-label">Label</label><input class="sw-fp-input" type="text" id="sw-fp-label" value="' + _esc(label) + '" placeholder="e.g. Q1"></div>';
                    h += '<div><label class="sw-fp-label">Marks</label><input class="sw-fp-input" type="number" id="sw-fp-marks" value="' + marks + '" min="0.5" step="0.5" style="width:70px"></div>';
                    h += '</div>';
                } else {
                    h += '<div class="sw-fp-row"><label class="sw-fp-label">Label (optional)</label>';
                    h += '<input class="sw-fp-input" type="text" id="sw-fp-label" value="' + _esc(label) + '" placeholder="e.g. Task 1"></div>';
                }

                // Question text (not image/video)
                if (!isDisplay) {
                    h += '<div class="sw-fp-row"><label class="sw-fp-label">' + (isHeading ? 'Heading text' : 'Question text') + '</label>';
                    h += '<div class="sw-fp-rte-wrap">';
                    h += '<div class="sw-fp-rte-toolbar">';
                    h += '<button type="button" class="sw-fp-rte-btn" data-cmd="bold"><strong>B</strong></button>';
                    h += '<button type="button" class="sw-fp-rte-btn" data-cmd="italic"><em>I</em></button>';
                    h += '<button type="button" class="sw-fp-rte-btn" data-cmd="underline"><u>U</u></button>';
                    h += '<span class="sw-fp-rte-sep"></span>';
                    h += '<button type="button" class="sw-fp-rte-btn" data-cmd="insertUnorderedList">&#8226; list</button>';
                    h += '<button type="button" class="sw-fp-rte-btn" data-cmd="removeFormat">&#10005;</button>';
                    h += '</div>';
                    h += '<div class="sw-fp-rte-editor" id="sw-fp-qtext" contenteditable="true">' + questiontext + '</div>';
                    h += '</div></div>';
                }

                // Image zone
                if (isImage) {
                    h += '<div class="sw-fp-row"><label class="sw-fp-label">Image</label>';
                    if (imgData) { h += '<img class="sw-fp-img-preview" id="sw-fp-img-preview" src="' + _esc(imgData) + '" alt="">'; }
                    else         { h += '<img class="sw-fp-img-preview" id="sw-fp-img-preview" src="" alt="" style="display:none">'; }
                    h += '<input type="hidden" id="sw-fp-img-data" value="' + _esc(imgData) + '">';
                    h += '<div class="sw-fp-img-paste" id="sw-fp-img-paste" tabindex="0">Click here, then paste an image (Ctrl+V)</div>';
                    h += '<label class="sw-fp-img-upload-label">Or upload file<input type="file" id="sw-fp-img-file" accept="image/*" style="display:none"></label>';
                    h += '</div>';
                }

                // Video zone
                if (isVideo) {
                    h += '<div class="sw-fp-row"><label class="sw-fp-label">YouTube URL</label>';
                    h += '<input class="sw-fp-input" type="url" id="sw-fp-vid-url" value="' + _esc(vidUrl) + '" placeholder="https://www.youtube.com/watch?v=...">';
                    h += '<div id="sw-fp-vid-preview" class="sw-fp-vid-preview"></div></div>';
                }

                // Model + rubric (not heading, not display)
                if (!isHeading && !isDisplay) {
                    h += '<div class="sw-fp-row"><label class="sw-fp-label">Model answer</label>';
                    h += '<textarea class="sw-fp-textarea" id="sw-fp-model" rows="3" placeholder="Model answer for AI marking...">' + _escT(model_answer) + '</textarea></div>';
                    h += '<div class="sw-fp-row"><label class="sw-fp-label">Marking notes / rubric</label>';
                    h += '<textarea class="sw-fp-textarea" id="sw-fp-rubric" rows="2" placeholder="Guidance for AI and teacher...">' + _escT(rubric_notes) + '</textarea></div>';
                }
            }

            fpBody.innerHTML = h;

            // bind RTE toolbar
            var rteToolbar = fpBody.querySelector('.sw-fp-rte-toolbar');
            var rteEditor  = fpBody.querySelector('#sw-fp-qtext');
            if (rteToolbar && rteEditor) {
                rteToolbar.querySelectorAll('.sw-fp-rte-btn').forEach(function (btn) {
                    btn.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        document.execCommand(btn.dataset.cmd, false, null);
                        rteEditor.focus();
                    });
                });
            }

            // bind type selector change → rebuild
            var typeSelect = fpBody.querySelector('#sw-fp-type');
            if (typeSelect) {
                typeSelect.addEventListener('change', function () {
                    buildBody(qid, typeSelect.value);
                });
            }

            // bind video URL live preview
            var vidInput   = fpBody.querySelector('#sw-fp-vid-url');
            var vidPreview = fpBody.querySelector('#sw-fp-vid-preview');
            if (vidInput && vidPreview) {
                function _updateVid() {
                    var m = vidInput.value.match(/(?:youtube\.com\/(?:watch\?(?:[^&\s]*&)*v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_\-]{11})/);
                    if (m) {
                        vidPreview.innerHTML = '<div class="sw-video-responsive"><iframe class="sw-youtube-embed" src="https://www.youtube.com/embed/' + m[1] + '" frameborder="0" allowfullscreen></iframe></div>';
                    } else { vidPreview.innerHTML = ''; }
                }
                vidInput.addEventListener('input', _updateVid);
                _updateVid();
            }

            // bind image paste/upload
            var imgPasteEl  = fpBody.querySelector('#sw-fp-img-paste');
            var imgFileEl   = fpBody.querySelector('#sw-fp-img-file');
            var imgDataEl   = fpBody.querySelector('#sw-fp-img-data');
            var imgPreviewEl= fpBody.querySelector('#sw-fp-img-preview');
            function _loadImg(file) {
                if (!file || !file.type.match(/^image\//)) { return; }
                var reader = new FileReader();
                reader.onload = function (ev) {
                    var uri = ev.target.result;
                    if (imgDataEl)    { imgDataEl.value = uri; }
                    if (imgPreviewEl) { imgPreviewEl.src = uri; imgPreviewEl.style.display = 'block'; }
                };
                reader.readAsDataURL(file);
            }
            if (imgPasteEl) {
                imgPasteEl.addEventListener('paste', function (e) {
                    var items = (e.clipboardData || e.originalEvent.clipboardData).items;
                    for (var i = 0; i < items.length; i++) {
                        if (items[i].kind === 'file') { _loadImg(items[i].getAsFile()); e.preventDefault(); return; }
                    }
                });
                imgPasteEl.addEventListener('dragover', function (e) { e.preventDefault(); });
                imgPasteEl.addEventListener('drop', function (e) {
                    e.preventDefault();
                    if (e.dataTransfer.files.length) { _loadImg(e.dataTransfer.files[0]); }
                });
            }
            if (imgFileEl) {
                imgFileEl.addEventListener('change', function () {
                    if (imgFileEl.files.length) { _loadImg(imgFileEl.files[0]); }
                });
            }
        }

        // ---- save ----
        function savePanel() {
            if (!_curQid) { return; }
            var isDochtml = _curItem && _curItem.dataset.qtype === 'dochtml';
            if (isDochtml) { closePanel(); return; } // dochtml blocks are display-only

            var typeEl   = fpBody.querySelector('#sw-fp-type');
            var labelEl  = fpBody.querySelector('#sw-fp-label');
            var marksEl  = fpBody.querySelector('#sw-fp-marks');
            var qtextEl  = fpBody.querySelector('#sw-fp-qtext');
            var modelEl  = fpBody.querySelector('#sw-fp-model');
            var rubricEl = fpBody.querySelector('#sw-fp-rubric');
            var imgDE    = fpBody.querySelector('#sw-fp-img-data');
            var vidUE    = fpBody.querySelector('#sw-fp-vid-url');

            var qtype = typeEl ? typeEl.value : (_curItem ? _curItem.dataset.qtype : 'text');
            var isHeading = (qtype === 'heading');
            var isImage   = (qtype === 'image');
            var isVideo   = (qtype === 'video');

            var questiontext = '';
            if (isImage)      { questiontext = imgDE  ? imgDE.value        : ''; }
            else if (isVideo) { questiontext = vidUE  ? vidUE.value.trim() : ''; }
            else if (qtextEl) { questiontext = qtextEl.innerHTML; }

            var q = {
                id:           parseInt(_curQid, 10),
                qtype:        qtype,
                label:        labelEl  ? labelEl.value                   : '',
                questiontext: questiontext,
                marks:        isHeading ? 0 : (marksEl  ? (parseFloat(marksEl.value) || 1) : 1),
                model_answer: modelEl  ? modelEl.value                   : '',
                rubric_notes: rubricEl ? rubricEl.value                  : ''
            };

            fpSaveBtn.disabled = true;
            fpSaveBtn.textContent = 'Saving\u2026';

            ajax({action:'save_questions', cmid:CMID, questions:JSON.stringify([q])}, function (data) {
                fpSaveBtn.disabled = false;
                fpSaveBtn.textContent = 'Save';
                if (data.success) {
                    _updateDocItem(_curQid, q);
                    _updateCardItem(_curQid, q);
                    closePanel();
                } else {
                    alert('Save failed: ' + (data.error || 'unknown error'));
                }
            });
        }

        // ---- update doc view item after save ----
        function _updateDocItem(qid, q) {
            var item = document.querySelector('.sw-dv-item[data-qid="' + qid + '"]');
            if (!item) { return; }
            item.dataset.qtype = q.qtype;

            // Label / num
            var numEl = item.querySelector('.sw-dv-q-num');
            if (numEl && q.label) { numEl.textContent = q.label; }

            // Marks
            var marksEl = item.querySelector('.sw-dv-q-marks');
            if (marksEl) { marksEl.textContent = q.marks + ' ' + (q.marks == 1 ? 'mark' : 'marks'); }

            // Question text
            var qtEl = item.querySelector('.sw-dv-q-text');
            if (qtEl) { qtEl.innerHTML = q.questiontext; }

            // Heading body
            var hdgEl = item.querySelector('.sw-dv-heading-body');
            if (hdgEl) { hdgEl.innerHTML = q.questiontext; }

            // Image preview
            if (q.qtype === 'image') {
                var imgEl = item.querySelector('.sw-dv-image-preview');
                if (imgEl) { imgEl.src = q.questiontext; imgEl.style.display = 'block'; }
                var emptyEl = item.querySelector('.sw-dv-image-empty');
                if (emptyEl) { emptyEl.style.display = 'none'; }
            }

            // Video preview
            if (q.qtype === 'video') {
                var m = (q.questiontext||'').match(/(?:youtube\.com\/(?:watch\?(?:[^&\s]*&)*v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_\-]{11})/);
                var vidWrap = item.querySelector('.sw-video-responsive');
                if (m && vidWrap) {
                    vidWrap.innerHTML = '<iframe class="sw-youtube-embed" src="https://www.youtube.com/embed/' + m[1] + '" frameborder="0" allowfullscreen></iframe>';
                }
            }

            // Table re-render — rebuild sw-stable HTML from updated model_answer JSON
            if (q.qtype === 'table') {
                var tblWrap = item.querySelector('.sw-stable-wrap, .sw-dv-table-live');
                if (tblWrap) {
                    var tblDef2 = null;
                    if (q.model_answer) {
                        try { tblDef2 = JSON.parse(q.model_answer); } catch (e2) { tblDef2 = null; }
                        if (!tblDef2 || !tblDef2.sw_table) { tblDef2 = null; }
                    }
                    if (tblDef2) {
                        var hdrBg2 = String(tblDef2.header_bg || '#334155').replace(/[^#a-zA-Z0-9]/g, '');
                        var tHtml = '<table class="sw-stable">';
                        if (tblDef2.headers && tblDef2.headers.length) {
                            tHtml += '<thead><tr>';
                            for (var thi = 0; thi < tblDef2.headers.length; thi++) {
                                tHtml += '<th class="sw-stable-th" style="background:' + hdrBg2 + '">' + _esc(tblDef2.headers[thi]) + '</th>';
                            }
                            tHtml += '</tr></thead>';
                        }
                        tHtml += '<tbody>';
                        var tRows = tblDef2.rows || [];
                        for (var tri = 0; tri < tRows.length; tri++) {
                            tHtml += '<tr class="' + (tri % 2 === 0 ? 'sw-stable-even' : 'sw-stable-odd') + '">';
                            for (var tci = 0; tci < tRows[tri].length; tci++) {
                                var tc = tRows[tri][tci];
                                if (tc.e) {
                                    tHtml += '<td class="sw-stable-td sw-stable-td-input"><input class="sw-stable-cell" type="text" readonly placeholder="..."></td>';
                                } else {
                                    tHtml += '<td class="sw-stable-td sw-stable-td-fixed">' + _esc(tc.v || '') + '</td>';
                                }
                            }
                            tHtml += '</tr>';
                        }
                        tHtml += '</tbody></table>';
                        tblWrap.className = 'sw-answer-wrap sw-stable-wrap sw-dv-table-live';
                        tblWrap.innerHTML = tHtml;
                    }
                }
            }
        }

        // ---- mirror changes to hidden card list ----
        function _updateCardItem(qid, q) {
            var card = document.querySelector('#sw-q-list .sw-q-item[data-qid="' + qid + '"]');
            if (!card) { return; }
            var ci_label  = card.querySelector('.sw-q-label-input');
            var ci_type   = card.querySelector('.sw-q-type-select');
            var ci_marks  = card.querySelector('.sw-q-item-marks-input');
            var ci_model  = card.querySelector('.sw-q-model-input');
            var ci_rubric = card.querySelector('.sw-q-rubric-input');
            var ci_text   = card.querySelector('.sw-q-text-input');
            var ci_img    = card.querySelector('.sw-img-data-input');
            var ci_vid    = card.querySelector('.sw-video-url-input');
            if (ci_label)  { ci_label.value   = q.label; }
            if (ci_type)   { ci_type.value    = q.qtype; }
            if (ci_marks)  { ci_marks.value   = q.marks; }
            if (ci_model)  { ci_model.value   = q.model_answer; }
            if (ci_rubric) { ci_rubric.value  = q.rubric_notes; }
            if (q.qtype === 'image' && ci_img)  { ci_img.value  = q.questiontext; }
            else if (q.qtype === 'video' && ci_vid) { ci_vid.value = q.questiontext; }
            else if (ci_text) { ci_text.innerHTML = q.questiontext; }
        }

        // ---- click on document view items ----
        var dvPaper = document.getElementById('sw-dv-paper');
        if (dvPaper) {
            dvPaper.addEventListener('click', function (e) {
                var delBtn = e.target.closest('.sw-dv-delete-btn');
                if (delBtn) {
                    var dqid = delBtn.dataset.qid;
                    var dvDelItem = delBtn.closest('.sw-dv-item');
                    var dvDelQtype = dvDelItem ? dvDelItem.dataset.qtype : '';
                    var dvDelMsg = (dvDelQtype === 'dochtml')
                        ? 'Remove this HTML display block? This cannot be undone.'
                        : 'Delete this item permanently? This cannot be undone.';
                    if (!confirm(dvDelMsg)) { return; }
                    ajax({action:'delete_question', cmid:CMID, qid:dqid}, function (rsp) {
                        if (rsp.success) {
                            var dvItem = document.querySelector('.sw-dv-item[data-qid="' + dqid + '"]');
                            if (dvItem) { dvItem.parentNode.removeChild(dvItem); }
                            var card = document.querySelector('#sw-q-list .sw-q-item[data-qid="' + dqid + '"]');
                            if (card)   { card.parentNode.removeChild(card); }
                        } else { alert('Could not delete: ' + (rsp.error || 'unknown')); }
                    });
                    return;
                }
                var item = e.target.closest('.sw-dv-item');
                if (item && item.dataset.qid) { openPanel(item.dataset.qid, item); }
            });
        }

        // ---- delete from panel ----
        if (fpDeleteBtn) {
            fpDeleteBtn.addEventListener('click', function () {
                if (!_curQid) { return; }
                var fpDelIsDochtml = _curItem && _curItem.dataset.qtype === 'dochtml';
                var fpDelMsg = fpDelIsDochtml
                    ? 'Remove this HTML display block? This cannot be undone.'
                    : 'Delete this item permanently? This cannot be undone.';
                if (!confirm(fpDelMsg)) { return; }
                ajax({action:'delete_question', cmid:CMID, qid:_curQid}, function (rsp) {
                    if (rsp.success) {
                        var dvItem = document.querySelector('.sw-dv-item[data-qid="' + _curQid + '"]');
                        if (dvItem) { dvItem.parentNode.removeChild(dvItem); }
                        var card = document.querySelector('#sw-q-list .sw-q-item[data-qid="' + _curQid + '"]');
                        if (card)   { card.parentNode.removeChild(card); }
                        closePanel();
                    } else { alert('Could not delete: ' + (rsp.error || 'unknown')); }
                });
            });
        }

        // ---- close / save bindings ----
        if (fpClose)    { fpClose.addEventListener('click', closePanel); }
        if (fpCancel)   { fpCancel.addEventListener('click', closePanel); }
        if (fpBackdrop) { fpBackdrop.addEventListener('click', closePanel); }
        if (fpSaveBtn)  { fpSaveBtn.addEventListener('click', savePanel); }

        // Close on Escape (panel only — do not conflict with preview overlay)
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && fpEl.style.display !== 'none') {
                closePanel();
                e.stopPropagation();
            }
        }, true);

    })();

});
</script>

<?php
echo $OUTPUT->footer();
