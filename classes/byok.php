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

use mod_smartworkbook\local\byok\engine;

/**
 * Bring your own AI: runs the Smart Workbook AI steps on this site's own AI instead of LMS Labs credits.
 *
 * When AI Grader Central Config (local_aiconfig) has a Bring your own AI licence and its Text service is set to
 * Moodle AI or the site's own key, Word conversion, AI marking and model answers run here with the service's
 * own prompts and checks ({@see engine}). PDF workbooks still go to LMS Labs, which reads them with its PDF parser.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok {
    /** @var string */
    const COMPONENT = 'mod_smartworkbook';

    /** @var string|null What the site's AI said when the last call failed. */
    protected static $lasterror = null;

    /**
     * Whether the site's own AI writes this plugin's text.
     *
     * @return bool
     */
    public static function active(): bool {
        return class_exists('\local_aiconfig\byok\ai')
            && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, \local_aiconfig\byok\ai::TEXT);
    }

    /**
     * Whether a request runs here: always for marking and model answers, for conversion only with a Word file.
     *
     * @param string $route convert, mark or answers
     * @param string|null $filename The uploaded workbook, for convert.
     * @return bool
     */
    public static function handles(string $route, ?string $filename = null): bool {
        if (!self::active()) {
            return false;
        }
        if ($route !== 'convert') {
            return true;
        }
        $dot = strrpos((string) $filename, '.');
        return $dot !== false && \core_text::strtolower(substr((string) $filename, $dot + 1)) === 'docx';
    }

    /**
     * Runs a request here and answers like the service would.
     *
     * @param string $route convert, mark or answers
     * @param array $payload The body the plugin would post to LMS Labs.
     * @return array The decoded answer (success, and questions / marks / answers or error).
     */
    public static function run(string $route, array $payload): array {
        \core_php_time_limit::raise(600);
        self::$lasterror = null;
        engine::$aicall = function (string $system, string $user, int $maxtokens, bool $json): string {
            try {
                return \local_aiconfig\byok\ai::text(
                    self::COMPONENT,
                    $system,
                    $user,
                    ['maxtokens' => $maxtokens, 'json' => $json]
                );
            } catch (\moodle_exception $e) {
                self::$lasterror = $e->getMessage();
                throw $e;
            }
        };
        // Through JSON, so the engine sees exactly the types the service would.
        $body = json_decode(json_encode($payload, JSON_PRESERVE_ZERO_FRACTION));
        if ($route === 'convert') {
            [, $answer] = engine::convert($body);
        } else if ($route === 'mark') {
            [, $answer] = engine::mark($body);
        } else {
            [, $answer] = engine::answers($body);
        }
        $answer = json_decode(json_encode($answer), true);
        if (empty($answer['success']) && self::$lasterror !== null) {
            $answer['error'] .= ' ' . get_string('byok_said', 'smartworkbook', self::$lasterror);
        }
        return $answer;
    }
}
