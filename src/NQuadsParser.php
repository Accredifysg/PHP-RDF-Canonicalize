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

        // Named node (IRI)
        if (str_starts_with($term, '<') && str_ends_with($term, '>')) {
            return RdfTerm::namedNode(substr($term, 1, -1));
        }

        // Literal
        if (str_starts_with($term, '"')) {
            return $this->parseLiteral($term);
        }

        // Default to named node
        return RdfTerm::namedNode($term);
    }

    /**
     * Parse a literal term.
     */
    private function parseLiteral(string $term): RdfTerm
    {
        // Pattern: "value"@lang or "value"^^<datatype> or just "value"
        if (preg_match('/^"([^"]*)"(?:@([a-z]+(?:-[a-z0-9]+)*))?(?:\^\^<([^>]+)>)?$/i', $term, $matches)) {
            $value = $matches[1];
            $language = $matches[2] ?? null;
            $datatype = $matches[3] ?? null;

            return RdfTerm::literal($value, $language, $datatype ? RdfTerm::namedNode($datatype) : null);
        }

        // Fallback: treat as plain literal
        return RdfTerm::literal(trim($term, '"'));
    }
}
