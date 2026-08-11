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
 * Backup task for mod_smartworkbook.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/smartworkbook/backup/moodle2/backup_smartworkbook_stepslib.php');

/**
 * Defines the complete backup task for AI Smart Workbook activities.
 *
 * Backup covers:
 *  - All activity settings (name, grade, display options, manual_grading flag).
 *  - All questions (text, marks, model answers, rubric notes).
 *  - Optionally (when "Include enrolled users' data" is ticked):
 *      - Every student submission record (status, grade, group member metadata).
 *      - Every student response (raw answer text, per question).
 *      - Every AI/teacher mark (AI suggestion, teacher override, status).
 *
 * NOTE: The source_filename field (original document name) is backed up for
 * reference only. All questions, model answers, rubric notes, and HTML display
 * blocks ARE fully restored — the activity is immediately usable on the new site.
 */
class backup_smartworkbook_activity_task extends backup_activity_task {
    /**
     * No extra settings beyond the standard ones (userinfo).
     */
    protected static function define_settings() {
    }

    /**
     * Register the single structure step that serialises the activity data to
     * smartworkbook.xml inside the activity backup directory.
     */
    protected function define_steps() {
        $this->add_step(new backup_smartworkbook_activity_structure_step(
            'smartworkbook_structure',
            'smartworkbook.xml'
        ));
    }

    /**
     * Rewrites any absolute links to smartworkbook pages found inside backed-up
     * HTML content (intro, question text, etc.) into backup-portable tokens that
     * the restore process can convert back to the new site's URLs.
     *
     * @param  string $content  Raw HTML content from the backup.
     * @return string           Content with site-specific URLs replaced by tokens.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Activity list page:  .../mod/smartworkbook/index.php?id=<courseid>
        $search  = '/(' . $base . '\/mod\/smartworkbook\/index\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@SMARTWORKBOOKINDEX*$2@$', $content);

        // Activity view page:  .../mod/smartworkbook/view.php?id=<cmid>
        $search  = '/(' . $base . '\/mod\/smartworkbook\/view\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@SMARTWORKBOOKVIEWBYID*$2@$', $content);

        // Teacher dashboard:   .../mod/smartworkbook/teacher.php?id=<cmid>
        $search  = '/(' . $base . '\/mod\/smartworkbook\/teacher\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@SMARTWORKBOOKTEACHERBYID*$2@$', $content);

        return $content;
    }
}
