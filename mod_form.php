<?php
/**
 * AI Smart Workbook - Activity creation/editing form.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_smartworkbook_mod_form extends moodleform_mod {

    public function definition() {
        global $CFG;
        $mform = $this->_form;

        // ---- General ---------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // ---- Grade -----------------------------------------------------------
        $mform->addElement('header', 'gradinghdr', get_string('grade', 'smartworkbook'));

        // Maximum grade is always 100 — raw marks are scaled to a percentage automatically.
        // Keep the field in the form so Moodle's core saves it to the DB, but hide it from teachers.
        $mform->addElement('hidden', 'grade', 100);
        $mform->setType('grade', PARAM_INT);

        // Passing Grade Percentage — stored as gradepass in the gradebook item.
        // Because grademax is fixed at 100, the gradepass value is identical to the percentage.
        $mform->addElement('text', 'gradepass', get_string('gradepasspercentage', 'smartworkbook'), ['size' => '5']);
        $mform->setType('gradepass', PARAM_RAW);
        $mform->setDefault('gradepass', '');
        $mform->addHelpButton('gradepass', 'gradepasspercentage', 'smartworkbook');

        // ---- Workbook display options ----------------------------------------
        $mform->addElement('header', 'displayoptionshdr', get_string('displayoptions', 'smartworkbook'));

        $mform->addElement('advcheckbox', 'showstudentname', get_string('showstudentname', 'smartworkbook'));
        $mform->setDefault('showstudentname', 0);
        $mform->addHelpButton('showstudentname', 'showstudentname', 'smartworkbook');

        $groupopts = [0 => get_string('numgroupmembers_none', 'smartworkbook')];
        for ($i = 1; $i <= 6; $i++) {
            $groupopts[$i] = $i;
        }
        $mform->addElement('select', 'numgroupmembers', get_string('numgroupmembers', 'smartworkbook'), $groupopts);
        $mform->setDefault('numgroupmembers', 0);
        $mform->addHelpButton('numgroupmembers', 'numgroupmembers', 'smartworkbook');

        // ---- Standard elements -----------------------------------------------
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        // Always ensure grade is fixed at 100.
        $defaultvalues['grade'] = 100;

        // Load the current gradepass from the gradebook item so the percentage field pre-fills correctly.
        // Since grademax = 100, gradepass already equals the passing percentage — no conversion needed.
        if (!empty($this->current->id)) {
            require_once($GLOBALS['CFG']->libdir . '/gradelib.php');
            $gradeitem = grade_item::fetch([
                'courseid'     => $this->current->course,
                'itemtype'     => 'mod',
                'itemmodule'   => 'smartworkbook',
                'iteminstance' => $this->current->id,
                'itemnumber'   => 0,
            ]);
            if ($gradeitem && $gradeitem->gradepass > 0) {
                $defaultvalues['gradepass'] = format_float($gradeitem->gradepass, 0);
            }
        }
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Validate that the passing percentage is between 0 and 100.
        if (isset($data['gradepass']) && $data['gradepass'] !== '') {
            $gradepass = unformat_float($data['gradepass']);
            if ($gradepass < 0 || $gradepass > 100) {
                $errors['gradepass'] = get_string('gradepasspercentage_error', 'smartworkbook');
            }
        }

        return $errors;
    }
}
