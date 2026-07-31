<?php
/**
 * AI Smart Workbook - English language strings.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Core plugin strings
$string['modulename']            = 'AI Smart Workbook';
$string['modulenameplural']      = 'AI Smart Workbooks';
$string['modulename_help']       = 'Build an interactive, AI-marked workbook directly in Moodle. Teachers add questions (short answer, extended response, yes/no, rating, table, heading, image, video), students type answers, AI auto-marks submissions, and the teacher approves before grades are released.';
$string['pluginname']            = 'AI Smart Workbook';
$string['pluginadministration']  = 'AI Smart Workbook administration';

// Capabilities
$string['smartworkbook:addinstance'] = 'Add a new AI Smart Workbook';
$string['smartworkbook:view']        = 'View AI Smart Workbook';
$string['smartworkbook:submit']      = 'Submit answers to AI Smart Workbook';
$string['smartworkbook:grade']       = 'Grade AI Smart Workbook submissions';
$string['smartworkbook:manage']      = 'Manage AI Smart Workbook (add, edit questions)';

// Form fields
$string['grade']             = 'Grade';
$string['gradepasspercentage']       = 'Passing Grade (%)';
$string['gradepasspercentage_help']  = '<p>The minimum percentage score a student must achieve for this workbook to be counted as <strong>passed</strong>.</p>
<p><strong>How it works:</strong> AI Smart Workbook always grades out of 100%. If you enter <em>70</em> here, a student needs to earn at least 70% of the available marks to pass. Their raw marks across all questions are automatically scaled to a percentage before being compared against this threshold.</p>
<p><strong>Completion conditions:</strong> This value powers the <em>"Student must receive a passing grade"</em> option under Completion conditions. If you want Moodle to automatically mark the activity complete only when a student passes, set a passing grade here and enable that completion condition.</p>
<p><strong>Gradebook:</strong> The passing grade is also visible in the Moodle gradebook and on student grade reports — students can see whether they have passed or are still below the threshold.</p>
<p>Leave blank (or enter 0) if you do not require a passing threshold — all submitted workbooks will be treated as complete regardless of the score.</p>';
$string['gradepasspercentage_error'] = 'Passing grade must be between 0 and 100.';

// Workbook statuses
$string['status']              = 'Status';
$string['status_setup']        = 'Not yet configured - add questions to begin';
$string['status_ready']        = 'Questions ready - not yet published to students';
$string['status_published']    = 'Published - students can now submit answers';

// Teacher UI
$string['teacherdashboard']    = 'Teacher Dashboard';
$string['viewasstudent']       = 'View as Student';
$string['preview_banner']      = 'Teacher Preview &mdash; This is how your workbook appears to students. Autosave and submission are disabled here. To experience the full student workflow, <strong>log in to Moodle as a real student</strong>.';
$string['reviewquestions']     = 'Review & Edit Questions';
$string['publishworkbook']     = 'Publish to Students';
$string['unpublishworkbook']   = 'Unpublish';
$string['generatemodelanswers'] = 'Generate Model Answers';
$string['generatingmodelanswers'] = 'Generating model answers...';
$string['modelanswerscost']    = '3 credits will be deducted for model answer generation.';
$string['editquestion']        = 'Edit question';
$string['deletequestion']      = 'Delete question';
$string['addquestion']         = 'Add question';
$string['savequestions']       = 'Save questions';
$string['questiontext']        = 'Question text';
$string['marks']               = 'Marks';
$string['modelanswer']         = 'Model answer';
$string['rubricnotes']         = 'Rubric notes';
$string['qtype']               = 'Question type';
$string['qtype_text']          = 'Short answer';
$string['qtype_long']          = 'Extended response';
$string['qtype_heading']       = 'Section heading';
$string['qtype_yesno']         = 'Yes / No';
$string['qtype_rating']        = 'Rating scale';
$string['qtype_table']         = 'Table';

// Submissions
$string['submissions']         = 'Student Submissions';
$string['nosubmissions']       = 'No submissions yet.';
$string['submissionstatus']    = 'Submission status';
$string['status_draft']        = 'In progress';
$string['status_submitted']    = 'Submitted';
$string['status_ai_marked']    = 'AI marked - awaiting approval';
$string['status_grades_released'] = 'Grades released';
$string['status_reanswer']     = 'Re-answer required';
$string['aimarkall']           = 'AI Mark All Submitted';
$string['aimarkthis']          = 'AI Mark This Submission';
$string['aimarking']           = 'Running AI marking...';
$string['aimarkcost']          = '5 credits will be deducted per submission.';
$string['releasegrades']       = 'Release Grades to Student';
$string['releaseallgrades']    = 'Release All Approved Grades';
$string['gradesreleased']      = 'Grades released successfully.';
$string['markingconsole']      = 'Marking Console';
$string['studentanswer']       = 'Student answer';
$string['aisuggestion']        = 'AI suggestion';
$string['yourmark']            = 'Your mark';
$string['yourcomment']         = 'Your comment';
$string['approveai']           = 'Approve AI mark';
$string['overridemark']        = 'Override';
$string['flagreanswer']        = 'Needs re-answer';
$string['resetsubmission']     = 'Request Re-answer from Student';
$string['savemark']            = 'Save mark';
$string['totalmark']           = 'Total: {$a->earned} / {$a->max}';
$string['classaverage']        = 'Class average';

// Student UI
$string['workbookintro']       = 'Complete all questions below and click Submit when you are done.';
$string['autosaved']           = 'Saved';
$string['saving']              = 'Saving...';
$string['savefailed']          = 'Save failed - check your internet connection';
$string['saveprogress']        = 'Save Progress';
$string['submitworkbook']      = 'Submit Workbook';
$string['resubmitworkbook']    = 'Resubmit Workbook';
$string['confirmsubmit']       = 'Are you sure you want to submit? You will not be able to change your answers after submitting.';
$string['confirmresubmit']     = 'Are you sure you want to resubmit? Only the flagged questions will be re-marked.';
$string['submitted']           = 'Your workbook has been submitted. Your teacher will release your grade when marking is complete.';
$string['alreadysubmitted']    = 'You have already submitted this workbook.';
$string['feedbackreleased']    = 'Your results are available.';
$string['yourgrade']           = 'Your grade';
$string['marksearned']         = '{$a->earned} / {$a->max} marks';
$string['reanswerflag']        = 'Your teacher has asked you to re-answer this question.';
$string['reanswer_notice']     = 'Your teacher has asked you to re-answer one or more questions. The flagged questions below are unlocked — update your answers and click Resubmit when done.';
$string['notyetpublished']     = 'This workbook is not yet published. Please check back later.';

// Errors
$string['error_nofile']        = 'Please select a file to upload.';
$string['error_filetype']      = 'Only .docx and .pdf files are supported.';
$string['error_conversion']    = 'Conversion failed. Please check your AI Grader credits and try again.';
$string['error_nocredentials'] = 'AI Grader credentials not configured. Please install and configure the AI Central Config plugin.';
$string['error_marking']       = 'AI marking failed. Please try again.';
$string['error_notpublished']  = 'This workbook has not been published yet.';
$string['error_alreadysubmitted'] = 'You have already submitted this workbook.';
$string['error_insufficientcredits'] = 'Insufficient AI Grader credits. Please top up your credits and try again.';

// Workbook display options (mod_form)
$string['displayoptions']        = 'Workbook display options';
$string['showstudentname']       = 'Show student name field';
$string['showstudentname_help']  = '<p>When ticked, a <strong>Student Name</strong> field appears at the very top of the student workbook, automatically pre-filled with the student\'s Moodle full name.</p>
<p><strong>Read-only:</strong> The field cannot be edited by the student — it is drawn directly from their Moodle account. This ensures the name on the submission always matches the enrolled student.</p>
<p><strong>When to use this:</strong></p>
<ul>
  <li>When workbooks are printed or exported to PDF and need to be clearly identified by student name.</li>
  <li>When your RTO requires the student\'s name to appear on submitted evidence (e.g. for a learner portfolio or as part of an assessment record).</li>
  <li>When you want to visually confirm identity on screen before marking begins.</li>
</ul>
<p><strong>Group workbooks:</strong> If you are using <em>Group member slots</em> (see below), students will fill in all group member names manually. In that case you may prefer to leave this unticked and rely on the group member fields instead.</p>
<p>Individual activities with a single submitter benefit most from this setting.</p>';
$string['numgroupmembers']       = 'Group member slots';
$string['numgroupmembers_help']  = '<p>Adds a set of editable <strong>Member name</strong> fields at the top of the student workbook so a group can record every participant\'s name on their shared submission.</p>
<p><strong>How it works:</strong> Choose how many member slots to display (1–6). One student opens and submits the workbook on behalf of the group — before answering they fill in the names of everyone contributing. All slots are free-text fields; they do not need to match enrolled Moodle usernames.</p>
<p><strong>Grading:</strong> The submitted workbook (and its AI-marked grade) belongs to the student who pressed Submit. If you want the same grade applied to all group members, use Moodle\'s <em>Groups</em> feature in combination with the gradebook — the group member name slots here are for identification purposes only, not for automatic grade distribution.</p>
<p><strong>When to use this:</strong></p>
<ul>
  <li>Collaborative assessments where a group produces a single written response (e.g. a workplace health and safety plan submitted by a team).</li>
  <li>Any task where the RTO requires all contributing learners to be named on the evidence document.</li>
  <li>Scenarios where printing or exporting the workbook needs a clear record of who participated.</li>
</ul>
<p><strong>Individual activities:</strong> Leave this set to <em>None (individual activity)</em> when every student submits their own workbook independently.</p>
<p><strong>Show student name field:</strong> When group slots are enabled you may want to untick <em>Show student name field</em> above, as the group member slots already capture all participant names.</p>';
$string['numgroupmembers_none']  = 'None (individual activity)';
$string['studentname_label']     = 'Student Name';
$string['groupmembers_label']    = 'Group Members';
$string['groupmember_n']         = 'Member {$a}';

// Privacy
$string['privacy:metadata:smartworkbook_response']         = 'Stores student answers for each question.';
$string['privacy:metadata:smartworkbook_response:userid']  = 'The user who provided the answer.';
$string['privacy:metadata:smartworkbook_response:responsetext'] = 'The student\'s typed answer.';
$string['privacy:metadata:smartworkbook_submission']       = 'Stores student submission records and grades.';
$string['privacy:metadata:smartworkbook_submission:userid'] = 'The user who made the submission.';
$string['privacy:metadata:smartworkbook_submission:grade'] = 'The grade awarded for the submission.';
