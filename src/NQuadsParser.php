<?php

declare(strict_types=1);

namespace Accredify\RdfCanonicalize;

use Exception;

/**
 * Parses an RDF dataset serialised as N-Quads into {@see RdfQuad} value objects.
 *
 * Extracted verbatim from RDFC10 (v0.2.0) — the parsing behaviour, and
 * therefore the quads fed to the canonicalization algorithm, is unchanged. This
 * is a pragmatic parser tuned to the well-formed N-Quads that
 * accredifysg/php-json-ld's `toRdf` emits, not a full N-Quads grammar: it
 * tolerates comments and blank lines and silently skips lines it cannot parse.
 * The known conformance gaps (e.g. the full ECHAR/UCHAR escaping grammar) live
 * here — see tests/W3c/README.md.
 */
class NQuadsParser
{
    /**
     * Parse an N-Quads string into RdfQuad objects.
     *
     * @param  string  $nquads  N-Quads as a single string
     * @return list<RdfQuad>
     */
    public function parse(string $nquads): array
    {
        $quads = [];
        $lines = explode("\n", trim($nquads));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue; // Skip empty lines and comments
            }

            $quad = $this->parseLine($line);
            if ($quad) {
                $quads[] = $quad;
            }
        }

        return $quads;
    }

    /**
     * Parse a single N-Quad line into an RdfQuad object.
     *
     * @return RdfQuad|null RdfQuad object or null if parsing fails
     */
    private function parseLine(string $line): ?RdfQuad
    {
        // Remove trailing period and whitespace
        $line = rtrim($line, " .\t\r\n");

        // Split into components (subject, predicate, object, optional graph)
        $components = $this->splitLine($line);

        if (count($components) < 3) {
            return null;
        }

        try {
            $subject = $this->parseTerm($components[0]);
            $predicate = $this->parseTerm($components[1]);
            $object = $this->parseTerm($components[2]);
            $graph = isset($components[3]) ? $this->parseTerm($components[3]) : null;

            return new RdfQuad($subject, $predicate, $object, $graph);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Split an N-Quad line into components, handling quoted literals.
     *
     * @return list<string> Array of components
     */
    private function splitLine(string $line): array
    {
        $components = [];
        $current = '';
        $inQuotes = false;
        $escapeNext = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($escapeNext) {
                $current .= $char;
                $escapeNext = false;

                continue;
            }

            if ($char === '\\') {
                $current .= $char;
                $escapeNext = true;

                continue;
            }

            if ($char === '"') {
                $current .= $char;
                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && $char === ' ') {
                if ($current !== '') {
                    $components[] = $current;
                    $current = '';
                }

                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $components[] = $current;
        }

        return $components;
    }

    /**
     * Parse an RDF term from a string.
     */
    private function parseTerm(string $term): RdfTerm
    {
        // Blank node
        if (str_starts_with($term, '_:')) {
            return RdfTerm::blankNode($term);
        }

        // Named node (IRI) — decode any UCHAR escapes to code points.
        if (str_starts_with($term, '<') && str_ends_with($term, '>')) {
            return RdfTerm::namedNode($this->decodeEscapes(substr($term, 1, -1)));
        }

        // Literal
        if (str_starts_with($term, '"')) {
            return $this->parseLiteral($term);
        }

        // Default to named node
        return RdfTerm::namedNode($term);
    }

    /**
     * Parse a literal term: "STRING" optionally followed by @lang or
     * ^^<datatype>. Scans to the closing quote honouring backslash escapes (so
     * an escaped \" inside the string does not terminate it), then decodes
     * the escapes to code points.
     */
    private function parseLiteral(string $term): RdfTerm
    {
        $length = strlen($term);
        $raw = '';
        $i = 1; // skip the opening quote

        while ($i < $length) {
            $char = $term[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $raw .= $char.$term[$i + 1];
                $i += 2;

                continue;
            }

            if ($char === '"') {
                $i++; // consume the closing quote

                break;
            }

            $raw .= $char;
            $i++;
        }

        $value = $this->decodeEscapes($raw);

        // Whatever follows the closing quote is the language tag or datatype.
        $suffix = substr($term, $i);
        $language = null;
        $datatype = null;
        if (str_starts_with($suffix, '@')) {
            $language = substr($suffix, 1);
        } elseif (str_starts_with($suffix, '^^<') && str_ends_with($suffix, '>')) {
            $datatype = $this->decodeEscapes(substr($suffix, 3, -1));
        }

        return RdfTerm::literal($value, $language, $datatype !== null ? RdfTerm::namedNode($datatype) : null);
    }

    /**
     * Decode N-Quads ECHAR (\t \b \n \r \f \" \' \\) and UCHAR (\uXXXX,
     * \UXXXXXXXX) escapes into their actual code points (UTF-8). A lone
     * backslash that matches no escape is kept verbatim.
     */
    private function decodeEscapes(string $value): string
    {
        $out = '';
        $length = strlen($value);
        $i = 0;

        while ($i < $length) {
            $char = $value[$i];

            if ($char !== '\\' || $i + 1 >= $length) {
                $out .= $char;
                $i++;

                continue;
            }

            $next = $value[$i + 1];
            switch ($next) {
                case 't': $out .= "\t";
                    $i += 2;
                    break;
                case 'b': $out .= "\x08";
                    $i += 2;
                    break;
                case 'n': $out .= "\n";
                    $i += 2;
                    break;
                case 'r': $out .= "\r";
                    $i += 2;
                    break;
                case 'f': $out .= "\f";
                    $i += 2;
                    break;
                case '"': $out .= '"';
                    $i += 2;
                    break;
                case "'": $out .= "'";
                    $i += 2;
                    break;
                case '\\': $out .= '\\';
                    $i += 2;
                    break;
                case 'u': $out .= $this->codePoint(substr($value, $i + 2, 4));
                    $i += 6;
                    break;
                case 'U': $out .= $this->codePoint(substr($value, $i + 2, 8));
                    $i += 10;
                    break;
                default: $out .= $char;
                    $i++; // lone backslash; keep verbatim
            }
        }

        return $out;
    }

    /**
     * Convert a hex code point (UCHAR payload) to its UTF-8 encoding.
     */
    private function codePoint(string $hex): string
    {
        // (string) maps a false (out-of-range code point) to '' without an
        // explicit guard PHPStan would flag as dead.
        return (string) mb_chr((int) hexdec($hex), 'UTF-8');
    }
}
