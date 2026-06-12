# PHP-RDF-Canonicalize

![CI](https://img.shields.io/github/actions/workflow/status/accredifysg/php-rdf-canonicalize/ci.yml?branch=main)
![Packagist Version](https://img.shields.io/packagist/v/accredifysg/php-rdf-canonicalize)
![PHP Version](https://img.shields.io/packagist/php-v/accredifysg/php-rdf-canonicalize)
![License](https://img.shields.io/packagist/l/accredifysg/php-rdf-canonicalize)
![W3C rdf-canon](https://img.shields.io/badge/W3C%20rdf--canon-86%2F86-brightgreen)

A PHP implementation of the W3C
[RDF Dataset Canonicalization (RDFC-1.0)](https://www.w3.org/TR/rdf-canon/)
algorithm (the standard that supersedes URDNA2015).

> **Status: stable (1.0).** Extracted from
> `accredifysg/verifiable-credentials-php`
> (mirroring how `accredifysg/php-json-ld` was), and **fully conformant with the
> W3C rdf-canon suite (86/86)**. The public API **and** the canonical output are
> covered by [Semantic Versioning](https://semver.org/) — breaking either bumps
> the major version. The output is consensus-critical: VC's `eddsa-rdfc-2022`
> and `ecdsa-sd-2023` signatures are computed over it — see
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
composer require accredifysg/php-rdf-canonicalize:^1.0
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

- [x] RDF Dataset Canonicalization (RDFC-1.0) over N-Quads — **full W3C
      conformance (86/86)**.
- [x] SHA-256 (default) and SHA-384 hash profiles, via the `hashAlgorithm`
      constructor option.

Out of scope for the 1.x line: JSON-LD → N-Quads conversion (use php-json-ld).

## Parity with verifiable-credentials-php

The canonical output is a **frozen, consensus-critical contract**: VC's
`eddsa-rdfc-2022` and `ecdsa-sd-2023` suites sign over it. For the N-Quads VC
actually produces (via php-json-ld's `toRdf`), the output is **byte-for-byte
identical** to the implementation VC originally shipped — verified by replaying
the full pipeline over real signed credentials (see
[`tools/corpus-replay.php`](tools/corpus-replay.php)).

[`tests/ParityTest.php`](tests/ParityTest.php) locks this with exact-byte
fixtures. Under SemVer, **changing the canonical output is a major version bump**,
paired with a coordinated regeneration of VC's signed fixtures.

## Conformance

Tested against the official
[W3C rdf-canon test suite](https://github.com/w3c/rdf-canon), pulled in as a git
submodule at `tests/w3c/`. See
[`tests/W3c/README.md`](tests/W3c/README.md) for the harness layout.

```bash
git submodule update --init --recursive   # once

composer test       # unit tests + parity lock (the default gate)
composer test:w3c   # W3C conformance (full — gates CI)
composer test:all   # both
```

### Score (v1.0.0) — full conformance

| Test type         | W3C suite | Passing |
| ----------------- | --------: | ------: |
| Eval              |        64 |      64 |
| Map (identifiers) |        21 |      21 |
| NegativeEval      |         1 |       1 |
| **Total**         |    **86** |  **86** |

The W3C suite **gates CI** (no allowlist — every case passes). The canonical
output is frozen under SemVer; a future change would be a major version bump
plus a coordinated regeneration of VC's signed fixtures. See the
[CHANGELOG](CHANGELOG.md) for the 0.x → 1.0 conformance history.

## License

[MIT](LICENSE) © Accredify
