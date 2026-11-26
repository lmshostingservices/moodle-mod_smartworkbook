<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_smartworkbook\local\byok;

/**
 * The LMS Labs Smart Workbook service routes, run inside this site with its own AI.
 *
 * A port of /api/smartworkbook/convert (Word files), /mark-submission and /generate-model-answers in the
 * service's routes.ts and smartworkbookContract.ts: same checks, prompts, answers and errors, without the
 * credit steps. Each method takes the JSON body the plugin would send and returns [HTTP status, response body].
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** @var callable|null function(string $system, string $user, int $maxtokens, bool $json): string; throws on failure. */
    public static $aicall = null;

    /** @var string JavaScript's whitespace (String.prototype.trim and \s). */
    const WS = '\x{9}-\x{d}\x{20}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';

    /** @var string A JavaScript \w character, for \b outside Unicode mode. */
    const WORD = '[A-Za-z0-9_]';

    /** @var string[] The question types the conversion may return. */
    const QTYPES = ['text', 'long', 'yesno', 'rating', 'heading', 'table', 'dochtml', 'image', 'video'];

    /** @var string The service's conversion instructions (%%text%% is the workbook). */
    const CONVERT_PROMPT = 'You are an educational content analyser for Australian RTO (Registered Training Organisation) '
        . "workbooks.\nExtract ALL questions and section headings into a structured JSON array.\n\nThe content below is "
        . 'converted from a Word document and preserves table structure as pipe-separated rows (| cell | cell |) and '
        . "heading levels (# ## ###).\n\nRules:\n- Each distinct question or instruction that a student must respond to = "
        . "one object.\n- Section headings / topic titles (no student response needed) use qtype: \"heading\".\n"
        . "- Short-answer (1–3 sentences) → qtype: \"text\".\n- Extended response (paragraph or more) → qtype: " .
            "\"long\".\n"
        . "- Yes/No or True/False → qtype: \"yesno\".\n- Rating scale 1–5 → qtype: \"rating\".\n- Questions in a table "
        . "with response columns → each question row = one object.\n- Multiple-choice options are NOT separate questions "
        . "— include the whole MC question + options as one questiontext block.\n- Estimate marks as a number: 1 for "
        . "short, 2–4 for medium, 4–8 for detailed.\n- Extract model answers where present (teacher version text after "
        . "the question or in a separate column).\n- label: question number/letter if shown (e.g. \"Q1\", \"1a\", "
        . "\"Part A\"). Use an empty string when absent.\n- Return every property on every object: qtype, label, "
        . "questiontext, marks, model_answer, rubric_notes.\n- Never return null. Use empty strings for label, "
        . "model_answer, and rubric_notes when unavailable. Use marks=1 when no mark value can be estimated.\n- qtype "
        . "must be exactly one of: text, long, yesno, rating, heading, table, dochtml, image, video.\n\nReturn ONLY valid "
        . 'JSON in this exact shape: {"questions": [{"qtype":"text","label":"Q1","questiontext":"...","marks":2,'
        . "\"model_answer\":\"\",\"rubric_notes\":\"\"}]}\n\nWorkbook content:\n%%text%%\n\nMark handling: Preserve any "
        . 'explicit mark value written in the workbook exactly (for example, "(3 marks)" means marks: 3). Never replace '
        . 'an explicit value with 1. Estimate marks only when the source does not state them.';

    /** @var string */
    const CONVERT_SYSTEM = 'You extract structured question data from Australian VET/RTO educational documents. '
        . 'Return only valid JSON. Do not wrap in markdown code fences.';

    /** @var string */
    const MARK_SYSTEM = 'You are a VET assessor. Mark student answers fairly and return only valid JSON.';

    /** @var string */
    const ANSWER_SYSTEM = 'You are a VET subject matter expert. Write model answers for student workbook questions.';

    // Routes.

    /**
     * POST /api/smartworkbook/convert for a Word workbook (PDF stays with LMS Labs: it needs the service's PDF reader).
     *
     * @param mixed $body The decoded JSON body (objects as stdClass).
     * @return array [status, body]
     */
    public static function convert($body): array {
        $body = is_object($body) ? $body : new \stdClass();
        $filename = $body->filename ?? null;
        $content = $body->filecontent ?? null;
        if (!is_string($filename) || self::trim($filename) === '') {
            return self::fail(400, 'The uploaded document has no filename.');
        }
        $dot = strrpos($filename, '.');
        $ext = mb_strtolower($dot === false ? $filename : substr($filename, $dot + 1), 'UTF-8');
        if ($ext !== 'docx' && $ext !== 'pdf') {
            return self::fail(400, 'Only DOCX and PDF workbooks are supported.');
        }
        if (!is_string($content)) {
            return self::fail(400, 'The uploaded document is missing file data.');
        }
        $base64 = preg_replace('/[' . self::WS . ']+/u', '', $content);
        if (
            $base64 === null || $base64 === '' || strlen($base64) % 4 !== 0
                || !preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $base64)
        ) {
            return self::fail(400, 'The uploaded document data is not valid Base64.');
        }
        $file = base64_decode($base64);
        if ($file === false || $file === '') {
            return self::fail(400, 'The uploaded document is empty.');
        }
        if ($ext === 'pdf') {
            throw new \coding_exception('PDF workbooks are read by LMS Labs');
        }
        try {
            $text = docx_text::text($file);
        } catch (\moodle_exception $e) {
            return self::fail(
                422,
                'Could not read text from the Word document. Check that it is a valid, unencrypted DOCX file.'
            );
        }
        $text = self::slice($text, 0, 24000);
        if (self::trim($text) === '') {
            return self::fail(422, 'The document contains no readable text.');
        }
        try {
            $raw = (self::$aicall)(self::CONVERT_SYSTEM, strtr(self::CONVERT_PROMPT, ['%%text%%' => $text]), 6000, true);
        } catch (\Throwable $e) {
            return self::fail(502, 'The AI service could not process this workbook. Please try again later.');
        }
        $clean = preg_replace('/^\x60{3}(?:[jJ][sS][oO][nN])?[' . self::WS . ']*/u', '', self::trim($raw));
        $clean = preg_replace('/[' . self::WS . ']*\x60{3}$/Du', '', $clean);
        $parsed = self::parse($clean);
        if ($parsed === false) {
            return self::fail(502, 'The AI service returned an unreadable response. Please retry.');
        }
        [$questions, $fields] = self::validate_conversion($parsed);
        if ($questions === null) {
            $summary = $fields ? ' Invalid fields: ' . implode(', ', $fields) . '.' : '';
            return self::fail(502, 'The AI response did not contain a valid, non-empty list of workbook questions.'
                . $summary . ' Please retry.');
        }
        $questions = self::explicit_marks($questions, $text);
        return [200, ['success' => true, 'count' => count($questions), 'questions' => $questions]];
    }

    /**
     * POST /api/smartworkbook/mark-submission.
     *
     * @param mixed $body
     * @return array [status, body]
     */
    public static function mark($body): array {
        $pairs = is_object($body) ? ($body->qa_pairs ?? null) : null;
        if (!is_array($pairs) || !$pairs || !self::unique($pairs, 'questionid')) {
            return self::fail(400, 'Invalid marking request');
        }
        foreach ($pairs as $pair) {
            if (!self::mark_input($pair)) {
                return self::fail(400, 'Invalid marking request');
            }
        }
        $marks = [];
        try {
            foreach ($pairs as $pair) {
                if ($pair->student_answer === '' || self::trim($pair->student_answer) === '') {
                    $marks[] = ['questionid' => $pair->questionid, 'mark' => 0, 'comment' => 'No answer provided.',
                        'confidence' => 'high'];
                    continue;
                }
                $model = $pair->model_answer ?? '';
                $rubric = $pair->rubric_notes ?? '';
                $max = self::numstr($pair->marks);
                $prompt = "Mark this student answer.\nQuestion: " . $pair->questiontext
                    . "\nModel answer: " . ($model !== '' ? $model : 'N/A')
                    . "\nRubric: " . ($rubric !== '' ? $rubric : 'Award marks for accuracy and completeness.')
                    . "\nMax marks: " . $max . "\nStudent answer: " . $pair->student_answer
                    . "\n\nReturn JSON: {\"mark\": <number 0 to " . $max
                    . '>, "comment": "<brief feedback>", "confidence": "<high|medium|low>"}';
                $raw = (self::$aicall)(self::MARK_SYSTEM, $prompt, 500, true);
                $parsed = self::parse($raw);
                if ($parsed === false) {
                    throw new \moodle_exception('byok_badmark', 'smartworkbook');
                }
                $marks[] = self::normalise_mark($pair->questionid, $pair->marks, $parsed);
            }
        } catch (\Throwable $e) {
            return self::fail(500, 'Workbook marking failed. No completed marking result was returned.');
        }
        return [200, ['success' => true, 'marks' => $marks]];
    }

    /**
     * POST /api/smartworkbook/generate-model-answers.
     *
     * @param mixed $body
     * @return array [status, body]
     */
    public static function answers($body): array {
        $questions = is_object($body) ? ($body->questions ?? null) : null;
        if (!is_array($questions) || !$questions || !self::unique($questions, 'id')) {
            return self::fail(400, 'Invalid model answer request');
        }
        foreach ($questions as $question) {
            if (!self::answer_input($question)) {
                return self::fail(400, 'Invalid model answer request');
            }
        }
        $answers = [];
        try {
            foreach ($questions as $q) {
                $existing = $q->existing_answer ?? null;
                if (is_string($existing) && $existing !== '' && self::len(self::trim($existing)) > 20) {
                    $answers[] = ['id' => $q->id, 'model_answer' => $existing];
                    continue;
                }
                $prompt = "Write a model answer for this VET workbook question.\nQuestion: " . $q->questiontext
                    . "\nMax marks: " . self::numstr($q->marks) . "\nWrite a clear, concise model answer an assessor can "
                    . 'use to mark student responses. Be specific and practical.';
                $raw = (self::$aicall)(self::ANSWER_SYSTEM, $prompt, 600, false);
                if (!is_string($raw) || self::trim($raw) === '') {
                    throw new \moodle_exception('byok_badanswer', 'smartworkbook');
                }
                $answers[] = ['id' => $q->id, 'model_answer' => self::trim($raw)];
            }
        } catch (\Throwable $e) {
            return self::fail(500, 'Workbook model answer generation failed');
        }
        return [200, ['success' => true, 'answers' => $answers]];
    }

    /**
     * An error answer.
     *
     * @param int $status
     * @param string $message
     * @return array
     */
    protected static function fail(int $status, string $message): array {
        return [$status, ['success' => false, 'error' => $message]];
    }

    // Request checks (smartworkbookMarkInput, smartworkbookAnswerInput).

    /**
     * new Set(items.map(item => item[key])).size === items.length.
     *
     * @param array $items
     * @param string $key
     * @return bool
     */
    protected static function unique(array $items, string $key): bool {
        $seen = [];
        foreach ($items as $item) {
            $value = is_object($item) && property_exists($item, $key) ? $item->$key : null;
            $id = self::set_key($value, !is_object($item) || !property_exists($item, $key));
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
        }
        return true;
    }

    /**
     * A SameValueZero key for a JSON value.
     *
     * @param mixed $value
     * @param bool $missing
     * @return string
     */
    protected static function set_key($value, bool $missing): string {
        if ($missing) {
            return 'u';
        }
        if (is_int($value) || is_float($value)) {
            return 'n' . self::numstr($value);
        }
        if (is_string($value)) {
            return 's' . $value;
        }
        if ($value === null || is_bool($value)) {
            return 'k' . var_export($value, true);
        }
        return 'o' . spl_object_id((object) $value) . '-' . mt_rand();
    }

    /**
     * Whether a value is a JavaScript number zod accepts (not NaN).
     *
     * @param mixed $value
     * @return bool
     */
    protected static function is_number($value): bool {
        return is_int($value) || (is_float($value) && !is_nan($value));
    }

    /**
     * z.number().finite().nonnegative().
     *
     * @param mixed $value
     * @return bool
     */
    protected static function marks_ok($value): bool {
        return self::is_number($value) && is_finite((float) $value) && $value >= 0;
    }

    /**
     * z.number().int().positive().
     *
     * @param mixed $value
     * @return bool
     */
    protected static function id_ok($value): bool {
        return self::is_number($value) && is_finite((float) $value) && floor((float) $value) == $value && $value > 0;
    }

    /**
     * z.string().nullish() on an optional property.
     *
     * @param \stdClass $item
     * @param string $key
     * @return bool
     */
    protected static function nullish_string(\stdClass $item, string $key): bool {
        return !property_exists($item, $key) || $item->$key === null || is_string($item->$key);
    }

    /**
     * smartworkbookMarkInput.
     *
     * @param mixed $pair
     * @return bool
     */
    protected static function mark_input($pair): bool {
        return is_object($pair)
            && self::id_ok($pair->questionid ?? null)
            && is_string($pair->questiontext ?? null) && $pair->questiontext !== ''
            && is_string($pair->student_answer ?? null)
            && self::nullish_string($pair, 'model_answer')
            && self::nullish_string($pair, 'rubric_notes')
            && self::marks_ok($pair->marks ?? null);
    }

    /**
     * smartworkbookAnswerInput.
     *
     * @param mixed $question
     * @return bool
     */
    protected static function answer_input($question): bool {
        return is_object($question)
            && self::id_ok($question->id ?? null)
            && is_string($question->questiontext ?? null) && $question->questiontext !== ''
            && self::marks_ok($question->marks ?? null)
            && self::nullish_string($question, 'existing_answer');
    }

    // Results (smartworkbookContract.ts).

    /**
     * normalizeSmartworkbookMark.
     *
     * @param int|float $questionid
     * @param int|float $maximum
     * @param mixed $value
     * @return array
     */
    public static function normalise_mark($questionid, $maximum, $value): array {
        if (
            !is_object($value) || !property_exists($value, 'mark') || !property_exists($value, 'comment')
                || !is_string($value->comment)
        ) {
            throw new \moodle_exception('byok_badmark', 'smartworkbook');
        }
        $mark = $value->mark;
        if (self::is_number($mark)) {
            $number = (float) $mark;
        } else if (is_string($mark) && self::trim($mark) !== '') {
            $number = self::jsnum(self::trim($mark));
        } else {
            throw new \moodle_exception('byok_badmark', 'smartworkbook');
        }
        if (!is_finite($number)) {
            throw new \moodle_exception('byok_badmark', 'smartworkbook');
        }
        $confidence = property_exists($value, 'confidence') ? $value->confidence : null;
        $mark = max(0, min($number, (float) $maximum));
        return ['questionid' => $questionid, 'mark' => self::num($mark), 'comment' => $value->comment,
            'confidence' => $confidence === 'high' || $confidence === 'low' ? $confidence : 'medium'];
    }

    /**
     * validateSmartworkbookConversion: null-tolerant defaults, then the zod schema.
     *
     * @param mixed $value
     * @return array [questions or null, invalid field paths]
     */
    public static function validate_conversion($value): array {
        if (is_object($value) && isset($value->questions) && is_array($value->questions)) {
            $value = clone $value;
            $value->questions = array_map(function ($question) {
                if (!is_object($question)) {
                    return $question;
                }
                $item = clone $question;
                $raw = property_exists($item, 'marks') ? $item->marks : null;
                $text = is_string($raw) ? self::trim($raw) : '';
                $qtype = property_exists($question, 'qtype') ? $question->qtype : null;
                $item->qtype = $qtype ?? 'text';
                $item->label = (property_exists($item, 'label') ? $item->label : null) ?? '';
                if ($raw === null || $text === '') {
                    $item->marks = $qtype === 'heading' ? 0 : 1;
                } else if (
                    preg_match(
                        '/^([0-9]+(?:\.[0-9]+)?)[' . self::WS . ']*(?:[mM][aA][rR][kK][sS]?)?$/Du',
                        $text,
                        $m
                    )
                ) {
                    $item->marks = self::num((float) $m[1]);
                }
                $item->model_answer = (property_exists($item, 'model_answer') ? $item->model_answer : null) ?? '';
                $item->rubric_notes = (property_exists($item, 'rubric_notes') ? $item->rubric_notes : null) ?? '';
                return $item;
            }, $value->questions);
        }
        $issues = [];
        $questions = [];
        if (!is_object($value)) {
            $issues[] = '$';
        } else if (!property_exists($value, 'questions') || !is_array($value->questions)) {
            $issues[] = 'questions';
        } else if (!$value->questions) {
            $issues[] = 'questions';
        } else {
            foreach ($value->questions as $i => $q) {
                $path = 'questions.' . $i;
                if (!is_object($q)) {
                    $issues[] = $path;
                    continue;
                }
                $out = [];
                $qtype = property_exists($q, 'qtype') ? $q->qtype : 'text';
                if (!is_string($qtype) || !in_array($qtype, self::QTYPES, true)) {
                    $issues[] = $path . '.qtype';
                }
                $out['qtype'] = $qtype;
                foreach (
                    ['label' => '', 'questiontext' => null, 'marks' => 1, 'model_answer' => '',
                        'rubric_notes' => ''] as $key => $default
                ) {
                    $v = property_exists($q, $key) ? $q->$key : $default;
                    if ($key === 'marks') {
                        if (!self::marks_ok($v)) {
                            $issues[] = $path . '.marks';
                        }
                    } else if (!is_string($v)) {
                        $issues[] = $path . '.' . $key;
                    } else if ($key === 'questiontext') {
                        $v = self::trim($v);
                        if ($v === '') {
                            $issues[] = $path . '.questiontext';
                        }
                    }
                    $out[$key] = $v;
                }
                $questions[] = $out;
            }
        }
        if ($issues) {
            return [null, array_slice(array_values(array_unique($issues)), 0, 8)];
        }
        return [$questions, []];
    }

    /**
     * applyExplicitSmartworkbookMarks: a mark written next to the question in the workbook wins.
     *
     * @param array $questions
     * @param string $source The workbook text the AI read.
     * @return array
     */
    public static function explicit_marks(array $questions, string $source): array {
        $lines = preg_split('/\r?\n/', $source);
        $normalised = array_map([self::class, 'normalised'], $lines);
        $b = '(?:(?<=' . self::WORD . ')(?!' . self::WORD . ')|(?<!' . self::WORD . ')(?=' . self::WORD . '))';
        $ws = '[' . self::WS . ']*';
        $q = '(?:[qQ][uU][eE][sS][tT][iI][oO][nN]|[qQ])';
        $numbered = '/' . $b . $q . $ws . '([0-9]+[a-zA-Z]?)' . $b . '/u';
        $markvalue = function (string $line) use ($b, $ws): ?float {
            $unit = '(?:[mM][aA][rR][kK][sS]?|[pP][oO][iI][nN][tT][sS]?|[pP][tT][sS]?)';
            if (preg_match('/' . $b . '([0-9]+(?:\.[0-9]+)?)' . $ws . $unit . $b . '/u', $line, $m)) {
                return (float) $m[1];
            }
            return null;
        };
        foreach ($questions as $index => $question) {
            if ($question['qtype'] === 'heading') {
                $questions[$index]['marks'] = 0;
                continue;
            }
            $label = self::normalised($question['label']);
            $number = preg_match('/' . $q . $ws . '([0-9]+[a-zA-Z]?)/u', $question['label'], $m) ? strtolower($m[1]) : null;
            $text = self::normalised($question['questiontext']);
            $matching = [];
            foreach ($lines as $i => $line) {
                $linenumber = preg_match($numbered, $line, $m) ? strtolower($m[1]) : null;
                $labelmatches = $label !== '' && strpos(' ' . $normalised[$i] . ' ', ' ' . $label . ' ') !== false;
                $numbermatches = $number !== null && $linenumber === $number;
                $textmatches = self::len($text) >= 12 && strpos($normalised[$i], $text) !== false;
                if ($labelmatches || $numbermatches || $textmatches) {
                    $matching[] = $i;
                }
            }
            foreach ($matching as $i) {
                $same = $markvalue($lines[$i]);
                if ($same !== null) {
                    $questions[$index]['marks'] = self::num($same);
                    continue 2;
                }
                for ($offset = 1; $offset <= 2 && $i + $offset < count($lines); $offset++) {
                    if (preg_match($numbered, $lines[$i + $offset])) {
                        break;
                    }
                    $following = $markvalue($lines[$i + $offset]);
                    if ($following !== null) {
                        $questions[$index]['marks'] = self::num($following);
                        continue 3;
                    }
                }
            }
        }
        return $questions;
    }

    /**
     * Lower case, letters and numbers only, single spaces.
     *
     * @param string $value
     * @return string
     */
    protected static function normalised(string $value): string {
        return self::trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value, 'UTF-8')));
    }

    // JavaScript helpers.

    /**
     * JSON.parse with objects kept as objects; false when it would throw.
     *
     * @param string $text
     * @return mixed
     */
    protected static function parse(string $text) {
        if (self::trim($text) === '') {
            return false;
        }
        try {
            return json_decode($text, false, 100000, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return false;
        }
    }

    /**
     * String.prototype.trim.
     *
     * @param string $s
     * @return string
     */
    public static function trim(string $s): string {
        return preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/u', '', $s);
    }

    /**
     * A string's length in UTF-16 code units.
     *
     * @param string $s
     * @return int
     */
    protected static function len(string $s): int {
        return (int) (strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2);
    }

    /**
     * String.prototype.substring in UTF-16 code units; a split surrogate pair becomes U+FFFD.
     *
     * @param string $s
     * @param int $start
     * @param int $end
     * @return string
     */
    protected static function slice(string $s, int $start, int $end): string {
        $u = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $units = (int) (strlen($u) / 2);
        $end = min($end, $units);
        if ($end <= $start) {
            return '';
        }
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $out = mb_convert_encoding(substr($u, $start * 2, ($end - $start) * 2), 'UTF-8', 'UTF-16LE');
        mb_substitute_character($previous);
        return $out;
    }

    /**
     * Number(string) for a trimmed, non-empty string.
     *
     * @param string $s
     * @return float
     */
    protected static function jsnum(string $s): float {
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/D', $s, $m)) {
            return (float) hexdec($m[1]);
        }
        if (preg_match('/^0[oO]([0-7]+)$/D', $s, $m)) {
            return (float) octdec($m[1]);
        }
        if (preg_match('/^0[bB]([01]+)$/D', $s, $m)) {
            return (float) bindec($m[1]);
        }
        if (preg_match('/^[+-]?Infinity$/D', $s)) {
            return $s[0] === '-' ? -INF : INF;
        }
        if (preg_match('/^[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $s)) {
            return (float) $s;
        }
        return NAN;
    }

    /**
     * A whole float as an int, so it reads like a JavaScript number.
     *
     * @param int|float $n
     * @return int|float
     */
    protected static function num($n) {
        if (is_float($n) && is_finite($n) && floor($n) == $n && abs($n) < 1e15) {
            return (int) $n;
        }
        return $n;
    }

    /**
     * String(number) as JavaScript writes it.
     *
     * @param int|float $n
     * @return string
     */
    public static function numstr($n): string {
        if (is_int($n)) {
            return (string) $n;
        }
        if (is_nan($n)) {
            return 'NaN';
        }
        if (is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }
        if ($n == 0) {
            return '0';
        }
        // Shortest digits that read back the same, then JavaScript's Number::toString layout.
        for ($precision = 1; $precision <= 17; $precision++) {
            $e = sprintf('%.' . ($precision - 1) . 'e', abs($n));
            if ((float) $e === abs($n)) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $e);
        $digits = str_replace('.', '', $mantissa);
        $digits = rtrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;
        $k = strlen($digits);
        $point = (int) $exponent + 1;
        $sign = $n < 0 ? '-' : '';
        if ($k <= $point && $point <= 21) {
            return $sign . $digits . str_repeat('0', $point - $k);
        }
        if (0 < $point && $point <= 21) {
            return $sign . substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        if (-6 < $point && $point <= 0) {
            return $sign . '0.' . str_repeat('0', -$point) . $digits;
        }
        $exp = $point - 1;
        $tail = $exp >= 0 ? '+' . $exp : (string) $exp;
        return $sign . ($k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1)) . 'e' . $tail;
    }
}
