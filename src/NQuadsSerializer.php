<?php

declare(strict_types=1);

namespace Accredify\RdfCanonicalize;

/**
 * Serialises RDF quad components to canonical N-Quads.
 *
 * Extracted verbatim from RDFC10 (v0.2.0) — the serialisation behaviour, and
 * therefore the canonical output bytes, is unchanged. This output is frozen:
 * verifiable-credentials-php's eddsa-rdfc-2022 / ecdsa-sd-2023 signatures sign
 * over it (see tests/ParityTest.php).
 */
class NQuadsSerializer
{
    public function serialize(RdfTerm $s, RdfTerm $p, RdfTerm $o, RdfTerm $g): string
    {
        $nquad = '';

        // subject can only be NamedNode or BlankNode
        $sType = $s->termType;
        $sValue = $s->value;
        if ($sType === RdfTerm::NAMED_NODE) {
            $nquad .= "<{$sValue}>";
        } else {
            $nquad .= $sValue;
        }

        // predicate can only be NamedNode
        $pValue = $p->value;
        $nquad .= " <{$pValue}> ";

        // object is NamedNode, BlankNode, or Literal
        $oType = $o->termType;
        $oValue = $o->value;
        $oLanguage = $o->language;
        if ($oType === RdfTerm::NAMED_NODE) {
            $nquad .= "<{$oValue}>";
        } elseif ($oType === RdfTerm::BLANK_NODE) {
            $nquad .= $oValue;
        } else {
            // A Literal always carries a datatype (RdfTerm::literal() defaults
            // it to xsd:string), so this is never null in practice.
            assert($o->datatype !== null);
            $oDatatypeValue = $o->datatype->value;

            $nquad .= '"'.$this->encodeLiteral($oValue).'"';

            if ($oLanguage) {
                $nquad .= "@{$oLanguage}";
            }

            if ($oDatatypeValue !== RdfTerm::XSD_STRING) {
                $nquad .= "^^<{$oDatatypeValue}>";
            }
        }

        // graph can only be NamedNode or BlankNode (or DefaultGraph, but that
        // does not add to `nquad`)
        $gType = $g->termType;
        $gValue = $g->value;
        if ($gType === RdfTerm::NAMED_NODE) {
            $nquad .= " <{$gValue}>";
        } elseif ($gType === RdfTerm::BLANK_NODE) {
            $nquad .= " {$gValue}";
        }

        $nquad .= " .\n";

        return $nquad;
    }

    /**
     * Encode a literal value with the canonical N-Quads escaping (RDF 1.1
     * canonical form): ECHAR for \b \t \n \f \r \" \\, \uXXXX (uppercase hex)
     * for the remaining C0 controls and U+007F, raw UTF-8 for everything else
     * (including C1 controls and beyond).
     *
     * This is a regex implementation of the shared escape table
     * {@see RdfTerm::CANONICAL_LITERAL_ESCAPES} (kept as a single
     * preg_replace_callback pass for memory reasons — see PR #28). Any change
     * here needs the mirrored change there; tests/NQuadsTest.php pins the two
     * byte-identical.
     */
    private function encodeLiteral(string $value): string
    {
        // Every byte matched here is a whole character in valid UTF-8, so a
        // byte-level match escapes the same characters as a code-point walk,
        // without splitting a large literal (an embedded PDF) into an array.
        return preg_replace_callback(
            '/[\x00-\x1F\x22\x5C\x7F]/',
            static fn (array $match): string => match ($match[0]) {
                "\x08" => '\\b',
                "\t" => '\\t',
                "\n" => '\\n',
                "\f" => '\\f',
                "\r" => '\\r',
                '"' => '\\"',
                '\\' => '\\\\',
                default => sprintf('\\u%04X', ord($match[0])),
            },
            $value,
        ) ?? throw new \RuntimeException(preg_last_error_msg());
    }
}
