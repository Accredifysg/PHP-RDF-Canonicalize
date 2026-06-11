<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RDFC-1.0 characterization fixtures
|--------------------------------------------------------------------------
|
| Each key is a directory under tests/Fixtures/ holding two byte-exact files:
|
|   input.nq     — an RDF dataset serialised as N-Quads
|   expected.nq  — the RDFC-1.0 canonical N-Quads for that dataset
|
| PROVENANCE / PARITY BASELINE
| ----------------------------
| These cases are the re-framed descendants of
| verifiable-credentials-php's tests/Canonicalization/RDFC10Test.php. That
| suite expressed its inputs as JSON-LD and serialised them to N-Quads via
| php-json-ld's `toRdf()->toNQuads()` before canonicalising. The JSON-LD →
| N-Quads step is NOT this package's concern (that coupling stayed in VC), so
| each `input.nq` is the captured intermediate N-Quads and each `expected.nq`
| is VC's exact canonical output for it. Re-framing this way preserves the
| original coverage at this package's real boundary: N-Quads in, canonical
| N-Quads out.
|
| The `expected.nq` bytes are the VC parity baseline. Do NOT edit them to
| chase W3C conformance — VC's eddsa-rdfc-2022 / ecdsa-sd-2023 signatures
| depend on this exact output. Any change needs owner sign-off plus a
| coordinated VC fixture regeneration. See tests/ParityTest.php.
|
| The value is a human-readable description used for the test name.
*/

return [
    'nested-blank-nodes' => 'a document with nested blank nodes',
    'multiple-values' => 'a document with multiple values for the same property',
    'language-tags' => 'a document with language tags',
    'lists' => 'a document with an RDF list',
    'deep-recursion' => 'a document with deep recursion',
    'hash-collisions' => 'a document with first-degree hash collisions',
    'complex-blank-node-relationships' => 'a document with complex blank node relationships',
    'multiple-equivalent-blank-nodes' => 'a document with multiple equivalent blank nodes',
    'n-degree-relationships' => 'a document with n-degree relationships',
    'escaping-special-symbols' => 'literals with N-Quads-escaped special symbols (passed through verbatim)',
    'named-subject' => 'a dataset with named-node (non-blank) subjects',
    'person-name-email' => 'a person with a name and email',
    'empty-list' => 'an empty list serialised as rdf:nil',
];
