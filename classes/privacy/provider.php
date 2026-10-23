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
 * Privacy Subsystem implementation for mod_smartworkbook.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartworkbook\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider: student responses, submissions and per-question marks.
 *
 * Student answers are sent to the external AI Grader service for marking, and
 * uploaded teacher documents are sent for conversion; both are declared as
 * external locations.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    /**
     * Describe stored and exported personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('smartworkbook_response', [
            'userid'       => 'privacy:metadata:smartworkbook_response:userid',
            'responsetext' => 'privacy:metadata:smartworkbook_response:responsetext',
            'timecreated'  => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:smartworkbook_response');

        $collection->add_database_table('smartworkbook_submission', [
            'userid'           => 'privacy:metadata:smartworkbook_submission:userid',
            'status'           => 'privacy:metadata:smartworkbook_submission:status',
            'grade'            => 'privacy:metadata:smartworkbook_submission:grade',
            'teacher_feedback' => 'privacy:metadata:smartworkbook_submission:teacher_feedback',
            'graderid'         => 'privacy:metadata:smartworkbook_submission:graderid',
            'meta_json'        => 'privacy:metadata:smartworkbook_submission:meta_json',
            'timesubmitted'    => 'privacy:metadata:smartworkbook_submission:timesubmitted',
        ], 'privacy:metadata:smartworkbook_submission');

        $collection->add_database_table('smartworkbook_mark', [
            'ai_mark'         => 'privacy:metadata:smartworkbook_mark:ai_mark',
            'ai_comment'      => 'privacy:metadata:smartworkbook_mark:ai_comment',
            'teacher_mark'    => 'privacy:metadata:smartworkbook_mark:teacher_mark',
            'teacher_comment' => 'privacy:metadata:smartworkbook_mark:teacher_comment',
        ], 'privacy:metadata:smartworkbook_mark');

        $collection->add_external_location_link('aigrader', [
            'responsetext' => 'privacy:metadata:aigrader:responsetext',
        ], 'privacy:metadata:aigrader');

        return $collection;
    }

    /**
     * Contexts containing data for a user (as student or as grader).
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {smartworkbook} w ON w.id = cm.instance
                 WHERE EXISTS (SELECT 1 FROM {smartworkbook_submission} s
                                WHERE s.workbookid = w.id AND (s.userid = :u1 OR s.graderid = :u2))
                    OR EXISTS (SELECT 1 FROM {smartworkbook_response} r
                                WHERE r.workbookid = w.id AND r.userid = :u3)";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, [
            'ctxlevel' => CONTEXT_MODULE, 'modname' => 'smartworkbook',
            'u1' => $userid, 'u2' => $userid, 'u3' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $params = ['cmid' => $context->instanceid, 'modname' => 'smartworkbook'];
        $base = "FROM {course_modules} cm
                 JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 JOIN {smartworkbook} w ON w.id = cm.instance";

        $userlist->add_from_sql('userid',
            "SELECT s.userid $base JOIN {smartworkbook_submission} s ON s.workbookid = w.id WHERE cm.id = :cmid", $params);
        $userlist->add_from_sql('graderid',
            "SELECT s.graderid $base JOIN {smartworkbook_submission} s ON s.workbookid = w.id
              WHERE cm.id = :cmid AND s.graderid IS NOT NULL", $params);
        $userlist->add_from_sql('userid',
            "SELECT r.userid $base JOIN {smartworkbook_response} r ON r.workbookid = w.id WHERE cm.id = :cmid", $params);
    }

    /**
     * Export a user's submission, responses and released marks.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('smartworkbook', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $workbookid = $cm->instance;

            $questions = $DB->get_records('smartworkbook_question', ['workbookid' => $workbookid], 'sortorder ASC',
                'id, label, questiontext, qtype');

            $answers = [];
            $responses = $DB->get_records('smartworkbook_response', ['workbookid' => $workbookid, 'userid' => $userid]);
            foreach ($responses as $r) {
                $q = $questions[$r->questionid] ?? null;
                $answers[] = (object)[
                    'question'     => $q ? format_string($q->label ?: strip_tags($q->questiontext)) : $r->questionid,
                    'response'     => $r->responsetext,
                    'timemodified' => transform::datetime($r->timemodified),
                ];
            }

            $submission = $DB->get_record('smartworkbook_submission', ['workbookid' => $workbookid, 'userid' => $userid]);
            $subdata = null;
            if ($submission) {
                $marks = [];
                foreach ($DB->get_records('smartworkbook_mark', ['submissionid' => $submission->id]) as $m) {
                    $q = $questions[$m->questionid] ?? null;
                    $marks[] = (object)[
                        'question'        => $q ? format_string($q->label ?: strip_tags($q->questiontext)) : $m->questionid,
                        'ai_mark'         => $m->ai_mark,
                        'ai_comment'      => $m->ai_comment,
                        'teacher_mark'    => $m->teacher_mark,
                        'teacher_comment' => $m->teacher_comment,
                        'status'          => $m->status,
                    ];
                }
                $subdata = (object)[
                    'status'           => $submission->status,
                    'grade'            => $submission->grade,
                    'total_marks'      => $submission->total_marks,
                    'max_marks'        => $submission->max_marks,
                    'teacher_feedback' => $submission->teacher_feedback,
                    'groupmembers'     => $submission->meta_json,
                    'timesubmitted'    => $submission->timesubmitted ? transform::datetime($submission->timesubmitted) : null,
                    'timegraded'       => $submission->timegraded ? transform::datetime($submission->timegraded) : null,
                    'marks'            => $marks,
                ];
            }

            $graded = $DB->count_records('smartworkbook_submission', ['workbookid' => $workbookid, 'graderid' => $userid]);

            if (!$answers && !$subdata && !$graded) {
                continue;
            }

            $data = helper::get_context_data($context, $contextlist->get_user());
            $data->responses = $answers;
            $data->submission = $subdata;
            if ($graded) {
                $data->submissionsgradedbyyou = $graded;
            }
            writer::with_context($context)->export_data([], $data);
            helper::export_context_files($context, $contextlist->get_user());
        }
    }

    /**
     * Delete all user data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('smartworkbook', $context->instanceid);
        if (!$cm) {
            return;
        }
        self::delete_workbook_userdata($cm->instance, null);
    }

    /**
     * Delete one user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('smartworkbook', $context->instanceid);
            if ($cm) {
                self::delete_workbook_userdata($cm->instance, [$userid]);
            }
        }
    }

    /**
     * Delete data for several users in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('smartworkbook', $context->instanceid);
        if ($cm && $userlist->get_userids()) {
            self::delete_workbook_userdata($cm->instance, $userlist->get_userids());
        }
    }

    /**
     * Remove submissions, marks and responses (all users when $userids is null).
     * Where a deleted user only appears as grader, the graderid is cleared.
     *
     * @param int $workbookid
     * @param int[]|null $userids
     */
    protected static function delete_workbook_userdata(int $workbookid, ?array $userids): void {
        global $DB;

        if ($userids === null) {
            $subids = $DB->get_fieldset_select('smartworkbook_submission', 'id', 'workbookid = ?', [$workbookid]);
            if ($subids) {
                $DB->delete_records_list('smartworkbook_mark', 'submissionid', $subids);
            }
            $DB->delete_records('smartworkbook_submission', ['workbookid' => $workbookid]);
            $DB->delete_records('smartworkbook_response', ['workbookid' => $workbookid]);
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = ['wbid' => $workbookid] + $inparams;

        $subids = $DB->get_fieldset_select('smartworkbook_submission', 'id',
            "workbookid = :wbid AND userid $insql", $params);
        if ($subids) {
            $DB->delete_records_list('smartworkbook_mark', 'submissionid', $subids);
            $DB->delete_records_list('smartworkbook_submission', 'id', $subids);
        }
        $DB->delete_records_select('smartworkbook_response', "workbookid = :wbid AND userid $insql", $params);
        $DB->set_field_select('smartworkbook_submission', 'graderid', null, "workbookid = :wbid AND graderid $insql", $params);
    }
}
