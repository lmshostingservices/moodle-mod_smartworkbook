<?php
/**
 * AI Smart Workbook - Upgrade script.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_smartworkbook_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026070700100) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['lib.php', 'version.php', 'view.php', 'ajax.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026070700100, 'smartworkbook');
    }

    if ($oldversion < 2026070700101) {
        // v1.0.1 — re-answer flow, table qtype renderer, full question editor,
        //           credit deduction on server endpoints.
        // No DB schema changes required; all fixes are PHP/JS/server-side.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['lib.php', 'version.php', 'view.php', 'ajax.php',
                      'teacher.php', 'styles.css', 'db/upgrade.php',
                      'amd/build/smartworkbook.js', 'amd/build/smartworkbook.min.js'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026070700101, 'smartworkbook');
    }

    if ($oldversion < 2026070700102) {
        // v1.0.2 — security: require_sesskey() added to ajax.php to validate
        //           Moodle session key on every AJAX request (CSRF hardening).
        //           No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026070700102, 'smartworkbook');
    }

    // v1.0.3 — FIX-REQUIRE-LOGIN: Replaced require_login() with direct isloggedin()/isguestuser()
    //           check. require_login() on Moodle 4.4+/5.x throws "coursehidden" exception in AJAX
    //           context, blocking all student auto-save and teacher marking actions. No DB changes.
    if ($oldversion < 2026070900103) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026070900103, 'smartworkbook');
    }

    // v1.0.4 — FIX-FILELIB: Added require_once(filelib.php) to ajax.php.
    //           \curl class (used in convert, ai_mark, generate_model_answers) is defined
    //           in filelib.php which is NOT auto-loaded in the Moodle AJAX bootstrap.
    //           Without this, all three actions threw a fatal "Class not found" error.
    //           No DB schema changes.
    if ($oldversion < 2026070900104) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026070900104, 'smartworkbook');
    }

    // v1.0.5: FIX-EMPTY-CLASSES
    // Removed empty classes/ and classes/event/ directories from the plugin source.
    // Moodle's plugin validator rejects ZIPs containing bare directory entries with
    // no PHP files inside — throws "Extracted file not found" on install. The plugin
    // has no custom event classes; index.php uses core \core\event\course_module_instance_list_viewed.
    // No DB schema changes.
    if ($oldversion < 2026070900105) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026070900105, 'smartworkbook');
    }

    // v1.0.6: FIX-LANGSTRING-GRADE
    // Added $string['grade'] = 'Grade' to lang/en/smartworkbook.php.
    // mod_form.php grade section header used get_string('grade') with no component;
    // Moodle displayed [[grade]] on the activity settings page because the string
    // was absent from the plugin lang file. No DB schema changes.
    if ($oldversion < 2026071100106) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php', 'lang/en/smartworkbook.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071100106, 'smartworkbook');
    }

    // v1.0.7: FIX-GRADEPASS
    // Added gradepass field to mod_form.php and wired it through data_preprocessing()
    // and smartworkbook_grade_item_update() in lib.php.
    // Fixes "This activity does not have a valid grade to pass set" error in the
    // Completion conditions panel. No DB schema changes.
    if ($oldversion < 2026071300107) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['mod_form.php', 'lib.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300107, 'smartworkbook');
    }

    // v1.0.8: FIX-CREDENTIALS
    // Fixed smartworkbook_get_api_credentials() in lib.php:
    //   - Removed table_exists('local_aiconfig') check (no such table exists).
    //   - Changed key names from site_id/api_key to siteid/apikey to match
    //     local_aiconfig's actual config_plugins storage.
    // All AI actions (convert, ai_mark, generate_model_answers) were silently
    // receiving empty credentials and returning "credentials not configured".
    if ($oldversion < 2026071300108) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['lib.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300108, 'smartworkbook');
    }

    // v1.0.9: FIX-UI
    // Fixed "AI Mark All Submitted" button text cutoff in teacher.php.
    // Fixed "Generate Model Answers" — now auto-reloads the page on success
    // instead of showing a manual "reload the page" alert.
    if ($oldversion < 2026071300109) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300109, 'smartworkbook');
    }

    // v1.0.10: FIX-QINDEX
    // Fixed question numbering in student view (view.php). q_index was incremented
    // at the top of the foreach loop for ALL item types including headings. Since
    // headings branch to continue without printing a number, the counter was eaten
    // silently, shifting every subsequent question number up by 1 per heading above it.
    // Fix: moved q_index++ to AFTER the heading check so only non-heading items count.
    if ($oldversion < 2026071300110) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['view.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300110, 'smartworkbook');
    }

    // v1.0.11: ADD display options + group members metadata
    // smartworkbook table: showstudentname, numgroupmembers
    // smartworkbook_submission table: meta_json (stores group member names as JSON)
    if ($oldversion < 2026071300111) {
        $table = new xmldb_table('smartworkbook');

        $field = new xmldb_field('showstudentname', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'model_answers_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('numgroupmembers', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0', 'showstudentname');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $sub_table = new xmldb_table('smartworkbook_submission');
        $meta_field = new xmldb_field('meta_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timegraded');
        if (!$dbman->field_exists($sub_table, $meta_field)) {
            $dbman->add_field($sub_table, $meta_field);
        }

        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['mod_form.php', 'view.php', 'ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }

        upgrade_mod_savepoint(true, 2026071300111, 'smartworkbook');
    }

    // v1.0.12: FIX-GRADE-HEADER — changed get_string('grade') to get_string('grade','smartworkbook')
    // so the Grade section header in mod_form.php resolves from the plugin's own lang file
    // instead of relying on Moodle core component lookup, which was failing on some installations.
    if ($oldversion < 2026071300112) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['mod_form.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300112, 'smartworkbook');
    }

    // v1.0.13: ADD-EXTRACT-FLAGS
    // Added extract_instructions=true and include_metadata_fields=true to the convert API payload.
    // These flags tell the lms-labs.com API to also return non-question instructional text
    // blocks (flow diagrams, task descriptions, sub-instructions) as heading items, and to extract
    // Student Name / Group Members metadata fields as text questions with marks 0.
    if ($oldversion < 2026071300113) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300113, 'smartworkbook');
    }

    // v1.0.14: DOCX-MERGE
    // Added server-side DOCX paragraph extractor (smartworkbook_docx_paragraphs).
    // The convert action now parses the uploaded .docx itself to recover all
    // instructional prose text (flow diagrams, task descriptions, sub-instructions)
    // that the API strips out, and merges them with API question data in document order.
    if ($oldversion < 2026071300114) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300114, 'smartworkbook');
    }

    // v1.0.15: MARKS-BANNER
    // Added total marks / passing grade banner to the student workbook view.
    // Reads total question marks from the DB and gradepass/grademax from the
    // Moodle grade item. Shows "Total marks: X" and
    // "You need to score Y% (Z/X marks) to pass" when a passing grade is set.
    if ($oldversion < 2026071300115) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['view.php', 'version.php', 'styles.css', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300115, 'smartworkbook');
    }

    // v1.0.16: PREMIUM-UI
    // Complete end-to-end premium CSS/UX redesign of both teacher.php and student
    // view.php. New design system: indigo primary palette, card-based layout,
    // stats strip (teacher), SVG upload icon, improved status badges with dot
    // indicators, premium question cards with numbered badges, refined marking
    // console, better yes/no and rating inputs, enhanced score/pass card.
    if ($oldversion < 2026071300116) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['view.php', 'teacher.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300116, 'smartworkbook');
    }

    // v1.0.17: DOCUMENT-UI
    // Redesigned student view CSS from SaaS-app style to document/worksheet style.
    // Marks banner: replaced purple gradient with clean white bordered box.
    // Section headings: replaced sidebar left-border pill with document bold heading + bottom border.
    // Question items: removed card boxes (shadows, rounded corners, per-question borders) —
    //   now flat document flow separated by hairline dividers.
    // Answer textareas: cleaner border, italic placeholder, blue focus ring.
    // Table questions: Word-style table borders (solid outer, inner grid lines).
    // Q-label: dark (#1f2937) pill. Marks badge: clean border, no fill.
    // Score card, notices, feedback block: lighter, document-appropriate styling.
    if ($oldversion < 2026071300117) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['view.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300117, 'smartworkbook');
    }

    // v1.0.18: Instruction text rendering overhaul.
    // Previously every DOCX paragraph stored as qtype='heading' rendered as an
    // individual bold underlined line with 32px top margin — completely wrong for
    // multi-paragraph instruction blocks from Word docs.
    // Fix: view.php now groups consecutive heading-type questions into a single
    // .sw-instruction-block rendered as flowing <p> tags. First paragraph of a
    // multi-para block gets sw-instr-title (bold, bottom rule) to represent the
    // section/task heading; subsequent paragraphs get sw-instr-para (normal weight
    // body text). Single-paragraph blocks still show as bold headings.
    // CSS: removed per-paragraph dark bottom border and 32px top margin; added
    // .sw-instruction-block, .sw-instr-title, .sw-instr-para rules.
    if ($oldversion < 2026071300118) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['view.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300118, 'smartworkbook');
    }

    // v1.0.19: DOCX conversion overhaul — three root bugs fixed:
    // 1. Global $seen deduplication removed all repeated question texts (e.g.
    //    "Who is involved?" in stages 2-6 of food worksheet vanished entirely).
    // 2. Single-value api_map ($api_map[$key]=$q) overwrote duplicates — only
    //    the last API question with that text survived.
    // 3. $used[$key]=true after first match meant the safety net also skipped
    //    repeated questions, so stages 2-6 questions never appeared at all.
    // Fix: replaced smartworkbook_docx_paragraphs() with smartworkbook_docx_blocks()
    // (walks body children, no global dedup, coloured table cells become dochtml
    // section-header blocks), api_map changed to multi-map (array_shift consume
    // pattern), added smartworkbook_fuzzy_match() for suffix/prefix matching.
    // New qtype='dochtml' for coloured table cell section headers rendered directly
    // as HTML in view.php (excluded from marks/AI-marking/model-answer loops).
    if ($oldversion < 2026071300119) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'view.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300119, 'smartworkbook');
    }

    // v1.0.20: VIEW-AS-STUDENT
    // Added "View as Student" button to teacher dashboard (teacher.php).
    // Added ?preview=1 mode to view.php: bypasses teacher redirect and publish
    // gate, suppresses all submission/autosave state, renders workbook read-only
    // with an amber "Teacher Preview" banner and a back-to-dashboard link.
    // AMD js_call_amd skipped in preview mode to prevent autosave/submit JS from
    // firing. Submit button hidden. New CSS: .sw-preview-banner in styles.css.
    if ($oldversion < 2026071300120) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'view.php', 'styles.css', 'version.php',
                      'db/upgrade.php', 'lang/en/smartworkbook.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300120, 'smartworkbook');
    }

    // v1.0.21: VIEW-AS-STUDENT-OVERLAY
    // Moved student preview entirely into teacher.php as a full-screen overlay.
    // Renders all questions in read-only student style (dochtml, heading groups,
    // text/long/yesno/rating/table) with the amber preview banner and Close button.
    // JS open/close + ESC key. No longer depends on view.php at all, bypassing
    // any opcache issues on that file. CSS: .sw-student-preview-overlay in styles.css.
    if ($oldversion < 2026071300121) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300121, 'smartworkbook');
    }

    // v1.0.22: WORKBOOK-SETTINGS-CARD
    // Added "Workbook Settings" card on teacher dashboard to control showstudentname
    // and numgroupmembers. Teacher can toggle student name field on/off and pick
    // 0-6 group member slots; changes save via new save_settings AJAX action with
    // instant "Saved" feedback. Student preview overlay also reflects the current
    // settings live (no page reload). CSS: .sw-settings-* in styles.css.
    if ($oldversion < 2026071300122) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'ajax.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300122, 'smartworkbook');
    }

    // v1.0.23: DOCX-NESTED-TABLE-PARSE + BASE64-CHUNK
    // Fix 1 (ajax.php): DOCX parser changed from xpath->query('w:p', $tc) to
    //   xpath->query('.//w:p', $tc) so paragraphs inside nested tables inside
    //   table cells are captured — the "Presenting to the Class" section at the
    //   bottom of worksheet templates uses this pattern and was silently dropped.
    //   array_unique() added to prevent duplicates when outer+inner cell paragraphs
    //   both appear in the same walk.
    // Fix 2 (teacher.php): Replaced String.fromCharCode.apply(null, Uint8Array)
    //   with a chunked 32768-byte approach to avoid V8 call-stack overflow on
    //   files larger than ~65 KB; the old code silently truncated base64 output.
    if ($oldversion < 2026071300123) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300123, 'smartworkbook');
    }

    // v1.0.24: DOCX-NESTED-TABLE-CONDITIONAL
    // Fix (ajax.php): v1.0.23's unconditional .//w:p descent caused duplication —
    // summary/overview tables at the end of some DOCX files had their deeply-nested
    // paragraphs captured a second time, doubling entire worksheet sections.
    // New strategy: use direct w:p children first; only if empty descend exactly ONE
    // table level (w:tbl/w:tr/w:tc/w:p). This captures "Presenting to the Class"
    // nested-table questions without touching paragraphs in deeply-nested summary blocks.
    if ($oldversion < 2026071300124) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300124, 'smartworkbook');
    }

    // v1.0.25: HOWTO-CARD + DOCS-UPDATE
    // Added collapsible "How to use AI Smart Workbook" quick-start instruction card
    // to the teacher dashboard (teacher.php), with 8 numbered steps covering the full
    // workflow: upload → review → generate answers → preview → settings → publish →
    // student submission → AI mark & release. Card is dismissible per-activity via
    // localStorage. Added CSS (.sw-howto-*) to styles.css. Updated SmartWorkbook.tsx
    // docs page: expanded teacher steps to 8, added View as Student and Workbook
    // Settings sections to the Teacher Guide tab.
    if ($oldversion < 2026071300125) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300125, 'smartworkbook');
    }

    // v1.0.26: MANUAL-GRADING-MODE
    // Add manual_grading column to smartworkbook table. When 1, the teacher dashboard
    // hides AI marking buttons and shows a manual grading checklist instead. The checklist
    // lists every question with its max marks, the student's answer, a mark input (with
    // "Full marks" checkbox shortcut), and an optional comment. Saving the checklist writes
    // approved marks to smartworkbook_mark and releases grades to the Moodle gradebook
    // directly — no AI credits used. New AJAX action: manual_mark_submission.
    if ($oldversion < 2026071300126) {
        $table = new xmldb_table('smartworkbook');
        $field = new xmldb_field('manual_grading', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'numgroupmembers');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'ajax.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300126, 'smartworkbook');
    }

    // v1.0.27: GRADING-ACCURACY
    // Fix max_marks calculation across all three grading paths (ai_mark, save_mark,
    // manual_mark_submission). Previously max_marks was computed from mark records /
    // AI response — if any questions were missed, max_marks was too low and the
    // released percentage was inflated. Now all three paths compute total_max from
    // the full question table (excluding heading/dochtml rows). Also fixed save_mark
    // to skip reanswer-flagged questions in total_earned. No schema change.
    if ($oldversion < 2026071300127) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300127, 'smartworkbook');
    }

    // v1.0.28: GRADE-UX
    // Removed "Maximum grade" field from the activity settings form — it is now
    // always fixed at 100 internally. Replaced the confusing "Grade to pass"
    // (an absolute number relative to grademax) with a "Passing Grade (%)" field
    // that teachers enter as a 0–100 percentage. No schema change; grademax=100
    // is enforced in smartworkbook_grade_item_update() in lib.php.
    if ($oldversion < 2026071300128) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['mod_form.php', 'lib.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300128, 'smartworkbook');
    }

    // v1.0.29: FIX-ORPHANED-HEADINGS
    // view.php and teacher.php preview: heading blocks that have no answerable
    // questions following them are now silently suppressed instead of rendering
    // as a confusing empty section at the bottom of the workbook.
    // Also fixed single-heading styling: first paragraph always gets sw-instr-title
    // regardless of whether there are subsequent paragraphs in the block.
    // No schema change.
    if ($oldversion < 2026071300129) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['view.php', 'teacher.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026071300129, 'smartworkbook');
    }

    // FEAT-PLATFORM-MG-CONTROL (v1.0.31): teacher.php now calls the AI Grader platform
    // grading-check API on load. Platform admins can enable/disable manual grading per
    // school, course, or activity from the admin portal. No DB schema changes in Moodle.
    if ($oldversion < 2026072000131) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072000131, 'smartworkbook');
    }

    // FEAT-PLATFORM-PASS-PCT (v1.0.32): teacher.php now reads passing_percentage from
    // the platform grading-check response and applies it directly to Moodle's grade-to-pass
    // field (gradepass) on each teacher page load. Admin portal lets you set pass % per
    // school, course, or activity (cascades from most specific to least specific).
    // No new Moodle DB columns — pass% lives in the platform DB.
    if ($oldversion < 2026072000132) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'lib.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072000132, 'smartworkbook');
    }

    // FEAT-HELP-STRINGS (v1.0.33): Fully written help text for all three mod_form
    // question-mark fields: gradepasspercentage, showstudentname, numgroupmembers.
    // No functional code changes — lang string update only.
    if ($oldversion < 2026072000133) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['lang/en/smartworkbook.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072000133, 'smartworkbook');
    }

    // FEAT-BACKUP-RESTORE (v1.0.34): Full Moodle backup/restore support added.
    // backup/moodle2/ directory created with all four required files:
    //   backup_smartworkbook_activity_task.class.php
    //   backup_smartworkbook_stepslib.php
    //   restore_smartworkbook_activity_task.class.php
    //   restore_smartworkbook_stepslib.php
    // Backs up: workbook settings, all questions (text/marks/model answers/rubric),
    // and optionally (userinfo=true) submissions, per-question marks, and responses.
    // Source file (DOCX/PDF) is not included; all parsed questions ARE restored.
    if ($oldversion < 2026072000134) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'backup/moodle2/backup_smartworkbook_activity_task.class.php',
                'backup/moodle2/backup_smartworkbook_stepslib.php',
                'backup/moodle2/restore_smartworkbook_activity_task.class.php',
                'backup/moodle2/restore_smartworkbook_stepslib.php',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072000134, 'smartworkbook');
    }

    // FEAT-SAVE-BUTTON (v1.0.35): Added "Save Progress" button at bottom of student workbook.
    // Appears alongside the Submit button so students don't need to scroll up.
    // Clicking it saves all textareas, radios, table grids, and group member fields at once.
    // Changes: view.php (button HTML), amd/src+build/smartworkbook.js (saveAll/bindSaveAll),
    //          lang/en/smartworkbook.php (saveprogress string). No DB schema changes.
    if ($oldversion < 2026072000135) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'view.php',
                'lang/en/smartworkbook.php',
                'amd/build/smartworkbook.js',
                'amd/build/smartworkbook.min.js',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072000135, 'smartworkbook');
    }

    // FEAT-RTE (v1.0.36): Question text field in teacher editor upgraded to rich text editor.
    // Replaced plain <textarea> with a contenteditable div + formatting toolbar (bold, italic,
    // underline, H2, H3, paragraph, text colour, clear formatting). Save JS now collects
    // innerHTML instead of .value. view.php already uses format_text(FORMAT_HTML) so student
    // view renders the formatting correctly with no DB schema changes.
    if ($oldversion < 2026072000136) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'teacher.php',
                'styles.css',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072000136, 'smartworkbook');
    }

    if ($oldversion < 2026072000137) {
        // v1.0.37: Structured multi-column table support added to table qtype.
        // Data stored as JSON in model_answer; no DB schema changes required.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'view.php',
                'teacher.php',
                'styles.css',
                'version.php',
                'amd/build/smartworkbook.min.js',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072000137, 'smartworkbook');
    }

    if ($oldversion < 2026072000138) {
        // v1.0.38 — Section icon badges: auto-detect section type from heading text
        // and render inline SVG icon in Moodle primary theme colour.
        // New CSS classes: sw-section-block, sw-section-badge, sw-section-icon,
        // sw-section-title-text, sw-section-body, sw-section-para.
        // Helper functions added to lib.php: smartworkbook_section_type(),
        // smartworkbook_section_svg(), smartworkbook_section_badge_html().
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'lib.php',
                'view.php',
                'teacher.php',
                'ajax.php',
                'styles.css',
                'version.php',
                'db/upgrade.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072000138, 'smartworkbook');
    }

    if ($oldversion < 2026072000139) {
        // v1.0.39: Upgraded section icon SVGs from stroke-based Lucide style to
        // Heroicons Solid filled icons — matching the flat bold filled icon style
        // used in the Word document section headers (measuring jug, tractor, etc.).
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['lib.php', 'version.php', 'view.php', 'ajax.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072000139, 'smartworkbook');
    }

    if ($oldversion < 2026072000140) {
        // v1.0.40: Section headers redesigned to match FoodTechGurus Word doc colour palette.
        // Each section type now gets its own background colour via data-swtype CSS attribute:
        // salmon pink #FAC8C9 (lesson/learning-intention/success-criteria),
        // cyan/teal #6FCCDD (topic/recipe/practical/stage),
        // light pink #FDE7E8 (prior-knowledge/task/assessment),
        // softer pink #FCE0E1 (wordsearch/article/presentation/resources/note).
        // Table-row style borders with dark text on light backgrounds to match Word doc.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['lib.php', 'version.php', 'view.php', 'ajax.php', 'styles.css', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072000140, 'smartworkbook');
    }

    if ($oldversion < 2026072100141) {
        // v1.0.41 — Image and video block support: DOCX import detects embedded
        // images and YouTube hyperlinks; teachers can paste/upload images and
        // enter YouTube URLs; student view renders them inline (display-only).
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['lib.php', 'version.php', 'view.php', 'ajax.php', 'teacher.php', 'styles.css', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100141, 'smartworkbook');
    }

    if ($oldversion < 2026072100142) {
        // v1.0.42 — DOCX-RICH-FORMATTING
        // Extended DOCX importer (smartworkbook_docx_blocks()) with three new
        // OOXML attribute parsers for better visual fidelity:
        //
        //   1. smartworkbook_para_html() — new helper that walks w:r runs and
        //      preserves bold (<strong>), italic (<em>), underline (<u>) and
        //      inline font colour (<w:color w:val="RRGGBB">) per run.
        //
        //   2. Cell background colour — section-header badge now uses the actual
        //      Word fill hex (#RRGGBB) from <w:shd w:fill="..."> as its CSS
        //      background-color instead of relying solely on the theme class.
        //
        //   3. Column widths — reads <w:tcW w:w="N"> and <w:tblGrid/w:gridCol>
        //      to compute a percentage width for each coloured-cell wrapper div,
        //      so section headers respect Word's proportional column layout.
        //
        // No DB schema changes — ajaxphp / version.php / upgrade.php only.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100142, 'smartworkbook');
    }

    if ($oldversion < 2026072100143) {
        // v1.0.43 — DOCHTML-EDITOR-FIX
        // Fixed teacher.php question editor: DOCX-imported coloured section blocks
        // (qtype='dochtml') were not excluded from the answerable-question checks,
        // so they appeared as numbered questions (Q1, Q2...) with an RTE showing
        // raw HTML. They now render as display-only 'SEC' rows (same CSS class as
        // HDG heading rows) with a read-only badge preview and no marks/label/
        // model-answer/rubric inputs — matching the correct student-view behaviour.
        //
        // No DB schema changes — teacher.php / version.php / upgrade.php only.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100143, 'smartworkbook');
    }

    if ($oldversion < 2026072100144) {
        // v1.0.44 — UPLOAD-SIZE-LIMIT
        // Added 40 MB client-side file size gate in teacher.php upload JS:
        // - Shows the selected file's size alongside its name.
        // - If file > 40 MB, displays a red inline error and blocks the Convert button
        //   (instead of letting the request reach the server and failing silently).
        // - Updated upload card hint and how-to step 1 to show "max 40 MB".
        // No DB schema changes — teacher.php / version.php / upgrade.php only.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100144, 'smartworkbook');
    }

    if ($oldversion < 2026072100145) {
        // v1.0.45 — RECURSIVE-NESTED-TABLE-PARSER
        // Root cause fix for "tables completely missing" in DOCX conversion:
        // The old parser used a flat w:tbl/w:tr/w:tc/w:p fallback (1 level deep)
        // when a cell had no direct w:p children. This silently stripped ALL
        // coloured-cell detection from nested tables and lost content beyond 1
        // nesting level. DOCXs that use a two-column TEACHER/STUDENT outer table
        // (whose white cells each contain inner tables with their own coloured
        // section headers) produced a completely flat, headerless output.
        // Fix: smartworkbook_walk_table() recursively descends into nested tables
        // at any depth, running full coloured-cell detection at every level.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100145, 'smartworkbook');
    }

    if ($oldversion < 2026072100146) {
        // v1.0.46 — DATA-TABLE-HTML-RENDERER
        // Full HTML table fidelity for DOCX data tables (3+ columns, merged
        // cells, explicit borders). smartworkbook_is_data_table() detects data
        // tables; smartworkbook_table_to_html() renders them with colspan
        // (w:gridSpan), rowspan (w:vMerge two-pass algorithm), cell padding
        // (w:tcMar), proportional column widths (w:tblGrid), background fill
        // (w:shd), text alignment (w:jc), and auto-inverted text colour for
        // dark fills. Data tables are emitted as 'html' blocks (stored as
        // dochtml rows in the workbook). Layout-only 2-column tables fall
        // through to the existing recursive cell-by-cell walker.
        // CSS: .sw-docx-table-wrap (overflow-x:auto) + .sw-docx-table in styles.css.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100146, 'smartworkbook');
    }

    if ($oldversion < 2026072100147) {
        // v1.0.47 — DOCX-EXTRACTOR-V2 + SAVE-SETTINGS-FIX
        //
        // Word-format fidelity improvements (DOCX extractor v1.0.20):
        //   • Body-level paragraphs now capture rich HTML ('html' key alongside
        //     'text') so bold labels, italic notes, and coloured text in
        //     unmatched paragraphs are preserved when saved as 'heading' qtypes.
        //   • smartworkbook_para_images(): new function extracts embedded images
        //     (DrawingML a:blip + VML v:imagedata) from body-level paragraphs
        //     that live outside tables — previously silently skipped.
        //   • YouTube hyperlink and bare-URL detection extended to body-level
        //     paragraphs (was previously table-cell only).
        //   • Short artifact paragraphs (< 3 chars: lone bullets, numbering
        //     glyphs, stray punctuation) silently discarded to avoid creating
        //     orphan 'heading' rows after fuzzy-match failure.
        //   • Unmatched paragraph → heading now stores rich HTML when available
        //     instead of plain-escaped text.
        //   • smartworkbook_fuzzy_match(): two new strategies added —
        //       Strategy 5: API key is a prefix of DOCX key.
        //       Strategy 6: DOCX key is a prefix of API key.
        //       Strategy 7: Jaccard word-overlap >= 0.70 (min 5 words each side).
        //     These catch paraphrased questions and questions with trailing
        //     qualifiers that the first four suffix strategies miss.
        //
        // Critical bug fix:
        //   • save_settings AJAX action was missing $cmid / $cm / $context /
        //     $workbook initialisation — PHP 8.x fatal on every settings save.
        //     Now correctly resolves the context from cmid before capability check.
        //
        // Minor:
        //   • save_meta: removed redundant require_sesskey() (already called
        //     globally at line 795).
        //   • Lang: corrected numgroupmembers_help "1–10" → "1–6" to match
        //     the actual server-side constraint and UI select.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'lang/en/smartworkbook.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100147, 'smartworkbook');
    }

    if ($oldversion < 2026072100148) {
        // v1.0.48 — WYSIWYG Document View: complete rethink of teacher interface.
        // Added Document View tab (renders questions as visual workbook paper) with
        // floating right-side edit panel. Question List tab preserved for power users.
        // Tab preference persisted per-CMID in localStorage. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100148, 'smartworkbook');
    }

    if ($oldversion < 2026072100149) {
        // v1.0.49 — Bug fixes: (1) save_questions no longer forces min 0.5 marks on
        // heading/dochtml/image/video types — those correctly save as 0. (2) savePanel
        // in the Document View floating panel sends marks=0 for heading type instead of
        // the incorrect default of 1. (3) _updateDocItem now re-renders the sw-stable
        // table HTML after a table question is saved via the panel. (4) Document View
        // delete confirm message is now context-aware (dochtml vs other types). (5) Fixed
        // duplicate id="sw-gm-saved" in group-member loop — indicator now appears once
        // after the loop. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'teacher.php', 'view.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100149, 'smartworkbook');
    }

    if ($oldversion < 2026072100150) {
        // v1.0.50 — DOCX table fidelity fixes:
        // (1) CRITICAL: Fixed dangling </tbody> emitted without a matching <tbody> when a
        //     data table has exactly one row that is detected as a header row.  The <tbody>
        //     tag is now guarded so it is only closed when it was actually opened (ri > 0
        //     or no header row), producing valid HTML in all single-row header tables.
        // (2) HIGH: Removed border-only check from smartworkbook_is_data_table().  Borders
        //     alone are no longer sufficient to classify a table as a data table.  Two-column
        //     TEACHER/STUDENT wrapper tables formatted with Word table borders were falsely
        //     flagged, causing all nested content to be flattened into a static HTML table
        //     instead of being walked recursively.  Data table detection now requires colspan
        //     merges, vMerge cells, or 3+ columns — matching real data-table semantics.
        // (3) MEDIUM: Fixed image ordering inside data table cells.  Images were previously
        //     appended after ALL paragraph text in a cell (cell-level extraction), which
        //     placed mid-cell images at the end regardless of their position in the DOCX.
        //     Per-paragraph extraction via smartworkbook_para_images() now interleaves
        //     images with their owning paragraph, preserving exact document order.
        // (4) LOW: Fixed teacher Question List badge numbers for dochtml question type —
        //     previously got Q-numbered instead of showing HDG.  Delete-question renumber
        //     handler was also missing image/video/dochtml cases; all now handled correctly.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'teacher.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100150, 'smartworkbook');
    }

    if ($oldversion < 2026072100151) {
        // v1.0.51 — CRITICAL: Fixed smartworkbook_is_data_table() using the mammoth.js
        // structural insight (industry gold standard for DOCX→HTML).
        //
        // Root cause: the previous heuristic used column count and borders to classify
        // tables.  A 2-column ingredients list (no merges, no 3+ cols) was being walked
        // cell-by-cell as a layout wrapper, DISCARDING the table structure entirely and
        // outputting each ingredient as a separate flat text item.
        //
        // Fix: the ONLY reliable distinguisher between a TEACHER/STUDENT layout wrapper
        // and a real data table is whether cells directly contain nested <w:tbl> elements.
        // TEACHER/STUDENT wrappers always have nested tables in their column cells.
        // Real data tables (ingredient lists, 2-column comparisons, recipe tables)
        // have ONLY <w:p> paragraphs in cells — no nested tables.
        //
        // New algorithm:
        //   1. Any colspan merge  → data table (render as HTML)
        //   2. Any rowspan merge  → data table (render as HTML)
        //   3. Any row with 3+   → data table (render as HTML)
        //   4. Any cell has w:tbl → layout wrapper (walk recursively)
        //   5. Everything else   → data table (render as HTML)
        //
        // Rule 5 is the key change: 2-column tables with only paragraph content are
        // now correctly rendered as HTML tables instead of being silently destroyed.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100151, 'smartworkbook');
    }

    if ($oldversion < 2026072100152) {
        // v1.0.52 — FULL TABLE OVERHAUL: mammoth.js approach.
        //
        // Every <w:tbl> in the document body now renders as a proper HTML <table>.
        // The smartworkbook_walk_table() / smartworkbook_is_data_table() heuristic
        // system that tried to distinguish "layout wrapper" tables from "data tables"
        // has been completely bypassed.  That heuristic was the root cause of all
        // table-related failures:
        //
        //   - 2-column ingredients lists → flat text (FIXED: renders as 2-col table)
        //   - Fill-in scaling grids      → lost (FIXED: renders as 4-col HTML table)
        //   - Recipe master tables       → destroyed (FIXED: merged headers preserved)
        //   - TEACHER/STUDENT wrappers   → now a 2-col HTML table (acceptable)
        //
        // Additionally: smartworkbook_table_to_html() now walks ALL direct cell
        // children (not just w:p paragraphs).  Nested w:tbl elements inside cells
        // are rendered recursively as inner HTML tables — preserving the structure of
        // fill-in cells whose blank writing lines are implemented as nested tables
        // with bottom borders, scaling grids inside colored section headers, etc.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100152, 'smartworkbook');
    }

    if ($oldversion < 2026072100153) {
        // v1.0.53 — HTML correctness + CSS fixes for mammoth table rendering.
        //
        // PHP fix (smartworkbook_table_to_html cell content joining):
        //   Before: implode('<br>', $parts) joined ALL cell parts with <br> — this
        //   placed an inline <br> element between block-level <div class="sw-docx-table-wrap">
        //   elements, which is invalid HTML and created stray blank lines between
        //   consecutive nested tables in the same cell.
        //   After:  block parts (sw-docx-table-wrap divs) are concatenated directly;
        //   text/image parts are wrapped in <p style="margin:0 0 0.25em 0"> and
        //   concatenated without separator — semantically correct, visually clean.
        //
        // CSS fixes (styles.css):
        //   1. Nested-table margin: .sw-docx-table td > .sw-docx-table-wrap margin
        //      set to 0 (was 0.75em top/bottom) to prevent double-gap inside cells.
        //   2. Alternating-row bleed: added override so the outer table's even-row
        //      rgba(0,0,0,0.02) tint does NOT compound into nested inner tables.
        //   3. Cell <p> margin: explicit 0 0 0.25em 0 rule for .sw-docx-table td > p
        //      matches the inline style we now emit, preventing browser-default
        //      large paragraph margins from breaking cell layout.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100153, 'smartworkbook');
    }

    if ($oldversion < 2026072100154) {
        // v1.0.54 — Two CSS correctness fixes for mammoth table rendering.
        //
        // Fix 1: Last-<p> margin leak.
        //   Every text paragraph in a cell is now wrapped in <p style="margin:0 0 0.25em 0">.
        //   Without a :last-child override, the FINAL paragraph in a cell leaks 0.25em
        //   bottom margin that stacks on top of the cell's own bottom padding, creating
        //   a visible double-gap between the last text line and the cell border.
        //   Added: .sw-docx-table td > p:last-child { margin-bottom: 0 }
        //
        // Fix 2: CSS color inheritance through dark-filled outer cells.
        //   CSS 'color' is inherited.  When PHP sets color:#ffffff on a dark-filled <td>
        //   (luminance < 128), that white propagates into every descendant — including
        //   the white cells of any nested inner <table> that have no explicit color.
        //   Result: white text on white/light background → invisible content.
        //   Added: .sw-docx-table .sw-docx-table td, .sw-docx-table .sw-docx-table th
        //          { color: #111827 } to reset to near-black for all nested-table cells.
        //   PHP's own inline color:#ffffff has higher specificity (inline > class) and
        //   still wins for inner cells that genuinely need white text.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100154, 'smartworkbook');
    }

    if ($oldversion < 2026072100155) {
        // v1.0.55 — Removed Word document import feature from teacher.php UI.
        //   • Removed the "Upload & Convert Workbook" card (file input + convert button + progress bar).
        //   • Removed the JS file-upload and convert handler.
        //   • Updated How To guide step 1 from "upload DOCX" to "add questions manually".
        //   • Renamed "Imported from Word" labels to neutral "HTML display block" text.
        //   • Existing dochtml records in the DB are unaffected and continue to render.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100155, 'smartworkbook');
    }

    if ($oldversion < 2026072100156) {
        // v1.0.56: Full review of Word-import removal.
        //   • Removed dead source_filename display from teacher header.
        //   • Removed $q_count > 0 gate on question editor — editor always shown.
        //   • Added empty state in question list when workbook has no questions.
        //   • Hid Document View pane (shown by default) when q_count=0; shows
        //     Question List with empty state instead.
        //   • Added "+ Add Question" button to editor header (always visible).
        //   • Added add_question AJAX action: inserts one blank text question
        //     at next sortorder; JS reloads page on success so editor initialises
        //     normally with the new question. Fixes the critical gap where Word
        //     import was the ONLY way to add questions to a workbook.
        //   • Removed 132 lines of dead CSS for upload card, progress bar, and
        //     file-chosen elements that were left after the upload UI removal.
        //   • Generate Model Answers / Save Changes buttons hidden when q_count=0.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'ajax.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100156, 'smartworkbook');
    }

    if ($oldversion < 2026072100157) {
        // v1.0.57: Full stale-reference audit after Word-import removal.
        //   • ajax.php: Removed entire DOCX block extractor (~1000 lines):
        //     all 10 helper functions (smartworkbook_norm, smartworkbook_para_text,
        //     smartworkbook_para_html, smartworkbook_fuzzy_match,
        //     smartworkbook_cell_images, smartworkbook_para_images,
        //     smartworkbook_is_data_table, smartworkbook_table_to_html,
        //     smartworkbook_walk_table, smartworkbook_docx_blocks) and the
        //     case 'convert' action block (~300 lines) — all exclusively used by
        //     the removed upload/convert flow.
        //   • teacher.php: Removed 4 dead getElementById('sw-add-row-btn')
        //     rebind blocks (RTE toolbar, image zone, video zone, table builder);
        //     removed stale upload card HTML comment; fixed floating panel title
        //     'Word Import Block' → 'HTML Display Block'; fixed delete-confirm
        //     message 'Remove this Word import block?' →
        //     'Remove this HTML display block?'.
        //   • lang/en/smartworkbook.php: Updated modulename_help, manage
        //     capability string, status_setup string. Removed 7 dead strings:
        //     uploadworkbook, uploadworkbook_help, selectfile, convertworkbook,
        //     converting, conversioncost, questionsdetected.
        //   • pluginConfig.ts: Updated description and creditCost to remove
        //     Word/PDF and 15-credits-per-import references.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'ajax.php', 'lang/en/smartworkbook.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100157, 'smartworkbook');
    }

    if ($oldversion < 2026072100158) {
        // v1.0.58: dochtml block rendering bug-fix pass.
        //   CSS fixes (styles.css):
        //   • .sw-doc-html-block: added bottom margin (14px 0 0 → 14px 0),
        //     overflow-x:auto (wide DOCX tables no longer overflow student view),
        //     p margin reset (browser-default 16px gaps removed).
        //   • .sw-dv-dochtml-body: added overflow-x:auto, p margin reset,
        //     table-layout:fixed, word-wrap/overflow-wrap:break-word on cells
        //     (Document View table rendering now matches student view).
        //   • .sw-dochtml-preview table/td/th/p: new CSS block — Question List
        //     preview no longer renders DOCX tables with browser-default styles.
        //   • .sw-dv-dochtml-actions: changed justify-content from space-between
        //     to flex-end (was paired with a label element that was never rendered).
        //   • Removed dead .sw-dv-dochtml-label CSS rule.
        //   JS fixes (teacher.php):
        //   • renumberItems() (2 copies): dochtml items now keep SEC badge after
        //     any drag-reorder or row-delete (was incorrectly relabelled HDG).
        //   • Floating panel delete confirm: dochtml now shows the correct
        //     "Remove this HTML display block?" message (not "Delete permanently").
        //   • .sw-dochtml-preview: removed opacity:0.9 (was washing out DOCX
        //     colours); added overflow-x:auto inline.
        //   • Floating panel notice updated to mention Close/Delete options.
        //   Stale comment fix (view.php):
        //   • Removed reference to deleted smartworkbook_docx_blocks() function.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'view.php', 'styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100158, 'smartworkbook');
    }

    if ($oldversion < 2026072100159) {
        // v1.0.59: Restored Word document upload & convert feature.
        // The 'Upload & Convert Workbook' card (DOCX/PDF → AI question extraction,
        // 15 credits) was incorrectly removed in v1.0.57. This version restores:
        //   - teacher.php: upload card HTML + JS (FileReader → base64 → AJAX)
        //   - ajax.php: case 'convert' handler + all 10 DOCX helper functions
        //     (smartworkbook_norm, para_text, para_html, fuzzy_match,
        //      cell_images, para_images, is_data_table, table_to_html,
        //      walk_table, docx_blocks)
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100159, 'smartworkbook');
    }

    if ($oldversion < 2026072100160) {
        // v1.0.60: Fix critical DOCX conversion bug — smartworkbook_is_data_table()
        // had two incorrect border-based rules (w:tblBorders, w:tcBorders) added by
        // a previous session. Every standard Australian RTO workbook table has Word
        // borders set, so ALL TEACHER/STUDENT layout tables were falsely classified
        // as data tables and rendered as a single monolithic dochtml block instead
        // of being walked cell-by-cell to extract questions. Restored the correct
        // 4-rule algorithm from v1.0.56 (51a31d54a): colspan, rowspan, 3+ cols,
        // nested-table check only — no border checks.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100160, 'smartworkbook');
    }

    if ($oldversion < 2026072100161) {
        // v1.0.61: Two-part fix for smartworkbook_is_data_table() applied from
        // analysis of real RTO workbooks (Food Tech Year 9/10 sample set):
        //
        // Part A — colour-aware Rule 1:
        //   Coloured cells with w:gridSpan > 1 are SECTION HEADERS spanning the
        //   full table width (e.g. "Stage 1: Production", "RECIPE"). Previous
        //   code treated ANY gridSpan > 1 as a rubric merge → the whole stage
        //   table became one dochtml blob. Now only WHITE/UNCOLOURED spanning
        //   cells count as rubric merges.
        //
        // Part B — Rule 5 default changed to false (walk):
        //   1–2 column tables without merges or nested sub-tables are walked
        //   rather than rendered as HTML. RTO workbooks use these structures for
        //   recipe cards, Q&A pairs, student response areas, and stage grids.
        //   Treating them as data tables swallowed entire sections.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100161, 'smartworkbook');
    }

    if ($oldversion < 2026072100162) {
        // v1.0.62: Removed gridSpan (colspan) rule from smartworkbook_is_data_table().
        //
        // Root cause from XML analysis of real RTO workbook DOCX files:
        // Australian workbooks routinely use w:gridSpan on WHITE/UNCOLOURED cells for
        // full-width text: description rows ("A thick, warming soup..."), instruction
        // rows ("Task: Map the supply chain..."), student-name fields. These are LAYOUT
        // cells, not rubric merges. The colour-aware Rule 1 added in v1.0.61 only
        // protected coloured spanning cells — white spanning cells still fired the rule,
        // causing Table 2 of the recipe DOCX (white gs=2 description row) to be
        // misclassified as a data table and rendered as dochtml.
        //
        // is_data_table now uses exactly 3 rules:
        //   Rule 1: w:vMerge (rowspan) → data table
        //   Rule 2: ≥ 3 physical w:tc elements in any row → data table
        //   Rule 3: nested w:tbl in any cell → layout wrapper (walk)
        //   Default: walk
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100162, 'smartworkbook');
    }

    if ($oldversion < 2026072100163) {
        // v1.0.63: Added Rule 0 (content-card detection) to smartworkbook_is_data_table().
        //
        // Problem (v1.0.62): Walk-everything approach shredded recipe cards / intro cards
        // into 41+ separate para blocks — each ingredient, each method sentence, the recipe
        // title — all became individual items instead of one formatted display block.
        //
        // Root cause (from XML of real RTO workbooks):
        //   CONTENT CARDS (recipe, intro, topic title):
        //     First row → exactly 1 <w:tc>, coloured fill (e.g. DAECD0 green).
        //     Full-width coloured header card. Must render as HTML to preserve layout.
        //
        //   Q&A LAYOUT WRAPPERS (Stage tables, TEACHER/STUDENT tables):
        //     First row → 2 <w:tc> elements: coloured cell | white cell, side by side.
        //     Must be walked to emit section badges + question para blocks.
        //
        // Rule 0: if first row has exactly 1 cell AND it is coloured → content card → HTML.
        // Stage tables still have 2 cells in first row → not caught → walk → questions ✓.
        //
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100163, 'smartworkbook');
    }

    if ($oldversion < 2026072100164) {
        // FIX-GEN-MODEL-ANSWERS: Fixed "Generate Model Answers" failing every time.
        // Workbooks built via DOCX parsing store all blocks as dochtml/heading types,
        // which are filtered out of q_list — leaving q_list empty. Server returned
        // {success:true,answers:[]} and PHP's empty([]) check misfired as failure.
        // Fix: pre-flight check returns clear "No answerable questions" message;
        // response check now only fails on success=false (not on empty answers array).
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100164, 'smartworkbook');
    }

    if ($oldversion < 2026072100165) {
        // FIX-TABLE-MERGE: Fixed DOCX conversion producing raw HTML dump of Word doc
        // instead of interactive questions. Root cause: html-type DOCX blocks (tables)
        // were unconditionally stored as dochtml — API questions fell to safety-net tail.
        // Fix: strip+normalise table HTML, substring-match against API question keys;
        // matching tables become interactive question records; non-matching stay dochtml.
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100165, 'smartworkbook');
    }

    if ($oldversion < 2026072100166) {
        // FIX-TABLE-ALL-EDITABLE (v1.0.66): All Word tables now become editable blocks.
        // Previously unmatched tables fell back to raw dochtml. New logic: tables with
        // < 80 chars or <= 2 cells become a heading block; larger multi-cell tables
        // become a structured_table (rows parsed from <tr>/<td>, blank cells editable).
        // This ensures every table in the DOCX is interactive after conversion.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100166, 'smartworkbook');
    }

    if ($oldversion < 2026072100167) {
        // FIX-UPLOAD-CSS (v1.0.67): Added missing CSS for upload/drop-zone section.
        // sw-upload-card, sw-drop-zone, sw-file-input, sw-file-chosen-wrap classes
        // were not defined in styles.css — native browser file input was showing raw.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['styles.css', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100167, 'smartworkbook');
    }

    if ($oldversion < 2026072100168) {
        // FIX-CONVERT-BTN (v1.0.68): Convert button was inside sw-file-chosen-wrap
        // (display:none) but JS only showed the inner button — parent container was
        // never un-hidden, so button was invisible even after selecting a file.
        // Also added drag-and-drop support for the drop zone.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['teacher.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100168, 'smartworkbook');
    }

    if ($oldversion < 2026072100169) {
        // FIX-DOCHTML-TABLE (v1.0.69): Unmatched Word tables were being stored as
        // qtype='structured_table' with JSON in questiontext — triple bug: wrong qtype
        // (view.php checks 'table'), wrong field (view.php reads model_answer), wrong
        // JSON format (view.php checks sw_table key). Result: raw JSON blob shown to
        // students. Fix: unmatched multi-cell tables now stored as qtype='dochtml' with
        // the original HTML in questiontext — faithfully preserves Word table layout.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100169, 'smartworkbook');
    }

    if ($oldversion < 2026072100170) {
        // FIX-SW-TABLE (v1.0.70): Replaced dochtml fallback with correct qtype='table'
        // reconstruction. Blank/underscore cells detected as editable (e:true). JSON
        // stored in model_answer (not questiontext) with sw_table:true key — matching
        // what view.php actually checks. Adds test engine at /smartworkbook-preview.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['ajax.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072100170, 'smartworkbook');
    }

    if ($oldversion < 2026072300232) {
        // FIX-API-DOMAIN: Updated all API endpoint URLs from lms-labs.com to lms-labs.com.
        // lms-labs.com has no DNS resolution from Moodle server side; lms-labs.com is the
        // correct working domain. All ajax.php, api_client, unlock_verifier, lib.php calls updated.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072300232, 'smartworkbook');
    }

    if ($oldversion < 2026072300233) {
        // FIX-API-DOMAIN: Reverted API endpoint to lms-labs.com (correct domain).
        // essaygraderai.app was the original single-plugin domain; lms-labs.com is correct.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_mod_savepoint(true, 2026072300233, 'smartworkbook');
    }

    if ($oldversion < 2026072300234) {
        // Domain update: lms-labs.com → lms-labs.com
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_mod_savepoint(true, 2026072300234, 'smartworkbook');
    }

    return true;
}