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

namespace mod_smartworkbook;

use mod_smartworkbook\local\byok\docx_text;
use mod_smartworkbook\local\byok\engine;

/**
 * Tests for converting workbooks, AI marking and model answers on the site's own AI.
 *
 * local\byok\docx_text was compared with mammoth (the service's Word reader) on more than 30,000 generated Word
 * files, and local\byok\engine with the service routes on more than 40,000 randomised requests and AI answers,
 * with identical results; these tests cover the wiring into the plugin.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_smartworkbook\byok
 * @covers     \mod_smartworkbook\local\byok\engine
 * @covers     \mod_smartworkbook\local\byok\docx_text
 * @covers     \mod_smartworkbook\local\byok\dingbats
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(docx_text::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\byok\dingbats::class)]
final class byok_test extends \advanced_testcase {
    /** @var array Requests the mocked text provider received (decoded bodies). */
    protected $requests = [];
    /** @var array Text answers the mocked provider gives, in order (null: the provider fails). */
    protected $answers = [];

    /** @var string The service's text for tests/fixtures/workbook.docx (mammoth 1.11 plus the service's clean-up). */
    const WORKBOOK_TEXT = "# Section A: Food safety\nQuestion 1: Explain why food must be kept out of the temperature "
        . "danger zone. (3 marks)\nQuestion 2: List two personal hygiene practices.\n2 marks\n\n| Task\n | Answer\n |  |\n"
        . "| Q3 Name the danger zone range\n |  |  |\n- Wash hands & dry them\n- Tie back long hair";

    /**
     * Licence the site for Bring your own AI on a test key, with text on its own Anthropic key and no LMS Labs
     * credentials.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config with Bring your own AI is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        set_config('src_text', 'ownkey', 'local_aiconfig');
        set_config('vendor_text', 'anthropic', 'local_aiconfig');
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
        set_config('siteid', '', 'local_aiconfig');
        set_config('apikey', '', 'local_aiconfig');
        $this->requests = [];
        $this->answers = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body, $vendor, $raw = false) {
            $this->requests[] = is_string($body) ? json_decode($body, true) : $body;
            $answer = array_shift($this->answers);
            if ($answer === null) {
                throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object) ['vendor' => $vendor,
                    'detail' => 'Overloaded']);
            }
            return ['content' => [['type' => 'text', 'text' => $answer]], 'stop_reason' => 'end_turn'];
        };
    }

    /**
     * Clean up the mock.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        engine::$aicall = null;
        parent::tearDown();
    }

    /**
     * The workbook fixture.
     *
     * @return string
     */
    protected function workbook(): string {
        return file_get_contents(__DIR__ . '/fixtures/workbook.docx');
    }

    /**
     * Everything one mocked request sent, as text.
     *
     * @param int $index
     * @return string
     */
    protected function sent(int $index): string {
        return json_encode($this->requests[$index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A Word workbook reads exactly as the service reads it: headings, table rows, list items.
     */
    public function test_word_text_matches_service(): void {
        $this->assertSame(self::WORKBOOK_TEXT, docx_text::text($this->workbook()));
    }

    /**
     * Word conversion runs on the site's own AI with the service's instructions, and marks written in the
     * workbook win over the AI's (as the service does).
     */
    public function test_convert_word_on_own_ai(): void {
        $this->assertTrue(byok::active());
        $this->assertTrue(byok::handles('convert', 'Food safety.DOCX'));
        $this->assertFalse(byok::handles('convert', 'Food safety.pdf'));
        $this->assertTrue(byok::handles('mark'));
        $this->answers[] = json_encode(['questions' => [
            ['qtype' => 'heading', 'label' => '', 'questiontext' => 'Section A: Food safety', 'marks' => null,
                'model_answer' => '', 'rubric_notes' => ''],
            ['qtype' => 'text', 'label' => 'Question 1', 'marks' => 1,
                'questiontext' => 'Explain why food must be kept out of the temperature danger zone.',
                'model_answer' => 'Bacteria grow fast between 5 and 60 °C.', 'rubric_notes' => ''],
            ['qtype' => 'long', 'label' => 'Question 2', 'questiontext' => 'List two personal hygiene practices.',
                'marks' => null, 'model_answer' => null, 'rubric_notes' => ''],
            ['qtype' => 'text', 'label' => 'Q3', 'questiontext' => '  Name the danger zone range ', 'marks' => '1 mark'],
        ]]);
        $result = byok::run('convert', ['site_id' => '', 'api_key' => '', 'filename' => 'Food safety.docx',
            'filecontent' => chunk_split(base64_encode($this->workbook()), 76, "\n"), 'extract_instructions' => true]);
        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['count']);
        $this->assertSame([0, 3, 2, 1], array_column($result['questions'], 'marks'));
        $this->assertSame('Name the danger zone range', $result['questions'][3]['questiontext']);
        $this->assertSame('', $result['questions'][2]['model_answer']);
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('You extract structured question data', $this->sent(0));
        $this->assertStringContainsString("Workbook content:\\n# Section A: Food safety\\nQuestion 1:", $this->sent(0));
        $this->assertStringContainsString('Mark handling: Preserve any explicit mark value', $this->sent(0));
    }

    /**
     * Conversion errors read like the service's.
     */
    public function test_convert_errors(): void {
        $result = byok::run('convert', ['filename' => 'broken.docx', 'filecontent' => base64_encode('not a zip')]);
        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('Could not read text from the Word document.', $result['error']);
        $this->assertCount(0, $this->requests);

        $this->answers[] = '{"questions": []}';
        $result = byok::run('convert', ['filename' => 'w.docx', 'filecontent' => base64_encode($this->workbook())]);
        $this->assertSame('The AI response did not contain a valid, non-empty list of workbook questions. ' .
            'Invalid fields: questions. Please retry.', $result['error']);

        $this->answers[] = null;
        $result = byok::run('convert', ['filename' => 'w.docx', 'filecontent' => base64_encode($this->workbook())]);
        $this->assertStringStartsWith('The AI service could not process this workbook.', $result['error']);
        $this->assertStringContainsString('Your AI service said:', $result['error']);

        $result = byok::run('convert', ['filename' => 'w.docx', 'filecontent' => 'not base64!']);
        $this->assertSame('The uploaded document data is not valid Base64.', $result['error']);
    }

    /**
     * Marking: a blank answer scores 0 without a call; the AI's mark is capped at the question's marks.
     */
    public function test_mark_on_own_ai(): void {
        $this->answers[] = '{"mark": " 7 ", "comment": "Covers both practices.", "confidence": "HIGH"}';
        $result = byok::run('mark', ['qa_pairs' => [
            ['questionid' => 11, 'questiontext' => 'Name the danger zone range', 'model_answer' => '',
                'rubric_notes' => '', 'marks' => 1.0, 'student_answer' => "  \n"],
            ['questionid' => 12, 'questiontext' => 'List two personal hygiene practices.', 'model_answer' => 'Wash hands',
                'rubric_notes' => '', 'marks' => 2.0, 'student_answer' => 'Wash hands and tie back hair'],
        ]]);
        $this->assertTrue($result['success']);
        $this->assertEquals(
            ['questionid' => 11, 'mark' => 0, 'comment' => 'No answer provided.', 'confidence' => 'high'],
            $result['marks'][0]
        );
        $this->assertEquals(['questionid' => 12, 'mark' => 2, 'comment' => 'Covers both practices.',
            'confidence' => 'medium'], $result['marks'][1]);
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('Model answer: Wash hands\\nRubric: Award marks for accuracy and completeness.' .
            '\\nMax marks: 2\\nStudent answer: Wash hands and tie back hair', $this->sent(0));
    }

    /**
     * Marking fails as a whole when the AI answer cannot be read, like the service.
     */
    public function test_mark_failure(): void {
        $this->answers[] = 'I would give this 2 marks.';
        $result = byok::run('mark', ['qa_pairs' => [['questionid' => 1, 'questiontext' => 'Q', 'marks' => 2.0,
            'student_answer' => 'A', 'model_answer' => '', 'rubric_notes' => '']]]);
        $this->assertSame(['success' => false,
            'error' => 'Workbook marking failed. No completed marking result was returned.'], $result);
        $result = byok::run('mark', ['qa_pairs' => [['questionid' => 1, 'questiontext' => '', 'marks' => 2.0,
            'student_answer' => 'A']]]);
        $this->assertSame('Invalid marking request', $result['error']);
    }

    /**
     * Model answers: an existing answer longer than 20 characters is kept; others are written by the AI.
     */
    public function test_model_answers_on_own_ai(): void {
        $this->answers[] = "\n  Keep food below 5 °C or above 60 °C.  \n";
        $result = byok::run('answers', ['questions' => [
            ['id' => 5, 'questiontext' => 'Explain the danger zone.', 'marks' => 3.0,
                'existing_answer' => 'Between 5 °C and 60 °C bacteria multiply quickly.'],
            ['id' => 6, 'questiontext' => 'How do you keep food safe?', 'marks' => 2.5, 'existing_answer' => 'Short'],
        ]]);
        $this->assertTrue($result['success']);
        $this->assertSame([
            ['id' => 5, 'model_answer' => 'Between 5 °C and 60 °C bacteria multiply quickly.'],
            ['id' => 6, 'model_answer' => 'Keep food below 5 °C or above 60 °C.'],
        ], $result['answers']);
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('Max marks: 2.5', $this->sent(0));
    }

    /**
     * Without a licence everything stays with LMS Labs.
     */
    public function test_inactive_without_licence(): void {
        set_config('licencekey', '', 'local_aiconfig');
        $this->assertFalse(byok::active());
        $this->assertFalse(byok::handles('mark'));
        $this->assertFalse(byok::handles('convert', 'w.docx'));
    }

    /**
     * JavaScript number formatting used in the prompts.
     */
    public function test_number_text(): void {
        $this->assertSame('2', engine::numstr(2.0));
        $this->assertSame('0.1', engine::numstr(0.1));
        $this->assertSame('1e-7', engine::numstr(1e-7));
        $this->assertSame('1e+21', engine::numstr(1e21));
        $this->assertSame('123456789012', engine::numstr(123456789012.0));
        $this->assertSame('0.30000000000000004', engine::numstr(0.1 + 0.2));
    }
}
