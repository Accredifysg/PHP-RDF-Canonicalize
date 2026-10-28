<?php

namespace Accredify\RdfCanonicalize;

use Exception;

class RdfTerm
{
    public const NAMED_NODE = 'NamedNode';

    public const BLANK_NODE = 'BlankNode';

    public const LITERAL = 'Literal';

    public const GRAPH = 'Graph';

    public const XSD_STRING = 'https://www.w3.org/2001/XMLSchema#string';

    public const XSD_BOOLEAN = 'https://www.w3.org/2001/XMLSchema#boolean';

    public const XSD_DATETIME = 'https://www.w3.org/2001/XMLSchema#dateTime';

    public const XSD_ANY_URI = 'https://www.w3.org/2001/XMLSchema#anyURI';

    public const RDF_SYNTAX_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';

    public const RDF_SYNTAX_NIL = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#nil';

    public const RDF_SYNTAX_FIRST = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#first';

    public const RDF_SYNTAX_REST = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#rest';

    /**
     * Canonical N-Quads literal escaping (RDF 1.1 canonical form): ECHAR for
     * \b \t \n \f \r \" \\, \uXXXX (uppercase hex) for the remaining C0
     * controls and U+007F. Every other character — including C1 controls and
     * beyond — passes through as raw UTF-8.
     *
     * Single source of truth for literal escaping. The output bytes are
     * frozen: verifiable-credentials-php's eddsa-rdfc-2022 / ecdsa-sd-2023
     * signatures sign over them. {@see NQuadsSerializer::encodeLiteral} keeps
     * its own regex implementation of this table for memory reasons; the two
     * are pinned byte-identical, and {@see NQuadsParser} pinned as the
     * inverse, by tests/NQuadsTest.php.
     *
     * @var array<string, string>
     */
    public const CANONICAL_LITERAL_ESCAPES = [
        "\x00" => '\u0000',
        "\x01" => '\u0001',
        "\x02" => '\u0002',
        "\x03" => '\u0003',
        "\x04" => '\u0004',
        "\x05" => '\u0005',
        "\x06" => '\u0006',
        "\x07" => '\u0007',
        "\x08" => '\b',
        "\x09" => '\t',
        "\x0A" => '\n',
        "\x0B" => '\u000B',
        "\x0C" => '\f',
        "\x0D" => '\r',
        "\x0E" => '\u000E',
        "\x0F" => '\u000F',
        "\x10" => '\u0010',
        "\x11" => '\u0011',
        "\x12" => '\u0012',
        "\x13" => '\u0013',
        "\x14" => '\u0014',
        "\x15" => '\u0015',
        "\x16" => '\u0016',
        "\x17" => '\u0017',
        "\x18" => '\u0018',
        "\x19" => '\u0019',
        "\x1A" => '\u001A',
        "\x1B" => '\u001B',
        "\x1C" => '\u001C',
        "\x1D" => '\u001D',
        "\x1E" => '\u001E',
        "\x1F" => '\u001F',
        '"' => '\"',
        '\\' => '\\\\',
        "\x7F" => '\u007F',
    ];

    public function __construct(
        public readonly string $termType,
        public readonly string $value,
        public readonly ?string $language = null,
        public readonly ?RdfTerm $datatype = null
    ) {}

    public static function namedNode(string $value): self
    {
        return new self(self::NAMED_NODE, $value);
    }

    public static function blankNode(string $value): self
    {
        return new self(self::BLANK_NODE, $value);
    }

    public static function literal(
        string $value,
        ?string $language = null,
        ?RdfTerm $datatype = null
    ): self {
        $datatype = $datatype ?? new self(
            self::NAMED_NODE,
            self::XSD_STRING
        );

        return new self(self::LITERAL, $value, $language, $datatype);
    }

    public static function graph(string $value = ''): self
    {
        return new self(self::GRAPH, $value);
    }

    public function toString(): string
    {
        return match ($this->termType) {
            self::NAMED_NODE => '<'.$this->value.'>',
            self::BLANK_NODE => $this->value,
            self::LITERAL => $this->literalToString(),
            self::GRAPH => ! empty($this->value) ? '<'.$this->value.'>' : '',
            default => throw new Exception('Unknown term type: '.$this->termType),
        };
    }

    private function literalToString(): string
    {
        $literal = '"'.$this->escapeLiteral($this->value).'"';

        if ($this->language) {
            return $literal.'@'.$this->language;
        }

        if ($this->datatype && $this->datatype->value !== self::XSD_STRING) {
            return $literal.'^^'.$this->datatype->toString();
        }

        return $literal;
    }

    private function escapeLiteral(string $value): string
    {
        return strtr($value, self::CANONICAL_LITERAL_ESCAPES);
    }
}
