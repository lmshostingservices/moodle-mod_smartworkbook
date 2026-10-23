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
 * Upgrade script for mod_smartworkbook.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Bring every table, field and index in db/install.xml into existence on sites
 * whose schema drifted (earlier builds changed install.xml without matching
 * upgrade steps). Idempotent: anything already present is left untouched.
 *
 * @return void
 */
function smartworkbook_upgrade_sync_schema_with_install_xml(): void {
    global $CFG, $DB;

    $dbman = $DB->get_manager();
    $xmldbfile = new xmldb_file($CFG->dirroot . '/mod/smartworkbook/db/install.xml');
    if (!$xmldbfile->fileExists() || !$xmldbfile->loadXMLStructure()) {
        throw new coding_exception('mod_smartworkbook: unable to load db/install.xml during upgrade');
    }
    $structure = $xmldbfile->getStructure();

    foreach ($structure->getTables() as $xmltable) {
        if (!$dbman->table_exists($xmltable)) {
            $dbman->create_table($xmltable);
            continue;
        }
        // Fields: add any missing ones. A fresh xmldb_table is built per field so
        // add_field() can place it without relying on PREVIOUS/NEXT links.
        foreach ($xmltable->getFields() as $xmlfield) {
            $table = new xmldb_table($xmltable->getName());
            if (!$dbman->field_exists($table, $xmlfield)) {
                $field = clone $xmlfield;
                $field->setPrevious(null);
                $field->setNext(null);
                $dbman->add_field($table, $field);
            }
        }
        // Indexes (non-key).
        foreach ($xmltable->getIndexes() as $xmlindex) {
            $table = new xmldb_table($xmltable->getName());
            $index = new xmldb_index($xmlindex->getName(),
                $xmlindex->getUnique() ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE,
                $xmlindex->getFields());
            if (!$dbman->index_exists($table, $index)) {
                if ($xmlindex->getUnique()) {
                    // Do not fail the upgrade if duplicates already exist; report instead.
                    try {
                        $dbman->add_index($table, $index);
                    } catch (ddl_exception | dml_exception $e) {
                        debugging('mod_smartworkbook: could not add unique index ' . $xmlindex->getName() .
                            ' on ' . $xmltable->getName() . ' (duplicate rows?): ' . $e->getMessage(), DEBUG_NORMAL);
                    }
                } else {
                    $dbman->add_index($table, $index);
                }
            }
        }
    }
}

/**
 * Execute mod_smartworkbook upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_smartworkbook_upgrade($oldversion) {
    if ($oldversion < 2026072300) {
        upgrade_mod_savepoint(true, 2026072300, 'smartworkbook');
    }

    if ($oldversion < 2026102300) {
        // v1.0.77: repair schema drift on long-lived installs.
        smartworkbook_upgrade_sync_schema_with_install_xml();
        upgrade_mod_savepoint(true, 2026102300, 'smartworkbook');
    }

    return true;
}
