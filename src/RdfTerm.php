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
        return strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);
    }
}
