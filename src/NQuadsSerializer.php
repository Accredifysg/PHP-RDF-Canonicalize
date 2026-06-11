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

            $nquad .= "\"{$oValue}\"";

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
}
