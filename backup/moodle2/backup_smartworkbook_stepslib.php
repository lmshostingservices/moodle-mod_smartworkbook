<?php
/**
 * Backup structure step for mod_smartworkbook.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Defines the XML structure serialised to smartworkbook.xml.
 *
 * XML tree produced:
 *
 *  <activity>
 *    <smartworkbook id="...">          <- mdl_smartworkbook row (minus source_fileid)
 *      <questions>
 *        <question id="...">           <- mdl_smartworkbook_question rows
 *        ...
 *      </questions>
 *      [when userinfo=true]
 *      <submissions>
 *        <submission id="...">         <- mdl_smartworkbook_submission rows
 *          <marks>
 *            <mark id="...">           <- mdl_smartworkbook_mark rows
 *            ...
 *          </marks>
 *        </submission>
 *        ...
 *      </submissions>
 *      <responses>
 *        <response id="...">           <- mdl_smartworkbook_response rows
 *        ...
 *      </responses>
 *    </smartworkbook>
 *  </activity>
 */
class backup_smartworkbook_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {

        $userinfo = $this->get_setting_value('userinfo');

        // ── Main activity record ────────────────────────────────────────────
        // source_fileid is a raw mdl_files.id that is meaningless outside this
        // Moodle instance, so we deliberately omit it.  source_filename (the
        // original document name) is kept so the teacher can see what was used.
        $workbook = new backup_nested_element('smartworkbook', ['id'], [
            'course',
            'name',
            'intro',
            'introformat',
            'grade',
            'source_filename',
            'status',
            'model_answers_json',
            'showstudentname',
            'numgroupmembers',
            'manual_grading',
            'timecreated',
            'timemodified',
        ]);

        // ── Questions (always backed up, these are the workbook content) ────
        $questions = new backup_nested_element('questions');
        $question  = new backup_nested_element('question', ['id'], [
            'sortorder',
            'qtype',
            'label',
            'questiontext',
            'marks',
            'model_answer',
            'rubric_notes',
            'table_cols',
            'table_rows',
            'timecreated',
        ]);

        $workbook->add_child($questions);
        $questions->add_child($question);

        // ── User data (submissions, marks, responses) ───────────────────────
        if ($userinfo) {
            // Submissions: one record per student who opened the workbook
            $submissions = new backup_nested_element('submissions');
            $submission  = new backup_nested_element('submission', ['id'], [
                'userid',
                'status',
                'grade',
                'total_marks',
                'max_marks',
                'teacher_feedback',
                'graderid',
                'meta_json',
                'timecreated',
                'timemodified',
                'timesubmitted',
                'timegraded',
            ]);

            // Marks: per-question AI/teacher marks, children of their submission
            $marks = new backup_nested_element('marks');
            $mark  = new backup_nested_element('mark', ['id'], [
                'questionid',
                'ai_mark',
                'ai_comment',
                'ai_confidence',
                'teacher_mark',
                'teacher_comment',
                'status',
                'timecreated',
                'timemodified',
            ]);

            // Responses: raw student text per question, stored at workbook level
            // (not per-submission) because they are keyed by workbookid+questionid+userid.
            $responses = new backup_nested_element('responses');
            $response  = new backup_nested_element('response', ['id'], [
                'questionid',
                'userid',
                'responsetext',
                'timecreated',
                'timemodified',
            ]);

            $workbook->add_child($submissions);
            $submissions->add_child($submission);
            $submission->add_child($marks);
            $marks->add_child($mark);

            $workbook->add_child($responses);
            $responses->add_child($response);
        }

        // ── Data sources ────────────────────────────────────────────────────
        $workbook->set_source_table('smartworkbook',
            ['id' => backup::VAR_ACTIVITYID]);

        $question->set_source_table('smartworkbook_question',
            ['workbookid' => backup::VAR_PARENTID]);

        if ($userinfo) {
            $submission->set_source_table('smartworkbook_submission',
                ['workbookid' => backup::VAR_PARENTID]);

            $mark->set_source_table('smartworkbook_mark',
                ['submissionid' => backup::VAR_PARENTID]);

            $response->set_source_table('smartworkbook_response',
                ['workbookid' => backup::VAR_PARENTID]);
        }

        // ── ID annotations for user/question remapping on restore ───────────
        // Questions: the backup framework needs to know the IDs so that marks
        // and responses (which reference questionid) can be remapped.
        $question->annotate_ids('smartworkbook_question', 'id');

        if ($userinfo) {
            $submission->annotate_ids('user', 'userid');
            $submission->annotate_ids('user', 'graderid');

            // Mark references a question by questionid — annotate so restore can remap.
            $mark->annotate_ids('smartworkbook_question', 'questionid');

            $response->annotate_ids('user', 'userid');
            $response->annotate_ids('smartworkbook_question', 'questionid');
        }

        // ── Annotate files in the intro ─────────────────────────────────────
        $workbook->annotate_files('mod_smartworkbook', 'intro', null);

        return $this->prepare_activity_structure($workbook);
    }
}
