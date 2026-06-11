<?php

namespace Accredify\RdfCanonicalize\Contracts;

interface Canonicalizer
{
    /**
     * Canonicalize an RDF dataset supplied as an N-Quads string (RDFC-1.0).
     *
     * @param  string  $input  The RDF dataset serialised as N-Quads
     * @param  array<string, mixed>  $options  Optional processing options (e.g. inputFormat)
     * @return list<string> Canonicalized N-Quads, one quad per element
     */
    public function canonicalize(string $input, array $options = []): array;
}
