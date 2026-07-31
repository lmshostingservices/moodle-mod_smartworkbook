<?php
/**
 * Restore structure step for mod_smartworkbook.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Reads smartworkbook.xml and writes all rows into the live database.
 *
 * Processing order (Moodle calls process_* methods as the XML is parsed):
 *  1. process_smartworkbook       — inserts the main instance row.
 *  2. process_smartworkbook_question  — inserts each question; stores
 *                                       old→new ID mapping for later use.
 *  3. process_smartworkbook_submission — inserts each submission; stores
 *                                        old→new ID mapping.
 *  4. process_smartworkbook_mark  — inserts per-question marks; remaps
 *                                   submissionid and questionid.
 *  5. process_smartworkbook_response  — inserts per-question student responses;
 *                                       remaps questionid and userid.
 *
 * ID remapping guarantees:
 *  - All user IDs are remapped via Moodle's user-matching logic.
 *  - Question IDs referenced by marks and responses are remapped through
 *    the 'smartworkbook_question' mapping table populated in step 2.
 *  - Timestamps are shifted by the date offset so scheduled activities land
 *    on the correct dates in the restored course.
 */
class restore_smartworkbook_activity_structure_step extends restore_activity_structure_step {

    /**
     * Declare the XPath expressions that this step will handle, and register
     * process_* callbacks for each one.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {

        $userinfo = $this->get_setting_value('userinfo');

        $paths = [];

        // Main activity record.
        $paths[] = new restore_path_element(
            'smartworkbook',
            '/activity/smartworkbook'
        );

        // Questions are always restored (they are the workbook content).
        $paths[] = new restore_path_element(
            'smartworkbook_question',
            '/activity/smartworkbook/questions/question'
        );

        if ($userinfo) {
            // Submission records.
            $paths[] = new restore_path_element(
                'smartworkbook_submission',
                '/activity/smartworkbook/submissions/submission'
            );

            // Marks are children of their submission in the XML.
            $paths[] = new restore_path_element(
                'smartworkbook_mark',
                '/activity/smartworkbook/submissions/submission/marks/mark'
            );

            // Responses are stored at workbook level (keyed workbookid+questionid+userid).
            $paths[] = new restore_path_element(
                'smartworkbook_response',
                '/activity/smartworkbook/responses/response'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    // ── Processors ────────────────────────────────────────────────────────

    /**
     * Insert the main smartworkbook instance row.
     *
     * Called once per restore.  After inserting, calls apply_activity_instance()
     * which (a) updates the course_module record to point at the new row ID and
     * (b) registers the 'smartworkbook' old→new mapping used by nested elements.
     */
    protected function process_smartworkbook($data) {
        global $DB;

        $data   = (object) $data;
        $oldid  = $data->id;

        // Remap course to the destination course.
        $data->course = $this->get_courseid();

        // source_fileid is an mdl_files.id from the source site — meaningless here.
        // source_filename is kept so the teacher can see the original document name.
        unset($data->source_fileid);

        // Shift timestamps to the restored course timeline.
        $data->timecreated  = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Remove the backed-up primary key before inserting.
        unset($data->id);

        $newid = $DB->insert_record('smartworkbook', $data);

        // Register the course_module→instance link and the 'smartworkbook' mapping.
        $this->apply_activity_instance($newid);
    }

    /**
     * Insert a question row and record the old→new question ID mapping.
     *
     * The mapping is consumed by process_smartworkbook_mark() and
     * process_smartworkbook_response() to remap questionid references.
     */
    protected function process_smartworkbook_question($data) {
        global $DB;

        $data  = (object) $data;
        $oldid = $data->id;
        unset($data->id);

        // Parent workbook ID from the element above in the XML tree.
        $data->workbookid  = $this->get_new_parentid('smartworkbook');
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $newid = $DB->insert_record('smartworkbook_question', $data);

        // Store old→new mapping so marks/responses can remap questionid.
        $this->set_mapping('smartworkbook_question', $oldid, $newid);
    }

    /**
     * Insert a student submission record and record the old→new submission mapping.
     *
     * userid and graderid are remapped through Moodle's user-matching logic.
     */
    protected function process_smartworkbook_submission($data) {
        global $DB;

        $data  = (object) $data;
        $oldid = $data->id;
        unset($data->id);

        $data->workbookid    = $this->get_new_parentid('smartworkbook');
        $data->userid        = $this->get_mappingid('user', $data->userid);
        $data->graderid      = !empty($data->graderid)
            ? $this->get_mappingid('user', $data->graderid)
            : null;

        $data->timecreated   = $this->apply_date_offset($data->timecreated);
        $data->timemodified  = $this->apply_date_offset($data->timemodified);
        $data->timesubmitted = !empty($data->timesubmitted)
            ? $this->apply_date_offset($data->timesubmitted)
            : null;
        $data->timegraded    = !empty($data->timegraded)
            ? $this->apply_date_offset($data->timegraded)
            : null;

        $newid = $DB->insert_record('smartworkbook_submission', $data);

        // Store mapping so marks (which are children of this submission) can
        // resolve the new submissionid.
        $this->set_mapping('smartworkbook_submission', $oldid, $newid);
    }

    /**
     * Insert a per-question mark record.
     *
     * submissionid is resolved via the 'smartworkbook_submission' mapping.
     * questionid   is resolved via the 'smartworkbook_question'   mapping.
     */
    protected function process_smartworkbook_mark($data) {
        global $DB;

        $data = (object) $data;
        unset($data->id);

        $data->submissionid = $this->get_new_parentid('smartworkbook_submission');
        $data->questionid   = $this->get_mappingid('smartworkbook_question', $data->questionid);

        $data->timecreated  = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $DB->insert_record('smartworkbook_mark', $data);
        // No mapping needed — nothing references smartworkbook_mark.id.
    }

    /**
     * Insert a per-question student response.
     *
     * workbookid is resolved from the ancestor smartworkbook element.
     * questionid is resolved via the 'smartworkbook_question' mapping.
     * userid     is resolved via Moodle's user-matching logic.
     */
    protected function process_smartworkbook_response($data) {
        global $DB;

        $data = (object) $data;
        unset($data->id);

        // Responses sit at workbook level in the XML (not under a submission).
        // Walk up the ancestor chain to find the nearest smartworkbook new ID.
        $data->workbookid   = $this->get_new_parentid('smartworkbook');
        $data->questionid   = $this->get_mappingid('smartworkbook_question', $data->questionid);
        $data->userid       = $this->get_mappingid('user', $data->userid);

        $data->timecreated  = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $DB->insert_record('smartworkbook_response', $data);
        // No mapping needed — nothing references smartworkbook_response.id.
    }

    /**
     * Called after all XML elements have been processed.
     *
     * Restore intro file references (images/media embedded in the activity
     * description) from the backup's file area.
     */
    protected function after_execute() {
        $this->add_related_files('mod_smartworkbook', 'intro', null);
    }
}
