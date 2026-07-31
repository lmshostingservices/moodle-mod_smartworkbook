<?php
/**
 * AI Smart Workbook - Capability definitions.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'mod/smartworkbook:addinstance' => [
        'riskbitmask' => RISK_XSS,
        'captype'     => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'  => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    'mod/smartworkbook:view' => [
        'captype'     => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'  => [
            'guest'          => CAP_ALLOW,
            'student'        => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    'mod/smartworkbook:submit' => [
        'riskbitmask' => RISK_SPAM,
        'captype'     => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'  => [
            'student' => CAP_ALLOW,
        ],
    ],
    'mod/smartworkbook:grade' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype'     => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'  => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    'mod/smartworkbook:manage' => [
        'riskbitmask' => RISK_PERSONAL | RISK_XSS,
        'captype'     => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'  => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];
