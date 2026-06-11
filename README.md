# PHP-RDF-Canonicalize

<!-- Badges (placeholders — wired up when the repo is published) -->
<!-- ![CI](https://img.shields.io/github/actions/workflow/status/accredifysg/php-rdf-canonicalize/ci.yml?branch=main) -->
<!-- ![Packagist Version](https://img.shields.io/packagist/v/accredifysg/php-rdf-canonicalize) -->
<!-- ![PHP Version](https://img.shields.io/packagist/php-v/accredifysg/php-rdf-canonicalize) -->
<!-- ![License](https://img.shields.io/packagist/l/accredifysg/php-rdf-canonicalize) -->

A PHP implementation of the W3C
[RDF Dataset Canonicalization (RDFC-1.0)](https://www.w3.org/TR/rdf-canon/)
algorithm (the standard that supersedes URDNA2015).

> **Status: 0.2.0.** This package was lift-and-shifted out of
> [`accredifysg/verifiable-credentials-php`](https://github.com/accredifysg/verifiable-credentials-php),
> mirroring how `accredifysg/php-json-ld` was extracted earlier. The default
> (SHA-256) canonical output is **byte-for-byte identical** to the
> implementation VC ships, because VC's `eddsa-rdfc-2022` and `ecdsa-sd-2023`
> signatures are computed over it — see
> [Parity](#parity-with-verifiable-credentials-php).

## What it does

RDFC-1.0 takes an RDF dataset and produces a canonical (deterministic)
serialization, so that two datasets that are equal up to blank-node relabelling
serialise to exactly the same bytes. That canonical form is what gets hashed and
signed by Data Integrity cryptographic suites.

This package operates purely on an **RDF dataset expressed as N-Quads**:

```
N-Quads string  ──►  RDFC10  ──►  canonical N-Quads
```

Converting JSON-LD to N-Quads (the `toRdf` algorithm) is **out of scope** and is
the caller's responsibility — use
[`accredifysg/php-json-ld`](https://github.com/accredifysg/php-json-ld)'s
`toRdf()` for that. This package intentionally has **no dependency** on
php-json-ld.

## Installation

```bash
composer require accredifysg/php-rdf-canonicalize
```

Requires **PHP 8.2+** with the `hash` (SHA-256) and `mbstring` extensions.

## Usage

```php
use Accredify\RdfCanonicalize\RDFC10;

$nquads = <<<NQ
_:b0 <https://schema.org/name> "John Doe" .
_:b0 <https://schema.org/knows> _:b1 .
_:b1 <https://schema.org/name> "Jane Doe" .
NQ;

$canonical = (new RDFC10)->canonicalize($nquads);

// $canonical is a sorted list of canonical N-Quads, one per element.
echo implode('', $canonical);
// _:c14n0 <https://schema.org/knows> _:c14n1 .
// _:c14n0 <https://schema.org/name> "John Doe" .
// _:c14n1 <https://schema.org/name> "Jane Doe" .
```

`RDFC10` implements `Accredify\RdfCanonicalize\Contracts\Canonicalizer`. After
canonicalising, `getCanonicalIdMap()` returns the map of original → canonical
blank node identifiers.

Pairing it with php-json-ld to canonicalise a JSON-LD document:

```php
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\RdfCanonicalize\RDFC10;

$nquads = (new JsonLdProcessor($loader))->toRdf($jsonLd)->toNQuads();
$canonical = (new RDFC10)->canonicalize($nquads, ['inputFormat' => 'application/n-quads']);
```

### Hash algorithm

RDFC-1.0 uses SHA-256 by default. Pass the spec's optional SHA-384 profile (or
any algorithm `hash_algos()` reports) via the constructor:

```php
$canonical = (new RDFC10(hashAlgorithm: 'sha384'))->canonicalize($nquads);
```

### Components

The N-Quads I/O is factored into two reusable, composable classes that `RDFC10`
uses internally and accepts via its constructor:

- `NQuadsParser` — N-Quads string → `RdfQuad[]`
- `NQuadsSerializer` — `RdfTerm` components → a canonical N-Quad line

## Scope

- [x] RDF Dataset Canonicalization (RDFC-1.0) over N-Quads.
- [x] SHA-256 (default) and SHA-384 hash profiles, via the `hashAlgorithm`
      constructor option.

Out of scope for the 0.x line: JSON-LD → N-Quads conversion (use php-json-ld)
and removal of duplicate input quads — see [Conformance](#conformance).

## Parity with verifiable-credentials-php

This is a **pure lift-and-shift**: the algorithm is byte-for-byte identical to
VC's in-repo RDFC10 at the point of extraction. VC's `eddsa-rdfc-2022` and
`ecdsa-sd-2023` cryptographic suites sign over this exact canonical output, so it
is a frozen, consensus-critical contract — **not** a free-to-tweak detail.

[`tests/ParityTest.php`](tests/ParityTest.php) locks this: every fixture under
`tests/Fixtures/` pairs an input dataset with the canonical output VC produced
for it, and the test asserts an exact byte match. **Do not change canonical
output** (even to improve W3C conformance) without owner sign-off and a
coordinated regeneration of VC's signed fixtures.

## Conformance

Tested against the official
[W3C rdf-canon test suite](https://github.com/w3c/rdf-canon), pulled in as a git
submodule at `tests/w3c/`. See
[`tests/W3c/README.md`](tests/W3c/README.md) for the harness layout.

```bash
git submodule update --init --recursive   # once

composer test       # unit tests + parity lock (the default gate)
composer test:w3c   # W3C conformance (informational)
composer test:all   # both
```

### Score (v0.2.0)

| Test type        | W3C suite | Passing | Notes                          |
| ---------------- | --------: | ------: | ------------------------------ |
| Eval             |        64 |      61 | 3 documented gaps              |
| Map (identifiers)|        21 |      21 | **100%**                       |
| NegativeEval     |         1 |       1 | poison clique correctly fails  |
| **Total**        |    **86** |  **83** |                                |

The 3 residual failures are **recorded, not fixed** (fixing them would change
canonical output — see [Parity](#parity-with-verifiable-credentials-php)). They
trace to 2 root causes, neither of which affects the VC pipeline (php-json-ld's
`toRdf` emits de-duplicated, spec-escaped N-Quads):

- **`#test060c` — N-Quads escaping.** `NQuadsParser`/`NQuadsSerializer` don't
  implement the full N-Quads `ECHAR`/`UCHAR` escaping grammar.
- **`#test076c` / `#test077c` — duplicate-quad removal.** RDFC-1.0 treats the
  dataset as a set; this port preserves duplicate input quads rather than
  removing them.

> The SHA-384 gap (`#test075`) was closed in v0.2.0 by the `hashAlgorithm`
> option — Map conformance is now 100%.

## License

[MIT](LICENSE) © Accredify
