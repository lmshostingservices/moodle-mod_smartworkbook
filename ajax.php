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
 * AI Smart Workbook - AJAX handler.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php'); // Required for \curl class used in convert, ai_mark, generate_model_answers actions.
require_once(__DIR__ . '/lib.php');

// DOCX block extractor  (v1.0.20)
//
// Parses a base64-encoded .docx file and returns ALL content
// blocks in document order as three types:
//
//   ['type'=>'para', 'text'=>'...', 'html'=>'...']
//       Paragraph — matched against API questions.
//       'text' = plain text for matching/normalisation.
//       'html' = rich HTML (bold/italic/underline/colour) for
//       fidelity when the paragraph becomes a 'heading' qtype.
//       NO global deduplication: repeated questions like
//       "Who is involved?" appear once per stage so each
//       occurrence can be matched to the correct API entry.
//
//   ['type'=>'html', 'text'=>'...', 'html'=>'...']
//       Coloured table-cell block rendered as a section header.
//       DOCX tables whose cells have a non-white fill (coloured
//       stage-header boxes, title rows, etc.) become styled HTML
//       divs. Non-coloured cells are exploded into 'para' items
//       so questions inside those cells are matchable.
//
//   ['type'=>'image', 'src'=>'...']
//       Embedded image (PNG/JPG/GIF/WebP) from table cells OR
//       body-level paragraphs as a data URI.
//
//   ['type'=>'video', 'url'=>'...']
//       YouTube hyperlink or bare URL from table cells OR
//       body-level paragraphs.
//
// The three root bugs fixed in v1.0.19:
//   1. Global $seen dedup silently removed all duplicate question
//      texts — stages 2-6 "Who is involved?" etc. vanished.
//   2. Single-value $api_map[$key]=$q meant only the LAST API
//      question with that text survived — earlier ones lost.
//   3. $used[$key]=true after first match caused safety-net to
//      also skip repeated questions, so they never appeared at all.
//
// Additional improvements in v1.0.20:
//   4. Body-level paragraph rich HTML preserved ('html' key).
//   5. Images in body-level paragraphs (outside tables) extracted.
//   6. YouTube hyperlinks in body-level paragraphs detected.
//   7. Short artifact paragraphs (< 3 chars) silently dropped.
//   8. Fuzzy match: added prefix strategy + Jaccard word-overlap.
//   9. save_settings AJAX action: missing $cm/$context/$workbook init fixed.
// ============================================================

/**
 * Normalise text for matching (lowercase, collapse whitespace).
 */
function smartworkbook_norm(string $s): string {
    return mb_strtolower(preg_replace('/\s+/', ' ', trim($s)));
}

/**
 * Extract plain text from a single w:p element.
 */
function smartworkbook_para_text(DOMElement $p, DOMXPath $xpath): string {
    $text = '';
    foreach ($xpath->query('.//w:t', $p) as $t) {
        $text .= $t->nodeValue;
    }
    return trim($text);
}

/**
 * Extract rich HTML from a single w:p element.
 *
 * Preserves bold, italic, underline and inline font colour per run.
 * Returns a plain-escaped string for paragraphs with no formatting.
 * Safe for direct HTML output — all text is htmlspecialchars-escaped.
 *
 * @param DOMElement $p
 * @param DOMXPath   $xpath  Must already have the 'w' namespace registered.
 * @return string  HTML fragment.
 */
function smartworkbook_para_html(DOMElement $p, DOMXPath $xpath): string {
    $html = '';
    // Walk every run, including those nested inside w:hyperlink wrappers.
    foreach ($xpath->query('.//w:r', $p) as $run) {
        $text = '';
        foreach ($xpath->query('w:t', $run) as $t) {
            $text .= $t->nodeValue;
        }
        if ($text === '') {
            continue;
        }
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);

        // Inline formatting from w:rPr
        $is_bold      = $xpath->query('w:rPr/w:b', $run)->length > 0;
        $is_italic    = $xpath->query('w:rPr/w:i', $run)->length > 0;
        $is_underline = $xpath->query('w:rPr/w:u', $run)->length > 0;

        // Font colour: <w:color w:val="RRGGBB"> — skip auto / near-black
        $colour_nodes = $xpath->query('w:rPr/w:color/@w:val', $run);
        $colour = $colour_nodes->length > 0
            ? strtoupper(trim($colour_nodes->item(0)->nodeValue))
            : '';
        $styles = [];
        if ($colour !== '' && $colour !== 'AUTO' && $colour !== '000000' &&
                preg_match('/^[0-9A-F]{6}$/', $colour)) {
            $styles[] = 'color:#' . $colour;
        }

        if ($is_bold)      { $escaped = '<strong>' . $escaped . '</strong>'; }
        if ($is_italic)    { $escaped = '<em>'     . $escaped . '</em>';     }
        if ($is_underline) { $escaped = '<u>'      . $escaped . '</u>';      }
        if (!empty($styles)) {
            $escaped = '<span style="' . implode(';', $styles) . '">' . $escaped . '</span>';
        }
        $html .= $escaped;
    }
    return $html;
}

/**
 * Fuzzy-match a normalised DOCX key against the API multi-map.
 *
 * Tries partial / suffix / prefix matching when exact match fails:
 *   - Strip trailing punctuation (:?.!) and retry
 *   - DOCX key is a suffix of an API key  (API has stage/label prefix)
 *   - API key is a suffix of the DOCX key (DOCX has stage/label prefix)
 *   - API key matches DOCX key after stripping API trailing punctuation
 *
 * Consumes the matched entry from $api_map (array_shift) and returns it,
 * or returns null if nothing matches.
 *
 * @param string $key      Normalised DOCX paragraph text
 * @param array  &$api_map Multi-map: normalised_text => [API question, ...]
 * @return array|null
 */
function smartworkbook_fuzzy_match(string $key, array &$api_map): ?array {
    if ($key === '') {
        return null;
    }

    // 1. Strip trailing punctuation from DOCX key and retry exact match
    $kstripped = rtrim($key, ':?.! ');
    if ($kstripped !== $key && !empty($api_map[$kstripped])) {
        $q = array_shift($api_map[$kstripped]);
        if (empty($api_map[$kstripped])) {
            unset($api_map[$kstripped]);
        }
        return $q;
    }

    $klen = strlen($key);

    foreach ($api_map as $akey => $qs) {
        if (empty($qs)) {
            continue;
        }
        $alen = strlen($akey);

        // 2. DOCX key is a suffix of API key  ("question 1: who pays the farmer?" → "who pays the farmer?")
        if ($alen > $klen + 2 && substr($akey, $alen - $klen) === $key) {
            $q = array_shift($api_map[$akey]);
            if (empty($api_map[$akey])) {
                unset($api_map[$akey]);
            }
            return $q;
        }

        // 3. API key is a suffix of DOCX key  ("stage 1: who is involved?" → "who is involved?")
        if ($klen > $alen + 2 && substr($key, $klen - $alen) === $akey) {
            $q = array_shift($api_map[$akey]);
            if (empty($api_map[$akey])) {
                unset($api_map[$akey]);
            }
            return $q;
        }

        // 4. API key stripped of trailing punctuation matches DOCX key
        $astripped = rtrim($akey, ':?.! ');
        if ($astripped !== $akey && $astripped === $key) {
            $q = array_shift($api_map[$akey]);
            if (empty($api_map[$akey])) {
                unset($api_map[$akey]);
            }
            return $q;
        }

        // 5. API key is a PREFIX of DOCX key
        //    e.g. DOCX "who is involved in this process?" matches API "who is involved"
        if ($alen > 4 && $klen > $alen + 2 && substr($key, 0, $alen) === $akey) {
            $q = array_shift($api_map[$akey]);
            if (empty($api_map[$akey])) {
                unset($api_map[$akey]);
            }
            return $q;
        }

        // 6. DOCX key is a PREFIX of API key
        //    e.g. DOCX "list the main hazards" matches API "list the main hazards and explain each"
        if ($klen > 4 && $alen > $klen + 2 && substr($akey, 0, $klen) === $key) {
            $q = array_shift($api_map[$akey]);
            if (empty($api_map[$akey])) {
                unset($api_map[$akey]);
            }
            return $q;
        }
    }

    // 7. Jaccard word-overlap >= 0.70 (last resort; requires ≥ 5 words each side).
    //    Catches paraphrased questions where neither string is a sub/superstring of
    //    the other but they share most content words.
    $docx_words = array_values(array_filter(preg_split('/\s+/', $key)));
    if (count($docx_words) >= 5) {
        $best_score = 0.0;
        $best_match = null;
        $best_akey  = '';
        foreach ($api_map as $akey => $qs) {
            if (empty($qs)) { continue; }
            $api_words = array_values(array_filter(preg_split('/\s+/', $akey)));
            if (count($api_words) < 5) { continue; }
            $intersection = count(array_intersect($docx_words, $api_words));
            $union_count  = count(array_unique(array_merge($docx_words, $api_words)));
            if ($union_count === 0) { continue; }
            $score = $intersection / $union_count;
            if ($score >= 0.70 && $score > $best_score) {
                $best_score = $score;
                $best_match = $qs[0];
                $best_akey  = $akey;
            }
        }
        if ($best_match !== null) {
            array_shift($api_map[$best_akey]);
            if (empty($api_map[$best_akey])) {
                unset($api_map[$best_akey]);
            }
            return $best_match;
        }
    }

    return null;
}

/**
 * Extract embedded images from a single table cell.
 * Handles both DrawingML (a:blip) and VML (v:imagedata).
 *
 * @param DOMElement $tc       The <w:tc> element.
 * @param DOMXPath   $xpath    Configured XPath.
 * @param array      $img_map  rId => data-URI string.
 * @return array  List of ['rid'=>string,'src'=>string].
 */
function smartworkbook_cell_images(DOMElement $tc, DOMXPath $xpath, array $img_map): array {
    $cell_images = [];
    foreach ($xpath->query('.//*[local-name()="blip"]', $tc) as $blip) {
        $rid = '';
        foreach ($blip->attributes as $battr) {
            if ($battr->localName === 'embed' || $battr->localName === 'link') {
                $rid = $battr->value; break;
            }
        }
        if ($rid && isset($img_map[$rid]) && !in_array($rid, array_column($cell_images, 'rid'))) {
            $cell_images[] = ['rid' => $rid, 'src' => $img_map[$rid]];
        }
    }
    foreach ($xpath->query('.//*[local-name()="imagedata"]', $tc) as $vml) {
        $rid = '';
        foreach ($vml->attributes as $vattr) {
            if ($vattr->localName === 'id') { $rid = $vattr->value; break; }
        }
        if ($rid && isset($img_map[$rid]) && !in_array($rid, array_column($cell_images, 'rid'))) {
            $cell_images[] = ['rid' => $rid, 'src' => $img_map[$rid]];
        }
    }
    return $cell_images;
}

/**
 * Extract embedded images from a single body-level paragraph element.
 * Handles both DrawingML (a:blip) and VML (v:imagedata).
 * Mirrors smartworkbook_cell_images() but operates on a w:p element
 * instead of a w:tc, enabling image extraction from body paragraphs
 * that live outside tables.
 *
 * @param DOMElement $p       The <w:p> element.
 * @param DOMXPath   $xpath   Configured XPath with namespaces registered.
 * @param array      $img_map rId => data-URI string.
 * @return array  List of ['rid'=>string,'src'=>string].
 */
function smartworkbook_para_images(DOMElement $p, DOMXPath $xpath, array $img_map): array {
    $images = [];
    foreach ($xpath->query('.//*[local-name()="blip"]', $p) as $blip) {
        $rid = '';
        foreach ($blip->attributes as $battr) {
            if ($battr->localName === 'embed' || $battr->localName === 'link') {
                $rid = $battr->value; break;
            }
        }
        if ($rid && isset($img_map[$rid]) && !in_array($rid, array_column($images, 'rid'))) {
            $images[] = ['rid' => $rid, 'src' => $img_map[$rid]];
        }
    }
    foreach ($xpath->query('.//*[local-name()="imagedata"]', $p) as $vml) {
        $rid = '';
        foreach ($vml->attributes as $vattr) {
            if ($vattr->localName === 'id') { $rid = $vattr->value; break; }
        }
        if ($rid && isset($img_map[$rid]) && !in_array($rid, array_column($images, 'rid'))) {
            $images[] = ['rid' => $rid, 'src' => $img_map[$rid]];
        }
    }
    return $images;
}

/**
 * Decide whether a <w:tbl> is a "data table" that should be rendered as a
 * full HTML <table> (preserving layout, merges, padding, widths) rather than
 * walked cell-by-cell as a transparent layout container.
 *
 * A table is flagged as a data table when it meets ANY one of:
 *   • Any cell has w:gridSpan > 1   (horizontal cell merge)
 *   • Any cell has w:vMerge         (vertical  cell merge)
 *   • Any row has 3 or more cells   (multi-column content/comparison table)
 *   • The table or any cell has explicit border declarations
 *
 * Two-column TEACHER/STUDENT wrapper tables (2 cells/row, no merges, no
 * borders) are NOT flagged and fall through to recursive cell-by-cell walk.
 */
function smartworkbook_is_data_table(DOMElement $tbl, DOMXPath $xpath): bool {
    // ─── STRUCTURAL SIGNAL REFERENCE ────────────────────────────────────────
    // Confirmed by XML inspection of real Food Tech Year 9/10 RTO workbooks:
    //
    // CONTENT CARDS  (recipe cards, intro/title cards, topic header cards):
    //   First row → exactly 1 <w:tc> element, coloured fill.
    //   The full-width coloured row spans all columns as a title header.
    //   These must render as HTML to preserve their visual card layout.
    //   e.g. Recipe Table 2: Row 1 = 1 cell, fill=DAECD0 (green).
    //
    // Q&A LAYOUT WRAPPERS  (TEACHER/STUDENT stage tables, question tables):
    //   First row → 2 <w:tc> elements: coloured label cell | white response cell.
    //   These must be WALKED to emit section badges and question para blocks.
    //   e.g. Stage tables: Row 1 = Cell1(DAECD0) | Cell2(white, questions).
    //
    // The first-row cell-count is the single most reliable separator between
    // these two table classes in Australian RTO workbook DOCX files.

    // ── Rule 0: Content-card detection ──────────────────────────────────────
    // First row has exactly 1 <w:tc> AND it is coloured → full-width header
    // card → render as HTML (preserves recipe card / intro card layout).
    $white_fills = ['FFFFFF','F2F2F2','F3F3F3','EEEEEE','EDEDED',
                    'E7E6E6','D9D9D9','BFBFBF','AUTO',''];
    $first_row = null;
    foreach ($xpath->query('w:tr', $tbl) as $r) { $first_row = $r; break; }
    if ($first_row !== null) {
        $first_cells = $xpath->query('w:tc', $first_row);
        if ($first_cells->length === 1) {
            $fn = $xpath->query('w:tcPr/w:shd/@w:fill', $first_cells->item(0));
            $fv = ($fn && $fn->length > 0) ? strtoupper(trim($fn->item(0)->nodeValue)) : '';
            if ($fv !== '' && !in_array($fv, $white_fills, true) &&
                preg_match('/^[0-9A-F]{6}$/', $fv)) {
                return true; // Coloured full-width header → content card → HTML
            }
        }
    }

    // ── Rule 1: rowspan (w:vMerge) → data table ─────────────────────────────
    // Rubrics and comparison matrices use vertical cell merges; layout tables don't.
    if ($xpath->query('w:tr/w:tc/w:tcPr/w:vMerge', $tbl)->length > 0) { return true; }

    // ── Rule 2: 3+ physical <w:tc> elements in any row → data table ─────────
    // Genuine comparison tables (4-col rubrics, scaling worksheets) always have
    // 3+ independent cell elements per row. Layout tables have ≤ 2 even when
    // the logical column count is higher due to gridSpan.
    foreach ($xpath->query('w:tr', $tbl) as $tr) {
        if ($xpath->query('w:tc', $tr)->length >= 3) { return true; }
    }

    // ── Rule 3: nested <w:tbl> in any cell → layout wrapper ─────────────────
    // Outer TEACHER/STUDENT frame tables nest inner tables inside their white cells.
    if ($xpath->query('w:tr/w:tc/w:tbl', $tbl)->length > 0) { return false; }

    // ── Rule 4 (default): walk ───────────────────────────────────────────────
    // 2-col stage grids, Q&A tables, student response tables, anything else
    // that doesn't match a genuine data-table or content-card signal above.
    return false;
}

/**
 * Render a <w:tbl> as an HTML <table> string with full fidelity.
 *
 * Handles:
 *   colspan   — w:gridSpan
 *   rowspan   — w:vMerge (restart / continue) — two-pass algorithm
 *   cell bg   — w:shd w:fill; auto-inverts text colour for dark fills
 *   padding   — w:tcMar top/bottom/left/right (twips ÷ 15 ≈ px)
 *   widths    — w:tblGrid gridCol + w:tcW proportional percentages
 *   alignment — w:jc on first paragraph in cell
 *   content   — para_html per paragraph; embedded images
 *   headers   — first row where ALL cells are coloured → <thead> / <th>
 */
function smartworkbook_table_to_html(
    DOMElement $tbl,
    DOMXPath   $xpath,
    array      $img_map
): string {
    // ── Grid column widths ────────────────────────────────────────────────
    $grid_col_widths = [];
    foreach ($xpath->query('w:tblGrid/w:gridCol/@w:w', $tbl) as $gw) {
        $grid_col_widths[] = (int)$gw->nodeValue;
    }
    $tbl_total_w = array_sum($grid_col_widths);

    // ── Pass 1: collect raw cell data ─────────────────────────────────────
    $raw_rows = [];
    foreach ($xpath->query('w:tr', $tbl) as $tr) {
        $cells = [];
        foreach ($xpath->query('w:tc', $tr) as $tc) {
            // colspan
            $gs_q    = $xpath->query('w:tcPr/w:gridSpan/@w:val', $tc);
            $colspan = ($gs_q->length > 0) ? max(1, (int)$gs_q->item(0)->nodeValue) : 1;

            // vMerge: 'none' | 'start' | 'continue'
            $vm_q   = $xpath->query('w:tcPr/w:vMerge', $tc);
            $vmerge = 'none';
            if ($vm_q->length > 0) {
                $vm_val = '';
                foreach ($vm_q->item(0)->attributes as $a) {
                    if ($a->localName === 'val') { $vm_val = $a->value; break; }
                }
                $vmerge = ($vm_val === 'restart') ? 'start' : 'continue';
            }

            // cell background fill
            $fill = '';
            $shd_q = $xpath->query('w:tcPr/w:shd/@w:fill', $tc);
            if ($shd_q->length > 0) {
                $fv = strtoupper(trim($shd_q->item(0)->nodeValue));
                if (preg_match('/^[0-9A-F]{6}$/', $fv) &&
                    !in_array($fv, ['FFFFFF', 'AUTO', 'F2F2F2', 'F3F3F3', 'EEEEEE', 'EDEDED', '000000'])) {
                    $fill = $fv;
                }
            }

            // cell padding (twips ÷ 15 ≈ px, minimum 2 px)
            $pad = ['top' => 4, 'bottom' => 4, 'left' => 8, 'right' => 8];
            foreach (['top', 'bottom', 'left', 'right'] as $side) {
                $pq = $xpath->query('w:tcPr/w:tcMar/w:' . $side . '/@w:w', $tc);
                if ($pq->length > 0) {
                    $pad[$side] = max(2, (int)round((int)$pq->item(0)->nodeValue / 15));
                }
            }

            // text alignment from first paragraph
            $align = 'left';
            $jc_q  = $xpath->query('w:p/w:pPr/w:jc/@w:val', $tc);
            if ($jc_q->length > 0) {
                $jcv = $jc_q->item(0)->nodeValue;
                if ($jcv === 'center') { $align = 'center'; }
                elseif ($jcv === 'right') { $align = 'right'; }
            }

            // cell content
            $parts = [];
            foreach ($xpath->query('w:p', $tc) as $p) {
                $ph = smartworkbook_para_html($p, $xpath);
                if ($ph !== '') {
                    $parts[] = $ph;
                } else {
                    $pt = smartworkbook_para_text($p, $xpath);
                    if ($pt !== '') {
                        $parts[] = htmlspecialchars($pt, ENT_QUOTES | ENT_HTML5);
                    }
                }
            }
            foreach (smartworkbook_cell_images($tc, $xpath, $img_map) as $cimg) {
                $parts[] = '<img src="' . htmlspecialchars($cimg['src'], ENT_QUOTES | ENT_HTML5)
                         . '" style="max-width:100%;height:auto;" alt="">';
            }

            $cells[] = [
                'colspan'  => $colspan,
                'vmerge'   => $vmerge,
                'fill'     => $fill,
                'pad'      => $pad,
                'align'    => $align,
                'html'     => implode('<br>', array_filter($parts, function ($x) { return $x !== ''; })),
                'grid_col' => 0,
                'rowspan'  => 1,
                'skip'     => false,
            ];
        }
        $raw_rows[] = $cells;
    }

    if (empty($raw_rows)) { return ''; }
    $n_rows = count($raw_rows);

    // ── Pass 2a: compute grid column positions ────────────────────────────
    // In OOXML every row (including rows with vMerge=continue) contains ALL
    // physical <w:tc> elements. grid_col is simply the cumulative sum of
    // colspan values of preceding cells in the same row.
    for ($ri = 0; $ri < $n_rows; $ri++) {
        $cp = 0;
        $cnt = count($raw_rows[$ri]);
        for ($ci = 0; $ci < $cnt; $ci++) {
            $raw_rows[$ri][$ci]['grid_col'] = $cp;
            $cp += $raw_rows[$ri][$ci]['colspan'];
        }
    }

    // ── Pass 2b: compute rowspan for vMerge=start cells ───────────────────
    // For each start cell, scan downward rows at the same grid_col.
    // Mark each found continuation cell as skip=true.
    for ($ri = 0; $ri < $n_rows; $ri++) {
        $cnt = count($raw_rows[$ri]);
        for ($ci = 0; $ci < $cnt; $ci++) {
            if ($raw_rows[$ri][$ci]['vmerge'] !== 'start') { continue; }
            $start_col = $raw_rows[$ri][$ci]['grid_col'];
            $rs = 1;
            for ($rj = $ri + 1; $rj < $n_rows; $rj++) {
                $found = false;
                $cnt_j = count($raw_rows[$rj]);
                for ($cj = 0; $cj < $cnt_j; $cj++) {
                    if ($raw_rows[$rj][$cj]['grid_col'] === $start_col &&
                        $raw_rows[$rj][$cj]['vmerge']   === 'continue') {
                        $raw_rows[$rj][$cj]['skip'] = true;
                        $rs++;
                        $found = true;
                        break;
                    }
                }
                if (!$found) { break; }
            }
            $raw_rows[$ri][$ci]['rowspan'] = $rs;
        }
    }

    // ── Detect header row ─────────────────────────────────────────────────
    $first_row_header = false;
    if (!empty($raw_rows[0])) {
        $all_col = true;
        foreach ($raw_rows[0] as $c) { if (empty($c['fill'])) { $all_col = false; break; } }
        $first_row_header = $all_col;
    }

    // ── Render HTML ───────────────────────────────────────────────────────
    $html  = '<div class="sw-docx-table-wrap">';
    $html .= '<table class="sw-docx-table">';

    for ($ri = 0; $ri < $n_rows; $ri++) {
        if ($ri === 0 && $first_row_header) { $html .= '<thead>'; }
        elseif ($ri === 1 && $first_row_header) { $html .= '<tbody>'; }
        elseif ($ri === 0) { $html .= '<tbody>'; }

        $html .= '<tr>';

        foreach ($raw_rows[$ri] as $cell) {
            if ($cell['skip']) { continue; }

            $tag = ($ri === 0 && $first_row_header) ? 'th' : 'td';
            $cs  = $cell['colspan'] > 1 ? ' colspan="' . $cell['colspan'] . '"' : '';
            $rs_v = $cell['rowspan'];
            $rs  = $rs_v > 1 ? ' rowspan="' . $rs_v . '"' : '';

            // Build inline style
            $p = $cell['pad'];
            $style  = 'padding:' . $p['top'].'px '.$p['right'].'px '.$p['bottom'].'px '.$p['left'].'px;';
            $style .= 'text-align:' . $cell['align'] . ';';
            $style .= 'vertical-align:top;';
            $style .= 'border:1px solid #d1d5db;';

            if (!empty($cell['fill'])) {
                $style .= 'background-color:#' . $cell['fill'] . ';';
                // Compute relative luminance — invert text colour for dark fills
                $r = hexdec(substr($cell['fill'], 0, 2));
                $g = hexdec(substr($cell['fill'], 2, 2));
                $b = hexdec(substr($cell['fill'], 4, 2));
                if ((0.299 * $r + 0.587 * $g + 0.114 * $b) < 128) {
                    $style .= 'color:#ffffff;';
                }
            }

            // Column width as percentage (span cols across gridSpan)
            if ($tbl_total_w > 0 && !empty($grid_col_widths)) {
                $w = 0;
                for ($s = 0; $s < $cell['colspan']; $s++) {
                    $gc = $cell['grid_col'] + $s;
                    if (isset($grid_col_widths[$gc])) { $w += $grid_col_widths[$gc]; }
                }
                if ($w > 0) {
                    $style .= 'width:' . round($w / $tbl_total_w * 100, 1) . '%;';
                }
            }

            $html .= '<' . $tag . $cs . $rs . ' style="' . htmlspecialchars($style, ENT_QUOTES | ENT_HTML5) . '">';
            $html .= $cell['html'];
            $html .= '</' . $tag . '>';
        }

        $html .= '</tr>';
        if ($ri === 0 && $first_row_header)        { $html .= '</thead>'; }
        if ($ri === $n_rows - 1)                   { $html .= '</tbody>'; }
    }

    $html .= '</table></div>';
    return $html;
}

/**
 * Recursively walk a single <w:tbl> element, appending content blocks.
 *
 * ROOT CAUSE FIX: The original parser used a flat w:tbl/w:tr/w:tc/w:p fallback
 * when a cell had no direct <w:p> children. That stripped ALL coloured-cell
 * detection from nested tables and silently discarded content beyond 1 nesting
 * level. This function replaces that flat query with a proper recursive descent:
 *
 *   white outer cell (no direct <w:p>) + nested <w:tbl> children
 *     → recurse into each nested table with full coloured-cell detection
 *
 * This correctly handles:
 *   • Coloured section-header cells at ANY nesting depth
 *   • Multi-level nesting (outer → inner → inner-inner)
 *   • Two-column TEACHER/STUDENT layouts where content lives inside inner tables
 *
 * @param DOMElement $tbl      The <w:tbl> to process.
 * @param DOMXPath   $xpath    Configured XPath with 'w' namespace.
 * @param array      &$blocks  Output array to append blocks into.
 * @param array      $img_map  rId → data-URI.
 * @param array      $rel_map  rId → relationship info.
 * @param int        $depth    Current recursion depth (safety cap 8).
 */
function smartworkbook_walk_table(
    DOMElement $tbl,
    DOMXPath   $xpath,
    array      &$blocks,
    array      $img_map,
    array      $rel_map,
    int        $depth = 0
): void {
    if ($depth > 8) {
        return; // safety cap against infinite recursion in malformed DOCX
    }

    // ── Data table fast-path ──────────────────────────────────────────────
    // If this table has 3+ columns, merged cells, or explicit borders it is
    // a content/data table (comparison table, rubric, task grid, etc.).
    // Render it as a full HTML <table> — preserving colspan, rowspan, cell
    // padding/widths/alignment/colours — and emit as a single 'html' block
    // (becomes a dochtml display row in the workbook).
    // Two-column TEACHER/STUDENT wrapper tables pass straight through here.
    if (smartworkbook_is_data_table($tbl, $xpath)) {
        $table_html = smartworkbook_table_to_html($tbl, $xpath, $img_map);
        if ($table_html !== '') {
            $blocks[] = [
                'type' => 'html',
                'text' => '[table]',
                'html' => $table_html,
            ];
        }
        return;
    }

    // Sum column widths (twips) for percentage calculations.
    $tbl_total_w = 0;
    foreach ($xpath->query('w:tblGrid/w:gridCol/@w:w', $tbl) as $gw) {
        $tbl_total_w += (int) $gw->nodeValue;
    }

    foreach ($xpath->query('w:tr', $tbl) as $tr) {
        foreach ($xpath->query('w:tc', $tr) as $tc) {

            // ── Background fill detection ─────────────────────────────────────
            $fill = '';
            $fill_nodes = $xpath->query('w:tcPr/w:shd/@w:fill', $tc);
            if ($fill_nodes->length > 0) {
                $fill = strtoupper(trim($fill_nodes->item(0)->nodeValue));
            }
            $is_colored = (
                $fill !== '' &&
                $fill !== 'AUTO' &&
                preg_match('/^[0-9A-F]{6}$/', $fill) &&
                !in_array($fill, ['FFFFFF', 'F2F2F2', 'F3F3F3', 'EEEEEE', 'EDEDED'])
            );

            // ── Column width ──────────────────────────────────────────────────
            $tcw_nodes    = $xpath->query('w:tcPr/w:tcW/@w:w', $tc);
            $cell_w_twips = ($tcw_nodes->length > 0) ? (int) $tcw_nodes->item(0)->nodeValue : 0;
            $cell_w_pct   = ($tbl_total_w > 0 && $cell_w_twips > 0)
                ? round($cell_w_twips / $tbl_total_w * 100, 1)
                : 0;

            // ── Direct paragraph extraction ───────────────────────────────────
            $cell_paras      = [];
            $cell_paras_html = [];
            foreach ($xpath->query('w:p', $tc) as $p) {
                $text = smartworkbook_para_text($p, $xpath);
                if ($text !== '') {
                    $cell_paras[]      = $text;
                    $cell_paras_html[] = smartworkbook_para_html($p, $xpath)
                        ?: htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);
                }
            }

            // ── No direct paragraphs → check for nested tables ────────────────
            // KEY FIX: if this is a WHITE cell with nested w:tbl children and no
            // direct w:p, we RECURSE into each nested table (preserving coloured-cell
            // detection at every depth) instead of doing the old flat
            // w:tbl/w:tr/w:tc/w:p query which silently discarded coloured headers.
            $nested_tbls = $xpath->query('w:tbl', $tc);
            if (empty($cell_paras) && $nested_tbls->length > 0) {
                if (!$is_colored) {
                    // White cell: recurse into every direct nested table.
                    foreach ($nested_tbls as $nested_tbl) {
                        smartworkbook_walk_table(
                            $nested_tbl, $xpath, $blocks, $img_map, $rel_map, $depth + 1
                        );
                    }
                    // Emit any images embedded in this outer cell.
                    foreach (smartworkbook_cell_images($tc, $xpath, $img_map) as $cimg) {
                        $blocks[] = ['type' => 'image', 'src' => $cimg['src']];
                    }
                    continue; // cell fully handled via recursion
                } else {
                    // Coloured cell with no direct paragraphs: recover badge title
                    // text from 1 level of nesting (rare edge case).
                    $seen = []; $dp = []; $dh = [];
                    foreach ($xpath->query('w:tbl/w:tr/w:tc/w:p', $tc) as $p) {
                        $text = smartworkbook_para_text($p, $xpath);
                        if ($text !== '' && !in_array($text, $seen, true)) {
                            $seen[] = $text;
                            $dp[]   = $text;
                            $dh[]   = smartworkbook_para_html($p, $xpath)
                                ?: htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);
                        }
                    }
                    $cell_paras = $dp; $cell_paras_html = $dh;
                }
            }

            // ── Image extraction ──────────────────────────────────────────────
            $cell_images = smartworkbook_cell_images($tc, $xpath, $img_map);

            if (empty($cell_paras) && empty($cell_images)) {
                continue;
            }

            if ($is_colored) {
                // Coloured cell → section-header HTML block with icon badge.
                $title_html = $cell_paras_html[0]
                    ?? htmlspecialchars($cell_paras[0], ENT_QUOTES | ENT_HTML5);
                $sub_html = '';
                if (count($cell_paras_html) > 1) {
                    $sub_html = '<span class="sw-section-sub"> &mdash; '
                        . implode(' &mdash; ', array_slice($cell_paras_html, 1))
                        . '</span>';
                }
                $sec_type = smartworkbook_section_type($cell_paras[0]);
                $sec_svg  = smartworkbook_section_svg($sec_type);
                $badge_style = 'background-color:#' . $fill . ';';
                $outer_style = ($cell_w_pct > 0) ? ' style="width:' . $cell_w_pct . '%;"' : '';

                $blocks[] = [
                    'type' => 'html',
                    'text' => implode(' — ', $cell_paras),
                    'html' => '<div class="sw-section-block" data-swtype="' . htmlspecialchars($sec_type, ENT_QUOTES | ENT_HTML5) . '"' . $outer_style . '>'
                            . '<div class="sw-section-badge" data-swtype="' . htmlspecialchars($sec_type, ENT_QUOTES | ENT_HTML5) . '" style="' . $badge_style . '">'
                            . '<span class="sw-section-icon">' . $sec_svg . '</span>'
                            . '<span class="sw-section-title-text"><strong>' . $title_html . '</strong>' . $sub_html . '</span>'
                            . '</div>'
                            . '</div>',
                ];

            } else {
                // White/uncoloured cell: first collect YouTube hyperlinks.
                $hl_youtube = [];
                foreach ($xpath->query('.//w:hyperlink', $tc) as $hl_node) {
                    $hrid = '';
                    foreach ($hl_node->attributes as $hattr) {
                        if ($hattr->localName === 'id') { $hrid = $hattr->value; break; }
                    }
                    if ($hrid && isset($rel_map[$hrid])) {
                        $hurl = $rel_map[$hrid]['target'];
                        if (preg_match('/youtube\.com|youtu\.be/', $hurl) && !in_array($hurl, $hl_youtube)) {
                            $hl_youtube[] = $hurl;
                        }
                    }
                }
                foreach ($hl_youtube as $yurl) {
                    $blocks[] = ['type' => 'video', 'url' => $yurl];
                }
                foreach ($cell_paras as $text) {
                    if (empty($hl_youtube) && preg_match(
                        '/(https?:\/\/(?:www\.)?(?:youtube\.com\/(?:watch\?(?:[^&\s]*&)*v=|embed\/)|youtu\.be\/)[A-Za-z0-9_\-]{11}[^\s]*)/',
                        $text, $ym)
                    ) {
                        $blocks[] = ['type' => 'video', 'url' => trim($ym[1])];
                    } else {
                        $blocks[] = ['type' => 'para', 'text' => $text];
                    }
                }
                foreach ($cell_images as $cimg) {
                    $blocks[] = ['type' => 'image', 'src' => $cimg['src']];
                }
            }
        }
    }
}

/**
 * Extract document content blocks from a base64-encoded DOCX file.
 *
 * Walks w:body direct children in document order:
 *   w:p  → plain text 'para' item (no global deduplication)
 *   w:tbl → per-row, per-cell:
 *       coloured cell (fill != FFFFFF/AUTO) → 'html' section-header block
 *       white/uncoloured cell               → individual 'para' items
 *
 * Returns null on any failure so callers can fall back gracefully.
 *
 * @param string $base64  Raw base64 string (no data-URI prefix).
 * @return array|null
 */
function smartworkbook_docx_blocks(string $base64): ?array {
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $bytes = base64_decode($base64, true);
    if ($bytes === false || strlen($bytes) < 4) {
        return null;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'sw_docx_') . '.docx';
    if (file_put_contents($tmp, $bytes) === false) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return null;
    }
    $xml = $zip->getFromName('word/document.xml');

    // Parse relationships file to map rId → media path / URL.
    $rel_map = [];
    $rels_xml = $zip->getFromName('word/_rels/document.xml.rels');
    if ($rels_xml) {
        $rels_dom = new DOMDocument();
        if (@$rels_dom->loadXML($rels_xml)) {
            foreach ($rels_dom->getElementsByTagName('Relationship') as $rel_node) {
                $rid    = $rel_node->getAttribute('Id');
                $rtype  = $rel_node->getAttribute('Type');
                $rtgt   = $rel_node->getAttribute('Target');
                $rel_map[$rid] = ['type' => $rtype, 'target' => $rtgt];
            }
        }
    }

    // Pre-extract web-compatible images (PNG/JPG/GIF/WebP).
    // Skip EMF/WMF (Windows metafiles — not renderable in browsers).
    $img_map = []; // rId => data URI string
    foreach ($rel_map as $rid => $info) {
        if (strpos($info['type'], '/image') === false) { continue; }
        $ext = strtolower(pathinfo($info['target'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) { continue; }
        $ipath = 'word/' . ltrim(str_replace('../', '', $info['target']), '/');
        $ibytes = $zip->getFromName($ipath);
        if ($ibytes === false || strlen($ibytes) > 4 * 1024 * 1024) { continue; } // skip >4 MB
        $imime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/' . $ext;
        $img_map[$rid] = 'data:' . $imime . ';base64,' . base64_encode($ibytes);
    }

    $zip->close();
    @unlink($tmp);

    if (!$xml) {
        return null;
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return null;
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    // Locate w:body
    $body_list = $xpath->query('/w:document/w:body');
    if (!$body_list || $body_list->length === 0) {
        $body_list = $xpath->query('//w:body');
    }
    if (!$body_list || $body_list->length === 0) {
        return null;
    }
    $body = $body_list->item(0);

    $blocks = [];

    foreach ($body->childNodes as $node) {
        if (!($node instanceof DOMElement)) {
            continue;
        }
        $local = $node->localName;

        if ($local === 'p') {
            // Body-level paragraph — images and YouTube first, then matchable text.

            // 1. Extract embedded images (DrawingML + VML) directly in this paragraph.
            foreach (smartworkbook_para_images($node, $xpath, $img_map) as $pimg) {
                $blocks[] = ['type' => 'image', 'src' => $pimg['src']];
            }

            // 2. Detect YouTube hyperlinks inside w:hyperlink elements.
            $yt_found = false;
            foreach ($xpath->query('.//w:hyperlink', $node) as $hl_node) {
                $hrid = '';
                foreach ($hl_node->attributes as $hattr) {
                    if ($hattr->localName === 'id') { $hrid = $hattr->value; break; }
                }
                if ($hrid && isset($rel_map[$hrid])) {
                    $hurl = $rel_map[$hrid]['target'];
                    if (preg_match('/youtube\.com|youtu\.be/', $hurl)) {
                        $blocks[] = ['type' => 'video', 'url' => $hurl];
                        $yt_found = true;
                    }
                }
            }

            if (!$yt_found) {
                $text = smartworkbook_para_text($node, $xpath);

                // 3. Skip very short artifacts (single bullets, lone numbers,
                //    numbering glyphs like "1." or "•") that would otherwise
                //    become orphan 'heading' rows after the fuzzy match fails.
                if (strlen($text) < 3) {
                    // no-op — discard whitespace-only or punctuation-only paragraphs
                } elseif (preg_match(
                    '/(https?:\/\/(?:www\.)?(?:youtube\.com\/(?:watch\?(?:[^&\s]*&)*v=|embed\/)|youtu\.be\/)[A-Za-z0-9_\-]{11}[^\s]*)/',
                    $text, $ym
                )) {
                    // 4. Bare YouTube URL written as plain text (no hyperlink element).
                    $blocks[] = ['type' => 'video', 'url' => trim($ym[1])];
                } elseif ($text !== '') {
                    // 5. Normal matchable paragraph.
                    //    Capture rich HTML alongside plain text so that unmatched
                    //    paragraphs that become 'heading' qtypes retain their
                    //    Word formatting (bold labels, italic notes, coloured text).
                    $html = smartworkbook_para_html($node, $xpath);
                    $blocks[] = ['type' => 'para', 'text' => $text, 'html' => $html ?: ''];
                }
            }

        } elseif ($local === 'tbl') {
            // Delegate to the recursive table walker.
            // This replaces the old flat one-level-deep approach and correctly
            // handles coloured section-header cells at ANY nesting depth, as well
            // as multi-level nesting (e.g. two-column TEACHER/STUDENT outer table
            // whose white cells each contain inner tables with their own coloured headers).
            smartworkbook_walk_table($node, $xpath, $blocks, $img_map, $rel_map, 0);
        }
    }

    return $blocks ?: null;
}

$PAGE->set_context(context_system::instance());

// Avoid require_login() — on Moodle 4.4+/5.x it performs additional course-context
// checks that throw 'coursehidden' / "Course or activity not accessible" in AJAX context.
// Direct isloggedin() check is sufficient; individual actions enforce context_module capability.
if (!isloggedin() || isguestuser()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated. Please reload the page.']);
    exit;
}

require_sesskey();

$action = required_param('action', PARAM_ALPHANUMEXT);

header('Content-Type: application/json');

switch ($action) {

    // ---- Student: auto-save a single answer --------------------------------
    case 'save_response':
        $cmid       = required_param('cmid', PARAM_INT);
        $questionid = required_param('questionid', PARAM_INT);
        $answer     = optional_param('answer', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — free-form rich text/HTML, escaped or format_text()d on output

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:submit', $context);

        $workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        if ($workbook->status !== 'published') {
            echo json_encode(['success' => false, 'error' => 'not_published']);
            break;
        }

        // Reject saves on already-submitted workbooks
        $submission = $DB->get_record('smartworkbook_submission', [
            'workbookid' => $workbook->id, 'userid' => $USER->id]);
        if ($submission && in_array($submission->status, ['submitted', 'ai_marked', 'grades_released'])) {
            echo json_encode(['success' => false, 'error' => 'already_submitted']);
            break;
        }

        $q = $DB->get_record('smartworkbook_question', ['id' => $questionid, 'workbookid' => $workbook->id], '*', MUST_EXIST);

        $existing = $DB->get_record('smartworkbook_response', [
            'workbookid' => $workbook->id, 'questionid' => $questionid, 'userid' => $USER->id]);

        $now = time();
        if ($existing) {
            $existing->responsetext  = $answer;
            $existing->timemodified  = $now;
            $DB->update_record('smartworkbook_response', $existing);
        } else {
            $rec = (object)[
                'workbookid'   => $workbook->id,
                'questionid'   => $questionid,
                'userid'       => $USER->id,
                'responsetext' => $answer,
                'timecreated'  => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('smartworkbook_response', $rec);
        }

        echo json_encode(['success' => true]);
        break;

    // ---- Student: submit workbook ------------------------------------------
    case 'submit':
        $cmid = required_param('cmid', PARAM_INT);

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:submit', $context);

        $workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        if ($workbook->status !== 'published') {
            echo json_encode(['success' => false, 'error' => get_string('error_notpublished', 'smartworkbook')]);
            break;
        }

        $existing = $DB->get_record('smartworkbook_submission', [
            'workbookid' => $workbook->id, 'userid' => $USER->id]);
        if ($existing && in_array($existing->status, ['submitted', 'ai_marked', 'grades_released'])) {
            echo json_encode(['success' => false, 'error' => get_string('error_alreadysubmitted', 'smartworkbook')]);
            break;
        }

        $now = time();
        if ($existing) {
            // When resubmitting from re-answer mode, clear marks for the flagged questions
            // so they are re-sent to AI marking on the next ai_mark call.
            if ($existing->status === 'reanswer') {
                $DB->delete_records_select('smartworkbook_mark',
                    'submissionid = ? AND status = ?', [$existing->id, 'reanswer']);
            }
            $existing->status        = 'submitted';
            $existing->timesubmitted = $now;
            $existing->timemodified  = $now;
            $DB->update_record('smartworkbook_submission', $existing);
        } else {
            $rec = (object)[
                'workbookid'    => $workbook->id,
                'userid'        => $USER->id,
                'status'        => 'submitted',
                'timecreated'   => $now,
                'timemodified'  => $now,
                'timesubmitted' => $now,
            ];
            $DB->insert_record('smartworkbook_submission', $rec);
        }

        echo json_encode(['success' => true]);
        break;

    // ---- Teacher: get all submissions --------------------------------------
    case 'get_submissions':
        $cmid = required_param('cmid', PARAM_INT);

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:grade', $context);

        $workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);

        $subs = $DB->get_records_sql(
            'SELECT s.*, u.firstname, u.lastname, u.email
               FROM {smartworkbook_submission} s
               JOIN {user} u ON u.id = s.userid
              WHERE s.workbookid = ?
           ORDER BY u.lastname ASC, u.firstname ASC',
            [$workbook->id]
        );

        $result = [];
        foreach ($subs as $s) {
            $result[] = [
                'id'        => (int)$s->id,
                'userid'    => (int)$s->userid,
                'name'      => $s->firstname . ' ' . $s->lastname,
                'email'     => $s->email,
                'status'    => $s->status,
                'grade'     => $s->grade,
                'totalmarks' => $s->total_marks,
                'maxmarks'   => $s->max_marks,
                'timesubmitted' => $s->timesubmitted,
                'timegraded'    => $s->timegraded,
            ];
        }

        echo json_encode(['success' => true, 'submissions' => $result]);
        break;

    // ---- Teacher: get questions for a workbook -----------------------------
    case 'get_questions':
        $cmid = required_param('cmid', PARAM_INT);

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:grade', $context);

        $workbook  = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $questions = $DB->get_records('smartworkbook_question', ['workbookid' => $workbook->id], 'sortorder ASC');

        $result = [];
        foreach ($questions as $q) {
            $result[] = [
                'id'           => (int)$q->id,
                'sortorder'    => (int)$q->sortorder,
                'qtype'        => $q->qtype,
                'label'        => $q->label,
                'questiontext' => $q->questiontext,
                'marks'        => (float)$q->marks,
                'model_answer' => $q->model_answer,
                'rubric_notes' => $q->rubric_notes,
            ];
        }

        echo json_encode(['success' => true, 'questions' => $result, 'status' => $workbook->status]);
        break;

    case 'add_question':
        $cmid = required_param('cmid', PARAM_INT);
        $cm   = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:manage', $context);

        $workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $maxorder = $DB->get_field('smartworkbook_question', 'MAX(sortorder)', ['workbookid' => $workbook->id]);
        $nextorder = ($maxorder === false || $maxorder === null) ? 0 : (int)$maxorder + 1;

        $newid = $DB->insert_record('smartworkbook_question', (object)[
            'workbookid'   => $workbook->id,
            'sortorder'    => $nextorder,
            'qtype'        => 'text',
            'label'        => '',
            'questiontext' => '',
            'marks'        => 1.0,
            'model_answer' => '',
            'rubric_notes' => '',
            'timecreated'  => time(),
        ]);

        echo json_encode(['success' => true, 'id' => (int)$newid]);
        break;

    // ---- Teacher: upload file for AI conversion ----------------------------
    case 'convert':
        $cmid        = required_param('cmid', PARAM_INT);
        $filename    = required_param('filename', PARAM_FILE);
        $filecontent = required_param('filecontent', PARAM_RAW); // pipeline-ignore: PARAM_RAW — base64-encoded binary payload, decoded and validated before use

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:manage', $context);

        \core\session\manager::write_close();

        $workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);

        list($site_id, $api_key) = smartworkbook_get_api_credentials();
        if (empty($site_id) || empty($api_key)) {
            echo json_encode(['success' => false, 'error' => get_string('error_nocredentials', 'smartworkbook')]);
            break;
        }

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 120]);

        $payload = json_encode([
            'site_id'                  => $site_id,
            'api_key'                  => $api_key,
            'filename'                 => $filename,
            'filecontent'              => $filecontent,
            'extract_instructions'     => true,
            'include_metadata_fields'  => true,
        ]);

        $response = $curl->post('https://lms-labs.com/api/smartworkbook/convert', $payload, [
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);

        $data = json_decode($response, true);

        if (empty($data['success']) || empty($data['questions'])) {
            $err = $data['error'] ?? get_string('error_conversion', 'smartworkbook');
            echo json_encode(['success' => false, 'error' => $err]);
            break;
        }

        $docx_blocks = ($filename && substr(strtolower($filename), -5) === '.docx')
            ? smartworkbook_docx_blocks($filecontent)
            : null;

        $DB->delete_records('smartworkbook_question', ['workbookid' => $workbook->id]);
        $i = 0;

        if ($docx_blocks !== null) {
            $api_map = [];
            foreach ($data['questions'] as $q) {
                $key = smartworkbook_norm($q['questiontext'] ?? '');
                if ($key !== '') {
                    $api_map[$key][] = $q;
                }
                $lkey = smartworkbook_norm($q['label'] ?? '');
                if ($lkey !== '' && $lkey !== $key) {
                    $api_map[$lkey][] = $q;
                }
            }

            foreach ($docx_blocks as $block) {

                if ($block['type'] === 'html') {
                    // Tables were previously always stored as raw dochtml, which meant
                    // the API questions extracted from those tables ended up buried at
                    // the end via the safety-net loop — leaving the workbook looking
                    // like a raw HTML dump of the Word doc.
                    //
                    // Fix: strip the table HTML to plain text, normalise it, then check
                    // whether any API question text is a substring of that plain text.
                    // If matches are found, insert the interactive question records in
                    // their original document order instead of a dochtml block.
                    // Only fall back to dochtml for pure display tables that contain
                    // no answerable questions.
                    $block_plain = smartworkbook_norm(
                        html_entity_decode(strip_tags($block['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    );
                    $found_keys = [];
                    foreach ($api_map as $akey => $qs) {
                        // Require at least 8 normalised chars to avoid false positives
                        // on very short strings like "yes" or "no".
                        if (mb_strlen($akey) >= 8 && mb_strpos($block_plain, $akey) !== false) {
                            $found_keys[$akey] = true;
                        }
                    }

                    if (!empty($found_keys)) {
                        // Insert matched questions in their original API document order.
                        foreach ($data['questions'] as $_bq) {
                            $qkey = smartworkbook_norm($_bq['questiontext'] ?? '');
                            $lkey = smartworkbook_norm($_bq['label'] ?? '');
                            $mk   = null;
                            if (!empty($found_keys[$qkey]) && !empty($api_map[$qkey])) {
                                $mk = $qkey;
                            } elseif (!empty($lkey) && !empty($found_keys[$lkey]) && !empty($api_map[$lkey])) {
                                $mk = $lkey;
                            }
                            if ($mk !== null) {
                                $mq = array_shift($api_map[$mk]);
                                if (empty($api_map[$mk])) {
                                    unset($api_map[$mk]);
                                }
                                unset($found_keys[$mk]);
                                $DB->insert_record('smartworkbook_question', (object)[
                                    'workbookid'   => $workbook->id,
                                    'sortorder'    => $i++,
                                    'qtype'        => $mq['qtype'] ?? 'text',
                                    'label'        => $mq['label'] ?? '',
                                    'questiontext' => $mq['questiontext'] ?? '',
                                    'marks'        => (float)($mq['marks'] ?? 1),
                                    'model_answer' => $mq['model_answer'] ?? '',
                                    'rubric_notes' => $mq['rubric_notes'] ?? '',
                                    'timecreated'  => time(),
                                ]);
                            }
                        }
                    } else {
                        // No API questions matched this table — reconstruct it as an
                        // interactive structured table (qtype='table', sw_table JSON in
                        // model_answer) so blank cells become editable inputs.
                        //
                        // History of the triple bug this replaces:
                        //   • v1.0.69 used qtype='dochtml' → no editing, raw HTML blob
                        //   • v1.0.68 used qtype='structured_table' → wrong qtype (view.php
                        //     checks 'table'), JSON in questiontext (view.php reads
                        //     model_answer), missing sw_table key → all three mismatched.
                        //
                        // Correct format: qtype='table', sw_table JSON in model_answer,
                        // sw_table:true key present. Blank/underscore cells get e:true.

                        $stripped_plain = trim(preg_replace('/\s+/', ' ',
                            html_entity_decode(strip_tags($block['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

                        // Parse rows and cells from the block HTML.
                        preg_match_all('/<tr[^>]*>([\s\S]*?)<\/tr>/i', $block['html'], $_row_m);
                        $_rows_html = $_row_m[1] ?? [];

                        // Check if first row uses <th> elements.
                        $_first_row_has_th = !empty($_rows_html[0])
                            && (bool)preg_match('/<th[^>]*>/i', $_rows_html[0]);

                        $_headers   = [];
                        $_data_rows = [];

                        foreach ($_rows_html as $_ri => $_rh) {
                            preg_match_all('/<(?:td|th)[^>]*>([\s\S]*?)<\/(?:td|th)>/i', $_rh, $_cm);
                            $_cells = array_map(function ($c) {
                                return trim(html_entity_decode(strip_tags($c), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                            }, $_cm[1] ?? []);

                            // Drop rows that are entirely empty.
                            if (empty(array_filter($_cells, function ($c) { return $c !== ''; }))) {
                                continue;
                            }

                            $_all_non_empty = !in_array('', $_cells, true);

                            if ($_ri === 0 && ($_first_row_has_th || ($_all_non_empty && count($_cells) >= 2))) {
                                // First non-empty row with headers or all-filled multi-col → headers.
                                $_headers = $_cells;
                            } else {
                                // Build cell definitions: empty / underscores → editable.
                                $_row_data = [];
                                foreach ($_cells as $_ct) {
                                    $_editable = ($_ct === ''
                                        || strpos($_ct, '___') !== false
                                        || (bool)preg_match('/^[_\s.]{3,}$/', $_ct));
                                    $_row_data[] = ['v' => $_editable ? '' : $_ct, 'e' => (bool)$_editable];
                                }
                                $_data_rows[] = $_row_data;
                            }
                        }

                        // If all rows were consumed as headers, demote them to data.
                        if (empty($_data_rows) && !empty($_headers)) {
                            $_data_rows[] = array_map(
                                function ($c) { return ['v' => $c, 'e' => false]; }, $_headers);
                            $_headers = [];
                        }

                        if (mb_strlen($stripped_plain) < 80 && empty($_data_rows)) {
                            // Very short title/label table with no data rows → heading.
                            $DB->insert_record('smartworkbook_question', (object)[
                                'workbookid'   => $workbook->id,
                                'sortorder'    => $i++,
                                'qtype'        => 'heading',
                                'label'        => '',
                                'questiontext' => $stripped_plain,
                                'marks'        => 0,
                                'model_answer' => '',
                                'rubric_notes' => '',
                                'timecreated'  => time(),
                            ]);
                        } else {
                            // Multi-cell table → proper sw_table record.
                            // CORRECT field mapping (see triple-bug history above):
                            //   qtype        = 'table'
                            //   model_answer = sw_table JSON  (NOT questiontext)
                            //   questiontext = ''
                            $DB->insert_record('smartworkbook_question', (object)[
                                'workbookid'   => $workbook->id,
                                'sortorder'    => $i++,
                                'qtype'        => 'table',
                                'label'        => '',
                                'questiontext' => '',
                                'marks'        => 0,
                                'model_answer' => json_encode([
                                    'sw_table'  => true,
                                    'headers'   => $_headers,
                                    'rows'      => $_data_rows,
                                    'header_bg' => '#334155',
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                'rubric_notes' => '',
                                'timecreated'  => time(),
                            ]);
                        }
                    }
                    continue;
                }

                if ($block['type'] === 'image') {
                    $DB->insert_record('smartworkbook_question', (object)[
                        'workbookid'   => $workbook->id,
                        'sortorder'    => $i++,
                        'qtype'        => 'image',
                        'label'        => '',
                        'questiontext' => $block['src'],
                        'marks'        => 0,
                        'model_answer' => '',
                        'rubric_notes' => '',
                        'timecreated'  => time(),
                    ]);
                    continue;
                }

                if ($block['type'] === 'video') {
                    $DB->insert_record('smartworkbook_question', (object)[
                        'workbookid'   => $workbook->id,
                        'sortorder'    => $i++,
                        'qtype'        => 'video',
                        'label'        => '',
                        'questiontext' => $block['url'],
                        'marks'        => 0,
                        'model_answer' => '',
                        'rubric_notes' => '',
                        'timecreated'  => time(),
                    ]);
                    continue;
                }

                // 'para' type — attempt API match
                $key = smartworkbook_norm($block['text']);
                $matched_q = null;

                if (!empty($api_map[$key])) {
                    $matched_q = array_shift($api_map[$key]);
                    if (empty($api_map[$key])) {
                        unset($api_map[$key]);
                    }
                } else {
                    $matched_q = smartworkbook_fuzzy_match($key, $api_map);
                }

                if ($matched_q !== null) {
                    $rec = (object)[
                        'workbookid'   => $workbook->id,
                        'sortorder'    => $i++,
                        'qtype'        => $matched_q['qtype'] ?? 'text',
                        'label'        => $matched_q['label'] ?? '',
                        'questiontext' => $matched_q['questiontext'] ?? '',
                        'marks'        => (float)($matched_q['marks'] ?? 1),
                        'model_answer' => $matched_q['model_answer'] ?? '',
                        'rubric_notes' => $matched_q['rubric_notes'] ?? '',
                        'timecreated'  => time(),
                    ];
                } else {
                    $rec = (object)[
                        'workbookid'   => $workbook->id,
                        'sortorder'    => $i++,
                        'qtype'        => 'heading',
                        'label'        => '',
                        'questiontext' => !empty($block['html'])
                            ? $block['html']
                            : htmlspecialchars($block['text'], ENT_QUOTES | ENT_HTML5),
                        'marks'        => 0,
                        'model_answer' => '',
                        'rubric_notes' => '',
                        'timecreated'  => time(),
                    ];
                }
                $DB->insert_record('smartworkbook_question', $rec);
            }

            // Safety net: append any API questions not matched by a DOCX block
            foreach ($api_map as $remaining_qs) {
                foreach ($remaining_qs as $q) {
                    $DB->insert_record('smartworkbook_question', (object)[
                        'workbookid'   => $workbook->id,
                        'sortorder'    => $i++,
                        'qtype'        => $q['qtype'] ?? 'text',
                        'label'        => $q['label'] ?? '',
                        'questiontext' => $q['questiontext'] ?? '',
                        'marks'        => (float)($q['marks'] ?? 1),
                        'model_answer' => $q['model_answer'] ?? '',
                        'rubric_notes' => $q['rubric_notes'] ?? '',
                        'timecreated'  => time(),
                    ]);
                }
            }

        } else {
            // Fallback: API-only save (PDF uploads / DOCX parse failure)
            foreach ($data['questions'] as $q) {
                $rec = (object)[
                    'workbookid'   => $workbook->id,
                    'sortorder'    => $i++,
                    'qtype'        => $q['qtype'] ?? 'text',
                    'label'        => $q['label'] ?? '',
                    'questiontext' => $q['questiontext'] ?? '',
                    'marks'        => (float)($q['marks'] ?? 1),
                    'model_answer' => $q['model_answer'] ?? '',
                    'rubric_notes' => $q['rubric_notes'] ?? '',
                    'timecreated'  => time(),
                ];
                $DB->insert_record('smartworkbook_question', $rec);
            }
        }

        $DB->update_record('smartworkbook', (object)[
            'id'              => $workbook->id,
            'source_filename' => $filename,
            'status'          => 'ready',
            'timemodified'    => time(),
        ]);

        echo json_encode(['success' => true, 'count' => $i]);
        break;

    // ---- Teacher: delete a question/block ----------------------------------
    case 'delete_question':
        $cmid = required_param('cmid', PARAM_INT);
        $qid  = required_param('qid', PARAM_INT);

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:manage', $context);

        // Verify the question belongs to this workbook before deleting.
        $workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $q = $DB->get_record('smartworkbook_question', ['id' => $qid, 'workbookid' => $workbook->id]);
        if (!$q) {
            echo json_encode(['success' => false, 'error' => 'not_found']);
            break;
        }
        $DB->delete_records('smartworkbook_question', ['id' => $qid]);
        echo json_encode(['success' => true]);
        break;

    // ---- Teacher: save embedded image (paste / upload) ---------------------
    case 'save_image':
        $cmid       = required_param('cmid', PARAM_INT);
        $qid        = required_param('qid', PARAM_INT);
        $image_data = required_param('image_data', PARAM_RAW); // pipeline-ignore: PARAM_RAW — base64-encoded binary payload, decoded and validated before use

        $cm      = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:manage', $context);

        // Must be a base64 data URI for a web-safe image type.
        if (!preg_match('/^data:image\/(png|jpeg|gif|webp);base64,[A-Za-z0-9+\/]+=*$/', $image_data)) {
            echo json_encode(['success' => false, 'error' => 'invalid_image_data']);
            break;
        }

        $DB->update_record('smartworkbook_question', (object)[
            'id'           => $qid,
            'qtype'        => 'image',
            'questiontext' => $image_data,
        ]);
        echo json_encode(['success' => true]);
        break;

    // ---- Teacher: save YouTube video URL ------------------------------------
    case 'save_video':
        $cmid = required_param('cmid', PARAM_INT);
        $qid  = required_param('qid', PARAM_INT);
        $url  = required_param('url', PARAM_TEXT);

        $cm      = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:manage', $context);

        // Validate it looks like a YouTube URL.
        if (!preg_match('/(?:youtube\.com|youtu\.be)/', $url)) {
            echo json_encode(['success' => false, 'error' => 'not_youtube_url']);
            break;
        }
        $url = clean_param($url, PARAM_URL);

        $DB->update_record('smartworkbook_question', (object)[
            'id'           => $qid,
            'qtype'        => 'video',
            'questiontext' => $url,
        ]);
        echo json_encode(['success' => true]);
        break;

    case 'save_questions':
        $cmid      = required_param('cmid', PARAM_INT);
        $questions = required_param('questions', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON blob, immediately json_decode()d and validated

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:manage', $context);

        $workbook  = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $questions = json_decode($questions, true);

        if (!is_array($questions)) {
            echo json_encode(['success' => false, 'error' => 'invalid_data']);
            break;
        }

        foreach ($questions as $i => $q) {
            if (empty($q['id'])) continue;
            $q_qtype = clean_param($q['qtype'] ?? 'text', PARAM_ALPHA);
            $q_marks = in_array($q_qtype, ['heading', 'dochtml', 'image', 'video'])
                ? 0.0
                : max(0.5, (float)($q['marks'] ?? 1));
            $DB->update_record('smartworkbook_question', (object)[
                'id'           => (int)$q['id'],
                'sortorder'    => (int)$i,
                'qtype'        => $q_qtype,
                'label'        => clean_param($q['label'] ?? '', PARAM_TEXT),
                'questiontext' => clean_param($q['questiontext'] ?? '', PARAM_RAW), // pipeline-ignore: PARAM_RAW — free-form rich text/HTML, escaped or format_text()d on output
                'marks'        => $q_marks,
                'model_answer' => clean_param($q['model_answer'] ?? '', PARAM_RAW), // pipeline-ignore: PARAM_RAW — free-form rich text/HTML, escaped or format_text()d on output
                'rubric_notes' => clean_param($q['rubric_notes'] ?? '', PARAM_RAW), // pipeline-ignore: PARAM_RAW — free-form rich text/HTML, escaped or format_text()d on output
            ]);
        }

        echo json_encode(['success' => true]);
        break;

    // ---- Teacher: publish / unpublish -------------------------------------
    case 'set_status':
        $cmid   = required_param('cmid', PARAM_INT);
        $status = required_param('status', PARAM_ALPHA);

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:manage', $context);

        $allowed = ['ready', 'published'];
        if (!in_array($status, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'invalid_status']);
            break;
        }

        $workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $DB->update_record('smartworkbook', (object)[
            'id'           => $workbook->id,
            'status'       => $status,
            'timemodified' => time(),
        ]);

        echo json_encode(['success' => true, 'status' => $status]);
        break;

    // ---- Teacher: AI mark a single submission ------------------------------
    case 'ai_mark':
        $cmid         = required_param('cmid', PARAM_INT);
        $submissionid = required_param('submissionid', PARAM_INT);

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:grade', $context);

        \core\session\manager::write_close();

        $workbook   = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $submission = $DB->get_record('smartworkbook_submission', ['id' => $submissionid, 'workbookid' => $workbook->id], '*', MUST_EXIST);

        list($site_id, $api_key) = smartworkbook_get_api_credentials();
        if (empty($site_id) || empty($api_key)) {
            echo json_encode(['success' => false, 'error' => get_string('error_nocredentials', 'smartworkbook')]);
            break;
        }

        // Build submission payload
        $questions = $DB->get_records('smartworkbook_question', ['workbookid' => $workbook->id], 'sortorder ASC');
        $responses = $DB->get_records('smartworkbook_response', ['workbookid' => $workbook->id, 'userid' => $submission->userid]);
        $resp_map = [];
        foreach ($responses as $r) {
            $resp_map[$r->questionid] = $r->responsetext;
        }

        $qa_pairs = [];
        foreach ($questions as $q) {
            if (in_array($q->qtype, ['heading', 'dochtml', 'image', 'video'])) continue;
            $qa_pairs[] = [
                'questionid'   => (int)$q->id,
                'questiontext' => $q->questiontext,
                'model_answer' => $q->model_answer ?? '',
                'rubric_notes' => $q->rubric_notes ?? '',
                'marks'        => (float)$q->marks,
                'student_answer' => $resp_map[$q->id] ?? '',
            ];
        }

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 120]);

        $payload = json_encode([
            'site_id'   => $site_id,
            'api_key'   => $api_key,
            'qa_pairs'  => $qa_pairs,
        ]);

        $response = $curl->post('https://lms-labs.com/api/smartworkbook/mark-submission', $payload, [
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);

        $data = json_decode($response, true);

        if (empty($data['success']) || empty($data['marks'])) {
            $err = $data['error'] ?? get_string('error_marking', 'smartworkbook');
            echo json_encode(['success' => false, 'error' => $err]);
            break;
        }

        // Save AI marks
        // total_max must equal the full workbook maximum (all non-heading/dochtml questions),
        // NOT just the questions the AI happened to return.  Computing it from $data['marks']
        // means a partial API response silently shrinks max_marks and inflates the percentage.
        $total_max = 0;
        foreach ($questions as $q_tmp) {
            if (in_array($q_tmp->qtype, ['heading', 'dochtml', 'image', 'video'])) continue;
            $total_max += (float)$q_tmp->marks;
        }

        $total_earned = 0;
        $now = time();

        foreach ($data['marks'] as $m) {
            $qid = (int)$m['questionid'];
            $q   = $questions[$qid] ?? null;
            if (!$q) continue;

            $existing = $DB->get_record('smartworkbook_mark', ['submissionid' => $submission->id, 'questionid' => $qid]);
            $mark_val = min((float)$m['mark'], (float)$q->marks);
            $total_earned += $mark_val;

            if ($existing) {
                $existing->ai_mark        = $mark_val;
                $existing->ai_comment     = $m['comment'] ?? '';
                $existing->ai_confidence  = $m['confidence'] ?? 'medium';
                $existing->status         = 'ai_done';
                $existing->timemodified   = $now;
                $DB->update_record('smartworkbook_mark', $existing);
            } else {
                $DB->insert_record('smartworkbook_mark', (object)[
                    'submissionid'  => $submission->id,
                    'questionid'    => $qid,
                    'ai_mark'       => $mark_val,
                    'ai_comment'    => $m['comment'] ?? '',
                    'ai_confidence' => $m['confidence'] ?? 'medium',
                    'status'        => 'ai_done',
                    'timecreated'   => $now,
                    'timemodified'  => $now,
                ]);
            }
        }

        // Update submission
        $DB->update_record('smartworkbook_submission', (object)[
            'id'           => $submission->id,
            'status'       => 'ai_marked',
            'total_marks'  => $total_earned,
            'max_marks'    => $total_max,
            'timemodified' => $now,
        ]);

        echo json_encode(['success' => true, 'total_earned' => $total_earned, 'total_max' => $total_max]);
        break;

    // ---- Teacher: save/override a single mark ------------------------------
    case 'save_mark':
        $cmid         = required_param('cmid', PARAM_INT);
        $submissionid = required_param('submissionid', PARAM_INT);
        $questionid   = required_param('questionid', PARAM_INT);
        $mark         = required_param('mark', PARAM_FLOAT);
        $comment      = optional_param('comment', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — free-form rich text/HTML, escaped or format_text()d on output
        $status       = optional_param('mark_status', 'approved', PARAM_ALPHA);

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:grade', $context);

        $workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $q = $DB->get_record('smartworkbook_question', ['id' => $questionid, 'workbookid' => $workbook->id], '*', MUST_EXIST);

        $mark = min(max(0, (float)$mark), (float)$q->marks);
        $allowed_statuses = ['approved', 'overridden', 'reanswer'];
        if (!in_array($status, $allowed_statuses)) $status = 'approved';

        $now = time();
        $existing = $DB->get_record('smartworkbook_mark', ['submissionid' => $submissionid, 'questionid' => $questionid]);
        if ($existing) {
            $existing->teacher_mark    = $mark;
            $existing->teacher_comment = $comment;
            $existing->status          = $status;
            $existing->timemodified    = $now;
            $DB->update_record('smartworkbook_mark', $existing);
        } else {
            $DB->insert_record('smartworkbook_mark', (object)[
                'submissionid'   => $submissionid,
                'questionid'     => $questionid,
                'teacher_mark'   => $mark,
                'teacher_comment'=> $comment,
                'status'         => $status,
                'timecreated'    => $now,
                'timemodified'   => $now,
            ]);
        }

        // Recalculate totals for the submission.
        // total_max = full workbook maximum (ALL non-heading/dochtml questions).
        // Computing it only from mark records causes it to shrink when some questions
        // have no record yet, producing an inflated percentage at release time.
        // total_earned = sum of best available marks, skipping questions awaiting re-answer.
        $marks_all = $DB->get_records('smartworkbook_mark', ['submissionid' => $submissionid]);
        $questions_all = $DB->get_records('smartworkbook_question', ['workbookid' => $workbook->id]);
        $q_map = [];
        foreach ($questions_all as $qq) { $q_map[$qq->id] = $qq; }

        $marks_by_qid = [];
        foreach ($marks_all as $mm) { $marks_by_qid[$mm->questionid] = $mm; }

        $total_earned = 0;
        $total_max    = 0;
        foreach ($questions_all as $qq) {
            if (in_array($qq->qtype, ['heading', 'dochtml', 'image', 'video'])) continue;
            $total_max += (float)$qq->marks;
            if (!isset($marks_by_qid[$qq->id])) continue;
            $mm = $marks_by_qid[$qq->id];
            if ($mm->status === 'reanswer') continue; // zero contribution until student resubmits
            $final = $mm->teacher_mark ?? $mm->ai_mark ?? 0;
            $total_earned += (float)$final;
        }

        $DB->update_record('smartworkbook_submission', (object)[
            'id'           => $submissionid,
            'total_marks'  => $total_earned,
            'max_marks'    => $total_max,
            'timemodified' => $now,
        ]);

        echo json_encode(['success' => true, 'total_earned' => $total_earned, 'total_max' => $total_max]);
        break;

    // ---- Teacher: release grades to a student ------------------------------
    case 'release_grades':
        $cmid         = required_param('cmid', PARAM_INT);
        $submissionid = required_param('submissionid', PARAM_INT);
        $feedback     = optional_param('feedback', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — free-form rich text/HTML, escaped or format_text()d on output

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:grade', $context);

        $workbook   = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $submission = $DB->get_record('smartworkbook_submission', ['id' => $submissionid, 'workbookid' => $workbook->id], '*', MUST_EXIST);

        $total_earned = (float)($submission->total_marks ?? 0);
        $total_max    = (float)($submission->max_marks ?? 0);
        $grade_max    = (int)($workbook->grade ?? 100);
        $grade_val    = smartworkbook_calc_grade($total_earned, $total_max, $grade_max);

        $now = time();
        $DB->update_record('smartworkbook_submission', (object)[
            'id'               => $submission->id,
            'status'           => 'grades_released',
            'grade'            => $grade_val,
            'teacher_feedback' => $feedback,
            'graderid'         => $USER->id,
            'timegraded'       => $now,
            'timemodified'     => $now,
        ]);

        // Post to Moodle gradebook
        require_once($CFG->libdir . '/gradelib.php');
        $grade_obj = new stdClass();
        $grade_obj->userid   = $submission->userid;
        $grade_obj->rawgrade = $grade_val;
        smartworkbook_grade_item_update($workbook, $grade_obj);

        echo json_encode(['success' => true, 'grade' => $grade_val]);
        break;

    // ---- Teacher: reset a submission to re-answer mode --------------------
    case 'reset_submission':
        $cmid         = required_param('cmid', PARAM_INT);
        $submissionid = required_param('submissionid', PARAM_INT);

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:grade', $context);

        $workbook   = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $submission = $DB->get_record('smartworkbook_submission', [
            'id'         => $submissionid,
            'workbookid' => $workbook->id,
        ], '*', MUST_EXIST);

        // Only allow resetting if at least one question is flagged for re-answer
        $flagged_count = $DB->count_records('smartworkbook_mark', [
            'submissionid' => $submission->id,
            'status'       => 'reanswer',
        ]);

        if ($flagged_count === 0) {
            echo json_encode(['success' => false, 'error' => 'No questions are flagged for re-answer. Use the marking console to flag at least one question first.']);
            break;
        }

        $DB->update_record('smartworkbook_submission', (object)[
            'id'           => $submission->id,
            'status'       => 'reanswer',
            'timemodified' => time(),
        ]);

        echo json_encode(['success' => true, 'flagged' => $flagged_count]);
        break;

    // ---- Teacher: get marks for marking console ----------------------------
    case 'get_marks':
        $cmid         = required_param('cmid', PARAM_INT);
        $submissionid = required_param('submissionid', PARAM_INT);

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:grade', $context);

        $workbook   = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $submission = $DB->get_record('smartworkbook_submission', ['id' => $submissionid, 'workbookid' => $workbook->id], '*', MUST_EXIST);
        $student    = $DB->get_record('user', ['id' => $submission->userid], 'id,firstname,lastname,email', MUST_EXIST);

        $questions = $DB->get_records('smartworkbook_question', ['workbookid' => $workbook->id], 'sortorder ASC');
        $responses = $DB->get_records('smartworkbook_response', ['workbookid' => $workbook->id, 'userid' => $submission->userid]);
        $marks_recs = $DB->get_records('smartworkbook_mark', ['submissionid' => $submission->id]);

        $resp_map  = [];
        foreach ($responses as $r) { $resp_map[$r->questionid] = $r->responsetext; }
        $marks_map = [];
        foreach ($marks_recs as $m) { $marks_map[$m->questionid] = $m; }

        $result_q = [];
        foreach ($questions as $q) {
            $m = $marks_map[$q->id] ?? null;
            $result_q[] = [
                'id'            => (int)$q->id,
                'qtype'         => $q->qtype,
                'label'         => $q->label,
                'questiontext'  => $q->questiontext,
                'marks'         => (float)$q->marks,
                'model_answer'  => $q->model_answer,
                'student_answer' => $resp_map[$q->id] ?? '',
                'ai_mark'       => $m ? (float)($m->ai_mark ?? 0) : null,
                'ai_comment'    => $m ? ($m->ai_comment ?? '') : null,
                'ai_confidence' => $m ? ($m->ai_confidence ?? '') : null,
                'teacher_mark'  => $m ? (float)($m->teacher_mark ?? $m->ai_mark ?? 0) : null,
                'teacher_comment' => $m ? ($m->teacher_comment ?? $m->ai_comment ?? '') : null,
                'mark_status'   => $m ? $m->status : 'pending',
            ];
        }

        echo json_encode([
            'success'     => true,
            'student'     => ['id' => (int)$student->id, 'name' => $student->firstname . ' ' . $student->lastname],
            'submission'  => [
                'id'           => (int)$submission->id,
                'status'       => $submission->status,
                'total_marks'  => $submission->total_marks,
                'max_marks'    => $submission->max_marks,
            ],
            'questions'   => $result_q,
        ]);
        break;

    // ---- Teacher: generate model answers -----------------------------------
    case 'generate_model_answers':
        $cmid = required_param('cmid', PARAM_INT);

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:manage', $context);

        \core\session\manager::write_close();

        $workbook  = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $questions = $DB->get_records('smartworkbook_question', ['workbookid' => $workbook->id], 'sortorder ASC');

        list($site_id, $api_key) = smartworkbook_get_api_credentials();
        if (empty($site_id) || empty($api_key)) {
            echo json_encode(['success' => false, 'error' => get_string('error_nocredentials', 'smartworkbook')]);
            break;
        }

        $q_list = [];
        foreach ($questions as $q) {
            if (in_array($q->qtype, ['heading', 'dochtml', 'image', 'video'])) continue;
            $q_list[] = [
                'id'           => (int)$q->id,
                'questiontext' => $q->questiontext,
                'marks'        => (float)$q->marks,
                'existing_answer' => $q->model_answer ?? '',
            ];
        }

        // Pre-flight: if every block is a heading/dochtml/image/video there is nothing
        // to generate answers for. Return a clear message instead of calling the API
        // with an empty list (which would cause the empty-array check below to misfire).
        if (empty($q_list)) {
            echo json_encode([
                'success' => false,
                'error'   => 'No answerable questions found in this workbook. Use "Extract Questions with AI" (the Convert button) to convert your DOCX into individual text/long/yesno questions first, then generate model answers.',
            ]);
            break;
        }

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 120]);

        $payload = json_encode([
            'site_id'   => $site_id,
            'api_key'   => $api_key,
            'questions' => $q_list,
        ]);

        $response = $curl->post('https://lms-labs.com/api/smartworkbook/generate-model-answers', $payload, [
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);

        $data = json_decode($response, true);

        // Only treat as failure when success is explicitly false or missing.
        // Do NOT check empty($data['answers']) — an empty array is valid when all
        // questions already had existing answers and nothing needed regenerating.
        if (empty($data['success'])) {
            $err = $data['error'] ?? 'Model answer generation failed. Please try again.';
            echo json_encode(['success' => false, 'error' => $err]);
            break;
        }

        foreach ($data['answers'] as $ans) {
            $qid = (int)$ans['id'];
            $DB->update_record('smartworkbook_question', (object)[
                'id'           => $qid,
                'model_answer' => clean_param($ans['model_answer'], PARAM_RAW), // pipeline-ignore: PARAM_RAW — free-form rich text/HTML, escaped or format_text()d on output
            ]);
        }

        echo json_encode(['success' => true, 'count' => count($data['answers'])]);
        break;

    // ---- save_meta: store group member names (and any other per-submission metadata) ----
    case 'save_meta':
        $cmid = required_param('cmid', PARAM_INT);
        $meta = required_param('meta', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON blob, immediately json_decode()d and validated

        $cm      = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:submit', $context);

        // Validate JSON
        $meta_arr = json_decode($meta, true);
        if (!is_array($meta_arr)) {
            echo json_encode(['success' => false, 'error' => 'invalid_meta']);
            break;
        }

        $workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);

        $submission = $DB->get_record('smartworkbook_submission', [
            'workbookid' => $workbook->id,
            'userid'     => $USER->id,
        ]);

        $clean_meta = clean_param($meta, PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON blob, immediately json_decode()d and validated

        if ($submission) {
            // Only update if not fully locked (submitted/ai_marked/grades_released without reanswer)
            $locked = in_array($submission->status, ['submitted', 'ai_marked', 'grades_released'])
                      && $submission->status !== 'reanswer';
            if (!$locked) {
                $DB->update_record('smartworkbook_submission', (object)[
                    'id'           => $submission->id,
                    'meta_json'    => $clean_meta,
                    'timemodified' => time(),
                ]);
            }
        } else {
            $DB->insert_record('smartworkbook_submission', (object)[
                'workbookid'  => $workbook->id,
                'userid'      => $USER->id,
                'status'      => 'draft',
                'meta_json'   => $clean_meta,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        echo json_encode(['success' => true]);
        break;

    case 'save_settings':
        $cmid = required_param('cmid', PARAM_INT);
        $cm   = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context  = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:manage', $context);
        $workbook = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $showstudentname = optional_param('showstudentname', 0, PARAM_INT) ? 1 : 0;
        $numgroupmembers = max(0, min(6, (int)optional_param('numgroupmembers', 0, PARAM_INT)));
        $manualgrading   = optional_param('manualgrading', 0, PARAM_INT) ? 1 : 0;
        $DB->set_field('smartworkbook', 'showstudentname', $showstudentname, ['id' => $workbook->id]);
        $DB->set_field('smartworkbook', 'numgroupmembers', $numgroupmembers, ['id' => $workbook->id]);
        // manual_grading column added in v1.0.26 — guard with field_exists for safety
        $ddl = $DB->get_manager();
        if ($ddl->field_exists(new xmldb_table('smartworkbook'), new xmldb_field('manual_grading'))) {
            $DB->set_field('smartworkbook', 'manual_grading', $manualgrading, ['id' => $workbook->id]);
        }
        echo json_encode(['success' => true, 'manual_grading' => $manualgrading]);
        break;

    // ---- Teacher: manual mark submission (no AI) ----------------------------
    case 'manual_mark_submission':
        $cmid         = required_param('cmid', PARAM_INT);
        $submissionid = required_param('submissionid', PARAM_INT);
        $marks_json   = required_param('marks', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON blob, immediately json_decode()d and validated

        $cm = get_coursemodule_from_id('smartworkbook', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        require_capability('mod/smartworkbook:grade', $context);

        \core\session\manager::write_close();

        $workbook   = $DB->get_record('smartworkbook', ['id' => $cm->instance], '*', MUST_EXIST);
        $submission = $DB->get_record('smartworkbook_submission', ['id' => $submissionid, 'workbookid' => $workbook->id], '*', MUST_EXIST);

        $marks = json_decode($marks_json, true);
        if (!is_array($marks)) {
            echo json_encode(['success' => false, 'error' => 'Invalid marks data']);
            break;
        }

        $questions_all = $DB->get_records('smartworkbook_question', ['workbookid' => $workbook->id]);
        $q_map = [];
        foreach ($questions_all as $qq) { $q_map[$qq->id] = $qq; }

        $now = time();

        // First pass: upsert every mark the teacher submitted.
        // Build an input map keyed by question ID for the second pass.
        $submitted_map = [];
        foreach ($marks as $m) {
            $qid = (int)($m['questionid'] ?? 0);
            if (!isset($q_map[$qid])) continue;
            $submitted_map[$qid] = $m;
        }

        foreach ($submitted_map as $qid => $m) {
            $comment = trim((string)($m['comment'] ?? ''));
            $q_max   = (float)$q_map[$qid]->marks;
            $awarded = min(max(0.0, (float)($m['mark'] ?? 0)), $q_max);

            $existing = $DB->get_record('smartworkbook_mark', ['submissionid' => $submission->id, 'questionid' => $qid]);
            if ($existing) {
                $existing->teacher_mark    = $awarded;
                $existing->teacher_comment = $comment;
                $existing->status          = 'approved';
                $existing->timemodified    = $now;
                $DB->update_record('smartworkbook_mark', $existing);
            } else {
                $DB->insert_record('smartworkbook_mark', (object)[
                    'submissionid'    => $submission->id,
                    'questionid'      => $qid,
                    'teacher_mark'    => $awarded,
                    'teacher_comment' => $comment,
                    'status'          => 'approved',
                    'timecreated'     => $now,
                    'timemodified'    => $now,
                ]);
            }
        }

        // Second pass: compute totals from the full question list (same logic as save_mark/ai_mark).
        // total_max = sum of ALL non-heading/dochtml questions regardless of whether they were submitted.
        // total_earned = sum of awarded marks for submitted questions (all should be present since the
        // JS checklist renders every gradeable question, but this is robust against edge-cases).
        $total_earned = 0.0;
        $total_max    = 0.0;
        foreach ($questions_all as $qq) {
            if (in_array($qq->qtype, ['heading', 'dochtml', 'image', 'video'])) continue;
            $total_max += (float)$qq->marks;
            if (!isset($submitted_map[$qq->id])) continue;
            $m_sub   = $submitted_map[$qq->id];
            $q_max   = (float)$qq->marks;
            $awarded = min(max(0.0, (float)($m_sub['mark'] ?? 0)), $q_max);
            $total_earned += $awarded;
        }

        $grade_max = max(1, (int)($workbook->grade ?? 100));
        $grade_val = smartworkbook_calc_grade($total_earned, $total_max, $grade_max);

        $DB->update_record('smartworkbook_submission', (object)[
            'id'               => $submission->id,
            'status'           => 'grades_released',
            'total_marks'      => $total_earned,
            'max_marks'        => $total_max,
            'grade'            => $grade_val,
            'graderid'         => $USER->id,
            'timegraded'       => $now,
            'timemodified'     => $now,
        ]);

        require_once($CFG->libdir . '/gradelib.php');
        $grade_obj           = new stdClass();
        $grade_obj->userid   = $submission->userid;
        $grade_obj->rawgrade = $grade_val;
        smartworkbook_grade_item_update($workbook, $grade_obj);

        echo json_encode([
            'success'       => true,
            'grade'         => $grade_val,
            'total_earned'  => $total_earned,
            'total_max'     => $total_max,
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
        break;
}
