<?php

namespace Accredify\RdfCanonicalize;

class RdfQuad
{
    public RdfTerm $graph;

    public function __construct(
        public RdfTerm $subject,
        public RdfTerm $predicate,
        public RdfTerm $object,
        ?RdfTerm $graph = null
    ) {
        $this->graph = $graph ?? RdfTerm::graph();
    }

    public function toString(): string
    {
        $quad = sprintf(
            '%s %s %s',
            $this->subject->toString(),
            $this->predicate->toString(),
            $this->object->toString()
        );

        if (! empty($this->graph->value)) {
            $quad .= ' '.$this->graph->toString();
        }

        return $quad.' .';
    }
}
