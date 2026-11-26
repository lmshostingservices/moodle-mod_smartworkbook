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
 * Reads a Word workbook exactly as the LMS Labs service does before it asks the AI for questions.
 *
 * The service runs mammoth 1.11 convertToHtml with its default style map, then turns that HTML into
 * pipe-separated table rows and markdown headings. This is a port of the parts of mammoth that decide
 * that text (document reading, numbering, styles, notes, the HTML tree, simplify and the writer); inline
 * formatting tags are left out because the service strips every tag. Checked against mammoth itself on
 * thousands of generated documents. Known differences: a style map embedded in the file by mammoth's
 * own tooling (mammoth/style-map) is not read, and broken XML is refused by libxml where xmldom may differ.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docx_text {
    /** @var array Namespace URIs mammoth names with a prefix (office-xml-reader.js). */
    const NAMESPACES = [
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main' => 'w',
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships' => 'r',
        'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing' => 'wp',
        'http://schemas.openxmlformats.org/drawingml/2006/main' => 'a',
        'http://schemas.openxmlformats.org/drawingml/2006/picture' => 'pic',
        'http://purl.oclc.org/ooxml/wordprocessingml/main' => 'w',
        'http://purl.oclc.org/ooxml/officeDocument/relationships' => 'r',
        'http://purl.oclc.org/ooxml/drawingml/wordprocessingDrawing' => 'wp',
        'http://purl.oclc.org/ooxml/drawingml/main' => 'a',
        'http://purl.oclc.org/ooxml/drawingml/picture' => 'pic',
        'http://schemas.openxmlformats.org/package/2006/content-types' => 'content-types',
        'http://schemas.openxmlformats.org/package/2006/relationships' => 'relationships',
        'http://schemas.openxmlformats.org/markup-compatibility/2006' => 'mc',
        'urn:schemas-microsoft-com:vml' => 'v',
        'urn:schemas-microsoft-com:office:word' => 'office-word',
        'http://schemas.microsoft.com/office/word/2010/wordml' => 'wordml',
    ];

    /** @var string JavaScript's whitespace (String.prototype.trim and \s). */
    const WS = '\x{9}-\x{d}\x{20}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';

    /** @var string The relationship type prefix of the parts next to the main document. */
    const RELTYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    /** @var \ZipArchive */
    protected $zip;

    /** @var bool Whether the package has a styles part (without one mammoth cannot look up table styles). */
    protected $hasstyles = false;

    /** @var array Paragraph styles: id => ['name' => ?string]. */
    protected $pstyles = [];

    /** @var array Numbering styles: id => numId. */
    protected $numstyles = [];

    /** @var array w:num: numId => abstractNumId. */
    protected $nums = [];

    /** @var array w:abstractNum: id => ['levels' => [index => level], 'link' => ?string]. */
    protected $abstracts = [];

    /** @var array Paragraph style id => numbering level. */
    protected $levelsbystyle = [];

    /** @var array Footnotes and endnotes: "type-id" => note. */
    protected $notes = [];

    // Body reader state (body-reader.js BodyReader); a new reader starts for each note and the body.
    /** @var array */
    protected $fields = [];
    /** @var array */
    protected $instr = [];
    /** @var array */
    protected $deleted = [];
    /** @var array Relationship id => target for the part being read. */
    protected $rels = [];

    // Conversion state (document-to-html.js).
    /** @var int */
    protected $notenumber = 1;
    /** @var array */
    protected $notereferences = [];

    /**
     * The service's workbook text for a Word file, before its 24,000 character cut.
     *
     * @param string $bytes The .docx file.
     * @return string
     * @throws \moodle_exception When mammoth would fail to read it (the service answers DOCX_EXTRACTION_FAILED).
     */
    public static function text(string $bytes): string {
        return self::flatten(self::html($bytes));
    }

    /**
     * mammoth.convertToHtml({buffer}).value.
     *
     * @param string $bytes
     * @return string
     */
    public static function html(string $bytes): string {
        $reader = new self();
        $dir = make_request_directory();
        $path = $dir . '/workbook.docx';
        file_put_contents($path, $bytes);
        $reader->zip = new \ZipArchive();
        if ($reader->zip->open($path, \ZipArchive::RDONLY) !== true) {
            self::fail('zip');
        }
        try {
            return $reader->convert();
        } finally {
            $reader->zip->close();
            @unlink($path);
        }
    }

    /**
     * The service's own clean-up of mammoth's HTML (routes.ts, /api/smartworkbook/convert).
     *
     * @param string $html
     * @return string
     */
    public static function flatten(string $html): string {
        $steps = [
            '/<tr[^>]*>/i' => "\n| ", '/<\/tr>/i' => ' |', '/<t[hd][^>]*>/i' => '', '/<\/t[hd]>/i' => ' | ',
            '/<h1[^>]*>/i' => "\n# ", '/<\/h1>/i' => "\n", '/<h2[^>]*>/i' => "\n## ", '/<\/h2>/i' => "\n",
            '/<h3[^>]*>/i' => "\n### ", '/<\/h3>/i' => "\n", '/<h[456][^>]*>/i' => "\n#### ", '/<\/h[456]>/i' => "\n",
            '/<\/p>/i' => "\n", '/<p[^>]*>/i' => '', '/<br\s*\/?>/i' => "\n", '/<li[^>]*>/i' => "\n- ", '/<\/li>/i' => '',
            '/<[^>]+>/' => '', '/&amp;/' => '&', '/&lt;/' => '<', '/&gt;/' => '>', '/&nbsp;/' => ' ', '/&#\d+;/' => ' ',
            '/\n{4,}/' => "\n\n\n",
        ];
        foreach ($steps as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html);
        }
        return preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/u', '', $html);
    }

    /**
     * A failure the service reports as DOCX_EXTRACTION_FAILED.
     *
     * @param string $why
     * @return never
     */
    protected static function fail(string $why) {
        throw new \moodle_exception('byok_docx', 'smartworkbook', '', null, $why);
    }

    // Reading the package (docx-reader.js).

    /**
     * Reads every part and converts the document.
     *
     * @return string
     */
    protected function convert(): string {
        $content = $this->xml('[Content_Types].xml');
        if ($content) {
            foreach ($content->children as $child) {
                if (($child->name ?? null) === 'content-types:Override' && !isset($child->attributes['PartName'])) {
                    self::fail('content type override without a part name');
                }
            }
        }
        $main = $this->part(
            $this->relationships('_rels/.rels'),
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument',
            '',
            'word/document.xml'
        );
        if (!$this->exists($main)) {
            self::fail('no main document');
        }
        $docrels = $this->relationships(self::relsname($main));
        $base = self::split($main)[0];
        $parts = [];
        foreach (['comments', 'endnotes', 'footnotes', 'numbering', 'styles'] as $name) {
            $parts[$name] = $this->part($docrels, self::RELTYPE . $name, $base, 'word/' . $name . '.xml');
        }
        $this->read_styles($this->xml($parts['styles']));
        $this->read_numbering($this->xml($parts['numbering']));
        foreach (['footnote', 'endnote'] as $type) {
            $root = $this->body_part($parts[$type . 's']);
            if ($root) {
                foreach (self::children_named($root, 'w:' . $type) as $element) {
                    $kind = $element->attributes['w:type'] ?? null;
                    if ($kind === 'continuationSeparator' || $kind === 'separator') {
                        continue;
                    }
                    $this->reset();
                    $key = $type . '-' . self::key($element->attributes['w:id'] ?? null);
                    // Later notes with the same id win (underscore indexBy).
                    $this->notes[$key] = ['type' => 'note', 'noteType' => $type,
                        'noteId' => $element->attributes['w:id'] ?? null,
                        'body' => $this->read_all($element->children)[0]];
                }
            }
        }
        $root = $this->body_part($parts['comments']);
        if ($root) {
            foreach (self::children_named($root, 'w:comment') as $element) {
                $this->reset();
                $this->read_all($element->children);
            }
        }
        $root = $this->body_part($main);
        $body = $root ? self::first($root, 'w:body') : null;
        if (!$body) {
            self::fail('no body');
        }
        $this->reset();
        $children = $this->read_all($body->children)[0];
        return $this->document($children);
    }

    /**
     * Reads a part that holds body content, with its own relationships.
     *
     * @param string $path
     * @return \stdClass|null
     */
    protected function body_part(string $path): ?\stdClass {
        $this->rels = $this->relationships(self::relsname($path))['ids'];
        return $this->xml($path);
    }

    /**
     * Starts a new body reader.
     */
    protected function reset(): void {
        $this->fields = [];
        $this->instr = [];
        $this->deleted = [];
    }

    /**
     * Whether the package holds a file.
     *
     * @param string $name
     * @return bool
     */
    protected function exists(string $name): bool {
        if ($name === '' || substr($name, -1) === '/') {
            return false;
        }
        return $this->zip->locateName($name) !== false;
    }

    /**
     * Parses one XML part, or null when it is missing (office-xml-reader.js readXmlFromZipFile).
     *
     * @param string $path
     * @return \stdClass|null
     */
    protected function xml(string $path): ?\stdClass {
        if (!$this->exists($path)) {
            return null;
        }
        $raw = $this->zip->getFromName($path);
        if ($raw === false) {
            self::fail('unreadable part');
        }
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $text = mb_scrub($raw, 'UTF-8');
        mb_substitute_character($previous);
        // TextDecoder drops one byte order mark and stripUtf8Bom another.
        for ($i = 0; $i < 2 && strncmp($text, "\xEF\xBB\xBF", 3) === 0; $i++) {
            $text = substr($text, 3);
        }
        $dom = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $text !== '' && $dom->loadXML($text, LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_COMPACT);
        $failed = !$ok || libxml_get_errors() || !$dom->documentElement;
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        if ($failed) {
            self::fail('xml');
        }
        $root = self::node($dom->documentElement);
        $collapsed = self::collapse_alternate($root);
        return $collapsed[0] ?? null;
    }

    /**
     * Converts a DOM node to mammoth's XML node (xml/reader.js).
     *
     * @param \DOMNode $node
     * @return \stdClass|null
     */
    protected static function node(\DOMNode $node): ?\stdClass {
        if ($node instanceof \DOMElement) {
            $children = [];
            foreach ($node->childNodes as $child) {
                $converted = self::node($child);
                if ($converted) {
                    $children[] = $converted;
                }
            }
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[self::name($attribute)] = $attribute->value;
            }
            return (object) ['type' => 'element', 'name' => self::name($node), 'attributes' => $attributes,
                'children' => $children];
        }
        if ($node->nodeType === XML_TEXT_NODE) {
            return (object) ['type' => 'text', 'value' => $node->nodeValue];
        }
        return null;
    }

    /**
     * A node's mammoth name: mapped prefix, {uri} or the bare local name.
     *
     * @param \DOMNode $node
     * @return string
     */
    protected static function name(\DOMNode $node): string {
        $uri = $node->namespaceURI;
        if ($uri !== null && $uri !== '') {
            $prefix = self::NAMESPACES[$uri] ?? null;
            return ($prefix !== null ? $prefix . ':' : '{' . $uri . '}') . $node->localName;
        }
        return $node->localName;
    }

    /**
     * Replaces mc:AlternateContent with its fallback's children (not looked into again).
     *
     * @param \stdClass $node
     * @return array
     */
    protected static function collapse_alternate(\stdClass $node): array {
        if ($node->type !== 'element') {
            return [$node];
        }
        if ($node->name === 'mc:AlternateContent') {
            return self::first_or_empty($node, 'mc:Fallback')->children;
        }
        $children = [];
        foreach ($node->children as $child) {
            foreach (self::collapse_alternate($child) as $item) {
                $children[] = $item;
            }
        }
        $node->children = $children;
        return [$node];
    }

    /**
     * Reads a relationships part (relationships-reader.js).
     *
     * @param string $path
     * @return array ['ids' => [id => target], 'types' => [type => [targets]]]
     */
    protected function relationships(string $path): array {
        $result = ['ids' => [], 'types' => []];
        $root = $this->xml($path);
        if (!$root) {
            return $result;
        }
        foreach ($root->children as $child) {
            if (($child->name ?? null) === 'relationships:Relationship') {
                $target = $child->attributes['Target'] ?? null;
                $result['ids'][self::key($child->attributes['Id'] ?? null)] = $target;
                $result['types'][self::key($child->attributes['Type'] ?? null)][] = $target;
            }
        }
        return $result;
    }

    /**
     * The first related part that exists, else the usual path (findPartPath).
     *
     * @param array $relationships
     * @param string $type
     * @param string $base
     * @param string $fallback
     * @return string
     */
    protected function part(array $relationships, string $type, string $base, string $fallback): string {
        foreach ($relationships['types'][$type] ?? [] as $target) {
            $path = self::join([$base, $target]);
            if (strncmp($path, '/', 1) === 0) {
                $path = substr($path, 1);
            }
            if ($this->exists($path)) {
                return $path;
            }
        }
        return $fallback;
    }

    /**
     * zipfile.joinPath: empty parts dropped, an absolute part restarts the path.
     *
     * @param array $paths
     * @return string
     */
    protected static function join(array $paths): string {
        $relevant = [];
        foreach ($paths as $path) {
            if ($path === null || $path === '') {
                continue;
            }
            if ($path[0] === '/') {
                $relevant = [$path];
            } else {
                $relevant[] = $path;
            }
        }
        return implode('/', $relevant);
    }

    /**
     * zipfile.splitPath.
     *
     * @param string $path
     * @return array [dirname, basename]
     */
    protected static function split(string $path): array {
        $last = strrpos($path, '/');
        return $last === false ? ['', $path] : [substr($path, 0, $last), substr($path, $last + 1)];
    }

    /**
     * The relationships part of a part.
     *
     * @param string $path
     * @return string
     */
    protected static function relsname(string $path): string {
        [$dir, $base] = self::split($path);
        return self::join([$dir, '_rels', $base . '.rels']);
    }

    /**
     * A JavaScript object key for a value that may be missing.
     *
     * @param mixed $value
     * @return string
     */
    protected static function key($value): string {
        return $value === null ? 'undefined' : (string) $value;
    }

    // Styles and numbering (styles-reader.js, numbering-xml.js).

    /**
     * Reads styles.xml.
     *
     * @param \stdClass|null $root
     */
    protected function read_styles(?\stdClass $root): void {
        if (!$root) {
            return;
        }
        $this->hasstyles = true;
        foreach (self::children_named($root, 'w:style') as $style) {
            $type = $style->attributes['w:type'] ?? null;
            $id = self::key($style->attributes['w:styleId'] ?? null);
            if ($type === 'numbering') {
                if (!array_key_exists($id, $this->numstyles)) {
                    $numpr = self::first_or_empty(self::first_or_empty($style, 'w:pPr'), 'w:numPr');
                    $this->numstyles[$id] = self::first_or_empty($numpr, 'w:numId')->attributes['w:val'] ?? null;
                }
            } else if ($type === 'paragraph' && !array_key_exists($id, $this->pstyles)) {
                $name = self::first($style, 'w:name');
                $this->pstyles[$id] = ['name' => $name ? ($name->attributes['w:val'] ?? null) : null];
            }
        }
    }

    /**
     * Reads numbering.xml.
     *
     * @param \stdClass|null $root
     */
    protected function read_numbering(?\stdClass $root): void {
        if (!$root) {
            return;
        }
        foreach (self::children_named($root, 'w:abstractNum') as $element) {
            $levels = [];
            $withoutindex = null;
            foreach (self::children_named($element, 'w:lvl') as $lvl) {
                $index = $lvl->attributes['w:ilvl'] ?? null;
                $level = [
                    'isOrdered' => (self::first_or_empty($lvl, 'w:numFmt')->attributes['w:val'] ?? null) !== 'bullet',
                    'level' => $index ?? '0',
                    'paragraphStyleId' => self::first_or_empty($lvl, 'w:pStyle')->attributes['w:val'] ?? null,
                ];
                if ($index === null) {
                    $withoutindex = $level;
                } else {
                    self::js_set($levels, $index, $level);
                }
            }
            if ($withoutindex !== null && !array_key_exists('0', $levels)) {
                self::js_set($levels, '0', $withoutindex);
            }
            $link = self::first_or_empty($element, 'w:numStyleLink')->attributes['w:val'] ?? null;
            self::js_set(
                $this->abstracts,
                self::key($element->attributes['w:abstractNumId'] ?? null),
                ['levels' => $levels, 'link' => $link]
            );
        }
        foreach (self::children_named($root, 'w:num') as $element) {
            $abstract = self::first($element, 'w:abstractNumId');
            if (!$abstract) {
                self::fail('w:num without w:abstractNumId');
            }
            self::js_set(
                $this->nums,
                self::key($element->attributes['w:numId'] ?? null),
                $abstract->attributes['w:val'] ?? null
            );
        }
        foreach (self::js_values($this->abstracts) as $abstract) {
            foreach (self::js_values($abstract['levels']) as $level) {
                if ($level['paragraphStyleId'] !== null) {
                    $this->levelsbystyle[$level['paragraphStyleId']] = $level;
                }
            }
        }
    }

    /**
     * numbering.findLevel.
     *
     * @param mixed $numid
     * @param string $level
     * @param int $depth
     * @return array|null
     */
    protected function find_level($numid, string $level, int $depth = 0): ?array {
        if ($depth > 1000) {
            self::fail('numbering style loop');
        }
        $key = self::key($numid);
        if (!array_key_exists($key, $this->nums)) {
            return null;
        }
        $abstractid = self::key($this->nums[$key]);
        if (!array_key_exists($abstractid, $this->abstracts)) {
            return null;
        }
        $abstract = $this->abstracts[$abstractid];
        if ($abstract['link'] === null) {
            return $abstract['levels'][$level] ?? null;
        }
        $link = self::key($abstract['link']);
        if (!array_key_exists($link, $this->numstyles)) {
            self::fail('missing numbering style');
        }
        return $this->find_level($this->numstyles[$link], $level, $depth + 1);
    }

    /**
     * Sets a key keeping JavaScript's own key order rules for later value lists.
     *
     * @param array $map
     * @param string $key
     * @param mixed $value
     */
    protected static function js_set(array &$map, string $key, $value): void {
        $map[$key] = $value;
    }

    /**
     * Object.values order: array-index keys ascending, then other keys as inserted.
     *
     * @param array $map
     * @return array
     */
    protected static function js_values(array $map): array {
        $indexes = [];
        $others = [];
        foreach ($map as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^(0|[1-9][0-9]*)$/D', $key) && (float) $key < 4294967295) {
                $indexes[$key] = $value;
            } else {
                $others[] = $value;
            }
        }
        uksort($indexes, function ($a, $b) {
            return (float) $a <=> (float) $b;
        });
        return array_merge(array_values($indexes), $others);
    }

    // XML helpers (xml/nodes.js).

    /**
     * Element.first.
     *
     * @param \stdClass $element
     * @param string $name
     * @return \stdClass|null
     */
    protected static function first(\stdClass $element, string $name): ?\stdClass {
        foreach ($element->children ?? [] as $child) {
            if (($child->name ?? null) === $name) {
                return $child;
            }
        }
        return null;
    }

    /**
     * Element.firstOrEmpty.
     *
     * @param \stdClass $element
     * @param string $name
     * @return \stdClass
     */
    protected static function first_or_empty(\stdClass $element, string $name): \stdClass {
        return self::first($element, $name) ?? (object) ['type' => 'element', 'name' => null, 'attributes' => [],
            'children' => []];
    }

    /**
     * Element.getElementsByTagName (direct children only).
     *
     * @param \stdClass $element
     * @param string $name
     * @return array
     */
    protected static function children_named(\stdClass $element, string $name): array {
        return array_values(array_filter($element->children ?? [], function ($child) use ($name) {
            return ($child->name ?? null) === $name;
        }));
    }

    /**
     * Element.text: one text child, else nothing, else mammoth gives up.
     *
     * @param \stdClass $element
     * @return string
     */
    protected static function text_of(\stdClass $element): string {
        if (!$element->children) {
            return '';
        }
        if (count($element->children) !== 1 || $element->children[0]->type !== 'text') {
            self::fail('mixed text');
        }
        return $element->children[0]->value;
    }

    // Body reader (body-reader.js). A result is [elements, extras].

    /**
     * readXmlElements.
     *
     * @param array $elements
     * @return array
     */
    protected function read_all(array $elements): array {
        $values = [];
        $extras = [];
        foreach ($elements as $element) {
            [$value, $extra] = $this->read($element);
            array_push($values, ...$value);
            array_push($extras, ...$extra);
        }
        return [$values, $extras];
    }

    /**
     * readXmlElement.
     *
     * @param \stdClass $element
     * @return array
     */
    protected function read(\stdClass $element): array {
        if ($element->type !== 'element') {
            return [[], []];
        }
        switch ($element->name) {
            case 'w:p':
                return $this->read_paragraph($element);
            case 'w:r':
                [$children, $extra] = $this->read_all($element->children);
                $options = $this->hyperlink_options();
                if ($options !== null) {
                    $children = [['type' => 'hyperlink', 'children' => $children] + $options];
                }
                return [[['type' => 'run', 'children' => $children]], $extra];
            case 'w:fldChar':
                return $this->read_fldchar($element);
            case 'w:instrText':
                $this->instr[] = self::text_of($element);
                return [[], []];
            case 'w:t':
                return [[['type' => 'text', 'value' => self::text_of($element)]], []];
            case 'w:tab':
                return [[['type' => 'tab']], []];
            case 'w:noBreakHyphen':
                return [[['type' => 'text', 'value' => "\u{2011}"]], []];
            case 'w:softHyphen':
                return [[['type' => 'text', 'value' => "\u{00AD}"]], []];
            case 'w:sym':
                return $this->read_symbol($element);
            case 'w:hyperlink':
                return $this->read_hyperlink($element);
            case 'w:tbl':
                return $this->read_table($element);
            case 'w:tr':
                if (self::first(self::first_or_empty($element, 'w:trPr'), 'w:del')) {
                    return [[], []];
                }
                $header = (bool) self::first(self::first_or_empty($element, 'w:trPr'), 'w:tblHeader');
                [$children, $extra] = $this->read_all($element->children);
                return [[['type' => 'tableRow', 'children' => $children, 'isHeader' => $header]], $extra];
            case 'w:tc':
                return $this->read_cell($element);
            case 'w:footnoteReference':
            case 'w:endnoteReference':
                return [[['type' => 'noteReference',
                    'noteType' => $element->name === 'w:footnoteReference' ? 'footnote' : 'endnote',
                    'noteId' => $element->attributes['w:id'] ?? null]], []];
            case 'w:commentReference':
                return [[['type' => 'commentReference']], []];
            case 'w:br':
                $type = $element->attributes['w:type'] ?? null;
                if ($type === null || $type === 'textWrapping') {
                    return [[['type' => 'break', 'breakType' => 'line']], []];
                }
                if ($type === 'page' || $type === 'column') {
                    return [[['type' => 'break', 'breakType' => $type]], []];
                }
                return [[], []];
            case 'w:bookmarkStart':
                if (($element->attributes['w:name'] ?? null) === '_GoBack') {
                    return [[], []];
                }
                return [[['type' => 'bookmarkStart']], []];
            case 'mc:AlternateContent':
                return $this->read_all(self::first_or_empty($element, 'mc:Fallback')->children);
            case 'w:sdt':
                return $this->read_sdt($element);
            case 'w:pict':
                [$value, $extra] = $this->read_all($element->children);
                return [[], array_merge($extra, $value)];
            case 'w:ins':
            case 'w:object':
            case 'w:smartTag':
            case 'w:drawing':
            case 'v:roundrect':
            case 'v:shape':
            case 'v:textbox':
            case 'w:txbxContent':
            case 'v:group':
            case 'v:rect':
                return $this->read_all($element->children);
            case 'wp:inline':
            case 'wp:anchor':
                return $this->read_drawing($element);
            case 'v:imagedata':
                $id = $element->attributes['r:id'] ?? null;
                if ($id === null || $id === '') {
                    return [[], []];
                }
                return [[$this->image($this->embedded($id))], []];
        }
        return [[], []];
    }

    /**
     * w:p, with deleted paragraphs joined to the next one and text boxes placed after it.
     *
     * @param \stdClass $element
     * @return array
     */
    protected function read_paragraph(\stdClass $element): array {
        $properties = self::first_or_empty($element, 'w:pPr');
        if (self::first(self::first_or_empty($properties, 'w:rPr'), 'w:del')) {
            foreach ($element->children as $child) {
                $this->deleted[] = $child;
            }
            return [[], []];
        }
        $children = $element->children;
        if ($this->deleted) {
            $children = array_merge($this->deleted, $children);
            $this->deleted = [];
        }
        $styleelement = self::first($properties, 'w:pStyle');
        $styleid = null;
        $stylename = null;
        $rawid = null;
        if ($styleelement) {
            $rawid = $styleelement->attributes['w:val'] ?? null;
            if ($rawid !== null && $rawid !== '' && array_key_exists($rawid, $this->pstyles)) {
                $stylename = $this->pstyles[$rawid]['name'];
            }
        }
        $styleid = ($rawid !== null && $rawid !== '') ? $rawid : null;
        $numbering = $this->numbering_of($rawid, self::first_or_empty($properties, 'w:numPr'));
        [$content, $extra] = $this->read_all($children);
        $paragraph = ['type' => 'paragraph', 'children' => $content, 'styleId' => $styleid,
            'styleName' => ($stylename === null || $stylename === '') ? null : $stylename, 'numbering' => $numbering];
        return [array_merge([$paragraph], $extra), []];
    }

    /**
     * readNumberingProperties.
     *
     * @param string|null $styleid The w:pStyle value ('' counts), or null when there is none.
     * @param \stdClass $numpr
     * @return array|null
     */
    protected function numbering_of(?string $styleid, \stdClass $numpr): ?array {
        $level = self::first_or_empty($numpr, 'w:ilvl')->attributes['w:val'] ?? null;
        $numid = self::first_or_empty($numpr, 'w:numId')->attributes['w:val'] ?? null;
        if ($level !== null && $numid !== null) {
            return $this->find_level($numid, $level);
        }
        if ($styleid !== null) {
            $bystyle = $this->levelsbystyle[$styleid] ?? null;
            if ($bystyle !== null) {
                return $bystyle;
            }
        }
        if ($numid !== null) {
            return $this->find_level($numid, '0');
        }
        return null;
    }

    /**
     * The hyperlink options of the innermost open HYPERLINK field.
     *
     * @return array|null
     */
    protected function hyperlink_options(): ?array {
        $top = null;
        foreach ($this->fields as $field) {
            if ($field['type'] === 'hyperlink') {
                $top = $field['options'];
            }
        }
        return $top;
    }

    /**
     * w:fldChar.
     *
     * @param \stdClass $element
     * @return array
     */
    protected function read_fldchar(\stdClass $element): array {
        $type = $element->attributes['w:fldCharType'] ?? null;
        if ($type === 'begin') {
            $this->fields[] = ['type' => 'begin', 'fldChar' => $element];
            $this->instr = [];
        } else if ($type === 'end') {
            $field = array_pop($this->fields);
            if ($field === null) {
                self::fail('field end without begin');
            }
            if ($field['type'] === 'begin') {
                $field = $this->parse_instr($field);
            }
            if ($field['type'] === 'checkbox') {
                return [[['type' => 'checkbox']], []];
            }
        } else if ($type === 'separate') {
            $field = array_pop($this->fields);
            if ($field === null) {
                self::fail('field separator without begin');
            }
            $this->fields[] = $this->parse_instr($field);
        }
        return [[], []];
    }

    /**
     * parseCurrentInstrText.
     *
     * @param array $field
     * @return array
     */
    protected function parse_instr(array $field): array {
        $text = implode('', $this->instr);
        $ws = '[' . self::WS . ']';
        $any = '[^\n\r\x{2028}\x{2029}]';
        if (preg_match('/HYPERLINK "(' . $any . '*)"/u', $text, $m)) {
            return ['type' => 'hyperlink', 'options' => ['href' => $m[1]]];
        }
        if (preg_match('/HYPERLINK' . $ws . '+\\\\l' . $ws . '+"(' . $any . '*)"/u', $text, $m)) {
            return ['type' => 'hyperlink', 'options' => ['anchor' => $m[1]]];
        }
        if (strpos($text, 'FORMCHECKBOX') !== false) {
            return ['type' => 'checkbox'];
        }
        return ['type' => 'unknown'];
    }

    /**
     * w:sym through dingbat-to-unicode.
     *
     * @param \stdClass $element
     * @return array
     */
    protected function read_symbol(\stdClass $element): array {
        $font = $element->attributes['w:font'] ?? null;
        $char = $element->attributes['w:char'] ?? null;
        if ($font === null) {
            self::fail('symbol without a font');
        }
        $unicode = dingbats::hex($font, $char);
        if ($unicode === null && $char !== null && preg_match('/^F0[^\n\r\x{2028}\x{2029}]{2}$/u', $char)) {
            $unicode = dingbats::hex($font, mb_substr($char, 2));
        }
        return $unicode === null ? [[], []] : [[['type' => 'text', 'value' => $unicode]], []];
    }

    /**
     * w:hyperlink.
     *
     * @param \stdClass $element
     * @return array
     */
    protected function read_hyperlink(\stdClass $element): array {
        $id = $element->attributes['r:id'] ?? null;
        $anchor = $element->attributes['w:anchor'] ?? null;
        [$children, $extra] = $this->read_all($element->children);
        if ($id !== null && $id !== '') {
            $href = $this->rels[$id] ?? null;
            if ($anchor !== null && $anchor !== '') {
                if ($href === null) {
                    self::fail('hyperlink without a target');
                }
                $hash = strpos($href, '#');
                $href = ($hash === false ? $href : substr($href, 0, $hash)) . '#' . $anchor;
            }
            return [[['type' => 'hyperlink', 'children' => $children, 'href' => $href]], $extra];
        }
        if ($anchor !== null && $anchor !== '') {
            return [[['type' => 'hyperlink', 'children' => $children, 'anchor' => $anchor]], $extra];
        }
        return [$children, $extra];
    }

    /**
     * w:sdt, with Word's checkbox content controls.
     *
     * @param \stdClass $element
     * @return array
     */
    protected function read_sdt(\stdClass $element): array {
        [$content, $extra] = $this->read_all(self::first_or_empty($element, 'w:sdtContent')->children);
        if (!self::first(self::first_or_empty($element, 'w:sdtPr'), 'wordml:checkbox')) {
            return [$content, $extra];
        }
        $found = false;
        $replace = function (array $node) use (&$replace, &$found) {
            if (isset($node['children'])) {
                foreach ($node['children'] as $i => $child) {
                    $node['children'][$i] = $replace($child);
                }
            }
            if ($node['type'] === 'text' && $node['value'] !== '' && !$found) {
                $found = true;
                return ['type' => 'checkbox'];
            }
            return $node;
        };
        foreach ($content as $i => $node) {
            $content[$i] = $replace($node);
        }
        return [$found ? $content : [['type' => 'checkbox']], $extra];
    }

    /**
     * w:tbl, with vertically merged cells folded into the first (calculateRowSpans).
     *
     * @param \stdClass $element
     * @return array
     */
    protected function read_table(\stdClass $element): array {
        $style = self::first(self::first_or_empty($element, 'w:tblPr'), 'w:tblStyle');
        $styleid = $style ? ($style->attributes['w:val'] ?? null) : null;
        if (!$this->hasstyles && $styleid !== null && $styleid !== '') {
            self::fail('table style without a styles part');
        }
        [$rows, $extra] = $this->read_all($element->children);
        $wellformed = true;
        foreach ($rows as $row) {
            if ($row['type'] !== 'tableRow') {
                $wellformed = false;
            }
        }
        if ($wellformed) {
            foreach ($rows as $row) {
                foreach ($row['children'] as $cell) {
                    if ($cell['type'] !== 'tableCell') {
                        $wellformed = false;
                    }
                }
            }
        }
        if ($wellformed) {
            $columns = [];
            foreach ($rows as $r => $row) {
                $index = 0.0;
                foreach ($row['children'] as $c => $cell) {
                    $key = self::number_key($index);
                    if ($cell['vmerge'] && isset($columns[$key])) {
                        $rows[$r]['children'][$c]['drop'] = true;
                    } else {
                        $columns[$key] = true;
                    }
                    $index += $cell['colSpan'];
                }
            }
            foreach ($rows as $r => $row) {
                $rows[$r]['children'] = array_values(array_filter($row['children'], function ($cell) {
                    return empty($cell['drop']);
                }));
            }
        }
        return [[['type' => 'table', 'children' => $rows]], $extra];
    }

    /**
     * A JavaScript number as an object key.
     *
     * @param float $n
     * @return string
     */
    protected static function number_key(float $n): string {
        if (is_nan($n)) {
            return 'NaN';
        }
        if (is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }
        if (floor($n) == $n && abs($n) < 1e21) {
            return sprintf('%.0f', $n);
        }
        return json_encode($n);
    }

    /**
     * w:tc.
     *
     * @param \stdClass $element
     * @return array
     */
    protected function read_cell(\stdClass $element): array {
        [$children, $extra] = $this->read_all($element->children);
        $properties = self::first_or_empty($element, 'w:tcPr');
        $span = self::first_or_empty($properties, 'w:gridSpan')->attributes['w:val'] ?? null;
        $colspan = ($span === null || $span === '') ? 1.0 : self::parse_int($span);
        $vmerge = self::first($properties, 'w:vMerge');
        $merged = false;
        if ($vmerge) {
            $value = $vmerge->attributes['w:val'] ?? null;
            $merged = $value === 'continue' || $value === null || $value === '';
        }
        return [[['type' => 'tableCell', 'children' => $children, 'colSpan' => $colspan, 'vmerge' => $merged]], $extra];
    }

    /**
     * parseInt(value, 10).
     *
     * @param string $value
     * @return float
     */
    protected static function parse_int(string $value): float {
        if (!preg_match('/^[' . self::WS . ']*([+-]?)([0-9]+)/u', $value, $m)) {
            return NAN;
        }
        $n = (float) $m[2];
        return $m[1] === '-' ? -$n : $n;
    }

    /**
     * wp:inline / wp:anchor: the pictures inside.
     *
     * @param \stdClass $element
     * @return array
     */
    protected function read_drawing(\stdClass $element): array {
        $blips = [$element];
        foreach (['a:graphic', 'a:graphicData', 'pic:pic', 'pic:blipFill', 'a:blip'] as $name) {
            $next = [];
            foreach ($blips as $item) {
                array_push($next, ...self::children_named($item, $name));
            }
            $blips = $next;
        }
        $images = [];
        foreach ($blips as $blip) {
            if (!self::first($element, 'wp:docPr')) {
                self::fail('picture without wp:docPr');
            }
            $embed = $blip->attributes['r:embed'] ?? null;
            $link = $blip->attributes['r:link'] ?? null;
            if ($embed !== null && $embed !== '') {
                $images[] = $this->image($this->embedded($embed));
            } else if ($link !== null && $link !== '') {
                if (($this->rels[$link] ?? null) === null) {
                    self::fail('linked picture without a target');
                }
                // External pictures are never read: no picture in the HTML.
                $images[] = ['type' => 'image', 'present' => false];
            }
        }
        return [$images, []];
    }

    /**
     * The package path of an embedded picture (uris.uriToZipEntryName('word', target)).
     *
     * @param string $id
     * @return string
     */
    protected function embedded(string $id): string {
        $target = $this->rels[$id] ?? null;
        if ($target === null) {
            self::fail('picture without a target');
        }
        return strncmp($target, '/', 1) === 0 ? substr($target, 1) : 'word/' . $target;
    }

    /**
     * A picture: in the HTML only when its file is in the package.
     *
     * @param string $path
     * @return array
     */
    protected function image(string $path): array {
        return ['type' => 'image', 'present' => $this->exists($path)];
    }

    // Conversion to HTML (document-to-html.js) with the default style map.

    /**
     * Converts the document and writes the simplified HTML.
     *
     * @param array $children
     * @return string
     */
    protected function document(array $children): string {
        $this->notenumber = 1;
        $this->notereferences = [];
        $nodes = $this->convert_all($children, false);
        $notes = [];
        foreach ($this->notereferences as $reference) {
            $key = $reference['noteType'] . '-' . self::key($reference['noteId']);
            if (!isset($this->notes[$key])) {
                self::fail('missing note');
            }
            $notes[] = $this->notes[$key];
        }
        $nodes[] = self::element('ol', true, $this->convert_all($notes, false));
        $nodes[] = self::element('dl', true, []);
        $out = '';
        foreach (self::collapse(self::remove_empty($nodes)) as $node) {
            $out .= self::write($node);
        }
        return $out;
    }

    /**
     * convertElements.
     *
     * @param array $elements
     * @param bool $header
     * @return array
     */
    protected function convert_all(array $elements, bool $header): array {
        $nodes = [];
        foreach ($elements as $element) {
            array_push($nodes, ...$this->convert_one($element, $header));
        }
        return $nodes;
    }

    /**
     * elementToHtml.
     *
     * @param array $element
     * @param bool $header
     * @return array
     */
    protected function convert_one(array $element, bool $header): array {
        switch ($element['type']) {
            case 'paragraph':
                return self::wrap(
                    $this->paragraph_path($element),
                    function () use ($element, $header) {
                        return $this->convert_all($element['children'], $header);
                    }
                );
            case 'run':
                return $this->convert_all($element['children'], $header);
            case 'text':
                return [(object) ['type' => 'text', 'value' => $element['value']]];
            case 'tab':
                return [(object) ['type' => 'text', 'value' => "\t"]];
            case 'hyperlink':
                $anchor = $element['anchor'] ?? null;
                $href = ($anchor !== null && $anchor !== '') ? '#' . $anchor : ($element['href'] ?? null);
                return [self::element(
                    'a',
                    false,
                    $this->convert_all($element['children'], $header),
                    ['href' => $href]
                )];
            case 'checkbox':
                return [self::element('input', true, [])];
            case 'bookmarkStart':
                return [self::element('a', true, [self::force()])];
            case 'noteReference':
                $this->notereferences[] = $element;
                $number = $this->notenumber++;
                return [self::element('sup', false, [self::element(
                    'a',
                    true,
                    [(object) ['type' => 'text', 'value' => '[' . $number . ']']]
                )])];
            case 'note':
                $body = $this->convert_all($element['body'], $header);
                $body[] = self::element('p', false, [(object) ['type' => 'text', 'value' => ' '],
                    self::element('a', true, [(object) ['type' => 'text', 'value' => '↑']])]);
                return [self::element('li', true, $body)];
            case 'commentReference':
                return [];
            case 'image':
                return $element['present'] ? [self::element('img', true, [])] : [];
            case 'table':
                return [self::element('table', true, $this->table_children($element, $header))];
            case 'tableRow':
                return [self::element('tr', true, array_merge(
                    [self::force()],
                    $this->convert_all($element['children'], $header)
                ))];
            case 'tableCell':
                return [self::element($header ? 'th' : 'td', true, array_merge(
                    [self::force()],
                    $this->convert_all($element['children'], $header)
                ))];
            case 'break':
                return $element['breakType'] === 'line' ? [self::element('br', true, [])] : [];
        }
        return [];
    }

    /**
     * convertTableChildren: leading header rows go in thead.
     *
     * @param array $table
     * @param bool $header
     * @return array
     */
    protected function table_children(array $table, bool $header): array {
        $children = $table['children'];
        $bodyindex = count($children);
        foreach ($children as $i => $child) {
            if (empty($child['isHeader'])) {
                $bodyindex = $i;
                break;
            }
        }
        if ($bodyindex === 0) {
            $nodes = $this->convert_all($children, false);
        } else {
            $nodes = [
                self::element('thead', true, $this->convert_all(array_slice($children, 0, $bodyindex), true)),
                self::element('tbody', true, $this->convert_all(array_slice($children, $bodyindex), false)),
            ];
        }
        return array_merge([self::force()], $nodes);
    }

    /**
     * The default style map's path for a paragraph: [[tag names, fresh], ...] outermost first.
     *
     * @param array $paragraph
     * @return array
     */
    protected function paragraph_path(array $paragraph): array {
        $id = $paragraph['styleId'];
        $name = $paragraph['styleName'];
        $named = function (string $wanted) use ($name): bool {
            return $name !== null && mb_strtoupper($wanted, 'UTF-8') === mb_strtoupper($name, 'UTF-8');
        };
        for ($n = 1; $n <= 6; $n++) {
            if ($id === 'Heading' . $n) {
                return [[['h' . $n], true]];
            }
        }
        for ($n = 1; $n <= 6; $n++) {
            if ($named('Heading ' . $n)) {
                return [[['h' . $n], true]];
            }
        }
        if ($id === 'Heading' || $named('Heading')) {
            return [[['h1'], true]];
        }
        foreach (['footnote text', 'endnote text', 'annotation text', 'Footnote', 'Endnote'] as $style) {
            if ($named($style)) {
                return [[['p'], true]];
            }
        }
        $numbering = $paragraph['numbering'];
        if ($numbering) {
            foreach ([false, true] as $ordered) {
                for ($level = 0; $level < 5; $level++) {
                    if (self::loose_equal($numbering['level'], $level) && $numbering['isOrdered'] === $ordered) {
                        $path = [];
                        for ($i = 0; $i < $level; $i++) {
                            $path[] = [['ul', 'ol'], false];
                            $path[] = [['li'], false];
                        }
                        $path[] = [[$ordered ? 'ol' : 'ul'], false];
                        $path[] = [['li'], true];
                        return $path;
                    }
                }
            }
        }
        return [[['p'], true]];
    }

    /**
     * level == index with JavaScript's string to number conversion.
     *
     * @param string $level
     * @param int $index
     * @return bool
     */
    protected static function loose_equal(string $level, int $index): bool {
        $s = preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/u', '', $level);
        if ($s === '') {
            return $index === 0;
        }
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/D', $s, $m)) {
            return hexdec($m[1]) == $index;
        }
        if (preg_match('/^0[oO]([0-7]+)$/D', $s, $m)) {
            return octdec($m[1]) == $index;
        }
        if (preg_match('/^0[bB]([01]+)$/D', $s, $m)) {
            return bindec($m[1]) == $index;
        }
        if (preg_match('/^[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $s)) {
            return (float) $s == $index;
        }
        return false;
    }

    /**
     * HtmlPath.wrap.
     *
     * @param array $path
     * @param callable $children
     * @return array
     */
    protected static function wrap(array $path, callable $children): array {
        $result = $children();
        for ($i = count($path) - 1; $i >= 0; $i--) {
            $result = [self::element($path[$i][0], $path[$i][1], $result)];
        }
        return $result;
    }

    // HTML tree (html/ast.js, html/simplify.js, writers/html-writer.js).

    /**
     * An HTML element node.
     *
     * @param string|array $tag Tag name, or the names it may merge with (first is written).
     * @param bool $fresh
     * @param array $children
     * @param array $attributes
     * @return \stdClass
     */
    protected static function element($tag, bool $fresh, array $children, array $attributes = []): \stdClass {
        $names = (array) $tag;
        return (object) ['type' => 'element', 'tag' => $names[0], 'names' => $names, 'fresh' => $fresh,
            'attributes' => $attributes, 'children' => $children];
    }

    /**
     * Html.forceWrite.
     *
     * @return \stdClass
     */
    protected static function force(): \stdClass {
        return (object) ['type' => 'forceWrite'];
    }

    /**
     * Whether an element is written as a void tag.
     *
     * @param \stdClass $node
     * @return bool
     */
    protected static function is_void(\stdClass $node): bool {
        return !$node->children && in_array($node->tag, ['br', 'hr', 'img', 'input'], true);
    }

    /**
     * simplify.removeEmpty.
     *
     * @param array $nodes
     * @return array
     */
    protected static function remove_empty(array $nodes): array {
        $out = [];
        foreach ($nodes as $node) {
            if ($node->type === 'element') {
                $children = self::remove_empty($node->children);
                $copy = clone $node;
                $copy->children = $children;
                if ($children || self::is_void($copy)) {
                    $out[] = $copy;
                }
            } else if ($node->type === 'text') {
                if ($node->value !== '') {
                    $out[] = $node;
                }
            } else {
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * simplify.collapse.
     *
     * @param array $nodes
     * @return array
     */
    protected static function collapse(array $nodes): array {
        $children = [];
        foreach ($nodes as $node) {
            if ($node->type === 'element') {
                $node = clone $node;
                $node->children = self::collapse($node->children);
            }
            self::append_child($children, $node);
        }
        return $children;
    }

    /**
     * simplify.appendChild: a non-fresh element joins the previous matching one.
     *
     * @param array $children
     * @param \stdClass $child
     */
    protected static function append_child(array &$children, \stdClass $child): void {
        $last = $children ? $children[count($children) - 1] : null;
        if (
            $child->type === 'element' && !$child->fresh && $last && $last->type === 'element'
                && in_array($last->tag, $child->names, true) && $child->attributes == $last->attributes
        ) {
            foreach ($child->children as $grandchild) {
                self::append_child($last->children, $grandchild);
            }
        } else {
            $children[] = $child;
        }
    }

    /**
     * The simple writer.
     *
     * @param \stdClass $node
     * @return string
     */
    protected static function write(\stdClass $node): string {
        if ($node->type === 'text') {
            return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $node->value);
        }
        if ($node->type !== 'element') {
            return '';
        }
        foreach ($node->attributes as $value) {
            if ($value === null) {
                // Mammoth's escapeHtmlAttribute(undefined) throws here.
                self::fail('link without a target');
            }
        }
        if (self::is_void($node)) {
            return '<' . $node->tag . ' />';
        }
        $out = '<' . $node->tag . '>';
        foreach ($node->children as $child) {
            $out .= self::write($child);
        }
        return $out . '</' . $node->tag . '>';
    }
}
