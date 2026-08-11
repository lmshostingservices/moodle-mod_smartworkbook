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
 * Restore task for mod_smartworkbook.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/smartworkbook/backup/moodle2/restore_smartworkbook_stepslib.php');

/**
 * Defines the restore task for AI Smart Workbook activities.
 *
 * What is restored:
 *  - All activity settings and display options.
 *  - All parsed questions, model answers, rubric notes, and table definitions.
 *  - Optionally (when the backup includes user data):
 *      - Student submission records with grades, status, and group member metadata.
 *      - Per-question AI and teacher marks.
 *      - Per-question student response text.
 *
 * What is NOT restored:
 *  - The original uploaded source file (DOCX/PDF) — teachers must re-upload.
 *    The activity is still fully functional: all questions and model answers are
 *    in place, and the workbook status is preserved (so a "published" workbook
 *    remains published after restore).
 */
class restore_smartworkbook_activity_task extends restore_activity_task {
    /**
     * No extra restore settings beyond the standard ones.
     */
    protected function define_my_settings() {
    }

    /**
     * Register the single structure step that reads smartworkbook.xml and
     * inserts all rows into the appropriate database tables.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_smartworkbook_activity_structure_step(
            'smartworkbook_structure',
            'smartworkbook.xml'
        ));
    }

    /**
     * Define which database fields contain HTML that may include encoded links
     * that must be decoded back to the new site's URL on restore.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        $contents = [];

        // Activity intro (description shown on the course page).
        $contents[] = new restore_decode_content(
            'smartworkbook',
            ['intro'],
            'smartworkbook'
        );

        // Question text and model answers may contain embedded links or images.
        $contents[] = new restore_decode_content(
            'smartworkbook_question',
            ['questiontext', 'model_answer', 'rubric_notes'],
            'smartworkbook_question'
        );

        return $contents;
    }

    /**
     * Define the URL decode rules that convert backup tokens back to live URLs.
     *
     * These must mirror the tokens produced by
     * backup_smartworkbook_activity_task::encode_content_links().
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule(
            'SMARTWORKBOOKINDEX',
            '/mod/smartworkbook/index.php?id=$1',
            'course'
        );

        $rules[] = new restore_decode_rule(
            'SMARTWORKBOOKVIEWBYID',
            '/mod/smartworkbook/view.php?id=$1',
            'course_module'
        );

        $rules[] = new restore_decode_rule(
            'SMARTWORKBOOKTEACHERBYID',
            '/mod/smartworkbook/teacher.php?id=$1',
            'course_module'
        );

        return $rules;
    }

    /**
     * No custom log rules for this plugin.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [];
    }

    /**
     * No custom course-level log rules for this plugin.
     *
     * @return array
     */
    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
