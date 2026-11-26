# mod_smartworkbook

## Summary
Metadata-only recovery release 1.0.76 of the mod_smartworkbook Moodle plugin.

## Description
This archive preserves the runtime code from release 1.0.75. Only package release metadata and documentation were updated to restore exact-artifact release verification. No new runtime features or behaviour changes are introduced.

## Bring your own AI
With a Bring your own AI licence in AI Grader Central Config (local_aiconfig), the site can use its own AI instead of LMS Labs credits. When the Text service there is set to Moodle AI or the site's own key:

- **Word (.docx) workbooks** are read and converted on the site itself, with the same reading rules, instructions and checks as the LMS Labs service. No credits are used.
- **AI marking** and **model answers** run on the site's own AI. No credits are used.
- **PDF workbooks** still go to LMS Labs (it reads PDFs with its own parser) and use 15 credits. Upload the Word version to stay on your own AI.

Teachers see a "Your own AI" badge on the upload card, and the confirmation messages say no credits are used. Sites without the licence keep working exactly as before.

## Release identity
Component: mod_smartworkbook. Release: 1.1.0. Numeric version: 2026112600. Date: 2026-11-26.

## Installation
- Install through the Moodle plugin installation interface and complete the standard upgrade.

## Licence
GNU GPL v3 or later. See LICENSE in this package.
