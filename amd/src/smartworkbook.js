/**
 * AI Smart Workbook - Student workbook AMD module.
 * Auto-save on input (debounced), submit with confirmation.
 * Supports: text/long textareas, yes/no radios, rating radios, table grids.
 * Re-answer mode: only unlocked questions are auto-saved.
 *
 * @module mod_smartworkbook/smartworkbook
 */
define('mod_smartworkbook/smartworkbook', ['core/ajax', 'core/notification'], function(Ajax, Notification) {

    var CMID = 0;
    var WB_ID = 0;
    var IS_SUBMITTED = false;
    var IS_REANSWER = false;
    var AJAX_URL = M.cfg.wwwroot + '/mod/smartworkbook/ajax.php';
    var debounceTimers = {};

    /**
     * Show a save indicator next to the answer field.
     */
    function showIndicator(qid, state, text) {
        var el = document.getElementById('sw-saved-' + qid);
        if (!el) return;
        el.className = 'sw-save-indicator ' + (state || '');
        el.textContent = text || '';
    }

    /**
     * Save a single response via fetch POST (shows per-field indicator).
     */
    function saveResponse(qid, answer) {
        showIndicator(qid, 'saving', 'Saving...');
        var params = new URLSearchParams({
            action: 'save_response',
            cmid: CMID,
            questionid: qid,
            answer: answer,
            sesskey: M.cfg.sesskey
        });
        fetch(AJAX_URL, {method: 'POST', body: params})
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showIndicator(qid, '', 'Saved');
                    setTimeout(function() { showIndicator(qid, '', ''); }, 2000);
                } else {
                    showIndicator(qid, 'error', 'Save failed');
                }
            })
            .catch(function() {
                showIndicator(qid, 'error', 'Save failed');
            });
    }

    /**
     * Save a single response silently (no per-field indicator) — returns a Promise.
     * Used by saveAll() so the "Save progress" button can await all saves at once.
     */
    function saveResponseSilent(qid, answer) {
        var params = new URLSearchParams({
            action: 'save_response',
            cmid: CMID,
            questionid: qid,
            answer: answer,
            sesskey: M.cfg.sesskey
        });
        return fetch(AJAX_URL, {method: 'POST', body: params}).then(function(r) { return r.json(); });
    }

    /**
     * Collect the current value of every editable field and save them all at once.
     * Called when the student clicks the "Save progress" button.
     */
    function saveAll(btn) {
        btn.disabled = true;
        var origText = btn.textContent;
        btn.textContent = 'Saving...';

        var promises = [];

        // Text / long-answer textareas
        document.querySelectorAll('.sw-answer-text').forEach(function(ta) {
            if (ta.readOnly) { return; }
            promises.push(saveResponseSilent(ta.dataset.qid, ta.value));
        });

        // Yes/No and Rating radios — only save the checked one per group
        var seen = {};
        document.querySelectorAll('.sw-yesno input[type=radio]:checked, .sw-rating input[type=radio]:checked').forEach(function(radio) {
            if (radio.disabled) { return; }
            var name = radio.getAttribute('name');
            var qid  = name.replace('yesno_', '').replace('rating_', '');
            if (!seen[qid]) {
                seen[qid] = true;
                promises.push(saveResponseSilent(qid, radio.value));
            }
        });

        // Legacy 2-column table grids — serialise into JSON array
        document.querySelectorAll('.sw-table-input-grid').forEach(function(table) {
            var qid    = table.dataset.qid;
            var rowMap = {};
            var hasEditable = false;
            table.querySelectorAll('.sw-table-cell').forEach(function(cell) {
                if (cell.readOnly) { return; }
                hasEditable = true;
                var row = parseInt(cell.dataset.row, 10);
                var col = cell.dataset.col;
                if (!rowMap[row]) { rowMap[row] = {}; }
                rowMap[row][col] = cell.value;
            });
            if (!hasEditable) { return; }
            var keys    = Object.keys(rowMap).map(Number).sort(function(a, b) { return a - b; });
            var rowsArr = [];
            keys.forEach(function(k) { rowsArr.push(rowMap[k]); });
            promises.push(saveResponseSilent(qid, JSON.stringify(rowsArr)));
        });

        // Structured multi-column tables (.sw-stable) — serialise key→value map
        document.querySelectorAll('.sw-stable').forEach(function(table) {
            var qid = table.dataset.qid;
            var cellMap = {};
            var hasEditable = false;
            table.querySelectorAll('.sw-stable-cell').forEach(function(cell) {
                if (cell.readOnly) { return; }
                hasEditable = true;
                cellMap[cell.dataset.key] = cell.value;
            });
            if (!hasEditable) { return; }
            promises.push(saveResponseSilent(qid, JSON.stringify(cellMap)));
        });

        // Group member names (non-blocking, runs in parallel with responses)
        saveMeta();

        Promise.all(promises).then(function() {
            btn.textContent = 'Saved!';
            setTimeout(function() {
                btn.disabled  = false;
                btn.textContent = origText;
            }, 2000);
        }).catch(function() {
            btn.disabled  = false;
            btn.textContent = origText;
        });
    }

    /**
     * Bind the "Save progress" button(s).
     * Uses a class selector so the button can appear at multiple positions
     * (e.g. top and bottom of the workbook) without duplicate IDs.
     */
    function bindSaveAll() {
        document.querySelectorAll('.sw-save-all-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                saveAll(btn);
            });
        });
    }

    /**
     * Bind auto-save to all answer textareas (skips readonly).
     */
    function bindAutoSave() {
        var textareas = document.querySelectorAll('.sw-answer-text');
        textareas.forEach(function(ta) {
            if (ta.readOnly) return;
            var qid = ta.dataset.qid;
            ta.addEventListener('input', function() {
                clearTimeout(debounceTimers[qid]);
                showIndicator(qid, 'saving', '...');
                debounceTimers[qid] = setTimeout(function() {
                    saveResponse(qid, ta.value);
                }, 800);
            });
        });

        // Yes/No and Rating radio buttons (skip disabled)
        var radios = document.querySelectorAll('.sw-yesno input[type=radio], .sw-rating input[type=radio]');
        radios.forEach(function(radio) {
            if (radio.disabled) return;
            radio.addEventListener('change', function() {
                var name = radio.getAttribute('name');
                var qid = name.replace('yesno_', '').replace('rating_', '');
                saveResponse(qid, radio.value);
            });
        });
    }

    /**
     * Bind auto-save to legacy 2-column table grid cells (skips readonly).
     * Serialises all cells for the question into a JSON array.
     */
    function bindTableAutoSave() {
        var tables = document.querySelectorAll('.sw-table-input-grid');
        tables.forEach(function(table) {
            var qid = table.dataset.qid;
            table.querySelectorAll('.sw-table-cell').forEach(function(cell) {
                if (cell.readOnly) return;
                cell.addEventListener('input', function() {
                    clearTimeout(debounceTimers[qid]);
                    showIndicator(qid, 'saving', '...');
                    debounceTimers[qid] = setTimeout(function() {
                        var rowMap = {};
                        table.querySelectorAll('.sw-table-cell').forEach(function(c) {
                            var row = parseInt(c.dataset.row, 10);
                            var col = c.dataset.col;
                            if (!rowMap[row]) { rowMap[row] = {}; }
                            rowMap[row][col] = c.value;
                        });
                        var rowsArr = [];
                        var keys = Object.keys(rowMap).map(Number).sort(function(a,b){return a-b;});
                        keys.forEach(function(k) { rowsArr.push(rowMap[k]); });
                        saveResponse(qid, JSON.stringify(rowsArr));
                    }, 800);
                });
            });
        });
    }

    /**
     * Bind auto-save to structured multi-column table cells (.sw-stable).
     * Serialises editable cells as a key→value map: {"ri,ci": "value"}.
     */
    function bindStructuredTableAutoSave() {
        document.querySelectorAll('.sw-stable').forEach(function(table) {
            var qid = table.dataset.qid;
            table.querySelectorAll('.sw-stable-cell').forEach(function(cell) {
                if (cell.readOnly) { return; }
                cell.addEventListener('input', function() {
                    clearTimeout(debounceTimers[qid]);
                    showIndicator(qid, 'saving', '...');
                    debounceTimers[qid] = setTimeout(function() {
                        var cellMap = {};
                        table.querySelectorAll('.sw-stable-cell').forEach(function(c) {
                            cellMap[c.dataset.key] = c.value;
                        });
                        saveResponse(qid, JSON.stringify(cellMap));
                    }, 800);
                });
            });
        });
    }

    /**
     * Collect all group-member inputs and POST to save_meta.
     */
    function saveMeta() {
        var inputs = document.querySelectorAll('.sw-group-member-input');
        if (!inputs.length) return;
        var meta = {};
        inputs.forEach(function(inp) {
            meta[inp.dataset.key] = inp.value;
        });
        var indicator = document.getElementById('sw-gm-saved');
        if (indicator) { indicator.textContent = '...'; }
        var params = new URLSearchParams({
            action:  'save_meta',
            cmid:    CMID,
            meta:    JSON.stringify(meta),
            sesskey: M.cfg.sesskey
        });
        fetch(AJAX_URL, {method: 'POST', body: params})
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (indicator) {
                    indicator.textContent = data.success ? 'Saved' : 'Save failed';
                    setTimeout(function() { if (indicator) indicator.textContent = ''; }, 2000);
                }
            })
            .catch(function() {
                if (indicator) { indicator.textContent = 'Save failed'; }
            });
    }

    /**
     * Bind auto-save to group member name inputs (debounced, skips readonly).
     */
    function bindGroupMembers() {
        var inputs = document.querySelectorAll('.sw-group-member-input');
        if (!inputs.length) return;
        var gmTimer;
        inputs.forEach(function(inp) {
            if (inp.readOnly) return;
            inp.addEventListener('input', function() {
                clearTimeout(gmTimer);
                gmTimer = setTimeout(saveMeta, 800);
            });
        });
    }

    /**
     * Bind the submit / resubmit button.
     */
    function bindSubmit() {
        var btn = document.getElementById('sw-submit-btn');
        if (!btn) return;
        var confirmMsg = IS_REANSWER
            ? 'Are you sure you want to resubmit? Only the flagged questions will be re-marked.'
            : 'Are you sure you want to submit? You will not be able to change your answers after submitting.';
        btn.addEventListener('click', function() {
            if (!confirm(confirmMsg)) return;
            btn.disabled = true;
            btn.innerHTML = '<span class="sw-spinner"></span> Submitting...';
            var params = new URLSearchParams({
                action: 'submit',
                cmid: CMID,
                sesskey: M.cfg.sesskey
            });
            fetch(AJAX_URL, {method: 'POST', body: params})
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        btn.disabled = false;
                        btn.textContent = IS_REANSWER ? 'Resubmit Workbook' : 'Submit Workbook';
                        Notification.alert('', data.error || 'Submission failed. Please try again.');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = IS_REANSWER ? 'Resubmit Workbook' : 'Submit Workbook';
                });
        });
    }

    return {
        /**
         * @param {int}  cmid        Course-module ID
         * @param {int}  wbid        Workbook instance ID
         * @param {bool} isSubmitted True when fully submitted (read-only mode)
         * @param {bool} isReanswer  True when student must re-answer flagged questions
         */
        init: function(cmid, wbid, isSubmitted, isReanswer) {
            CMID = cmid;
            WB_ID = wbid;
            IS_SUBMITTED = isSubmitted;
            IS_REANSWER  = isReanswer || false;

            if (!IS_SUBMITTED || IS_REANSWER) {
                bindAutoSave();
                bindTableAutoSave();
                bindStructuredTableAutoSave();
                bindGroupMembers();
                bindSaveAll();
                bindSubmit();
            }
        }
    };
});
