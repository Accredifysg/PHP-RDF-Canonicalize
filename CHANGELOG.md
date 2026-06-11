# Changelog

All notable changes to `accredifysg/php-rdf-canonicalize` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-06-11

First release. Extracts the RDF Dataset Canonicalization (RDFC-1.0)
implementation out of `accredifysg/verifiable-credentials-php` into a
standalone package, mirroring the earlier `accredifysg/php-json-ld` spin-out.

This is a **pure lift-and-shift**: the algorithm is unchanged and its canonical
N-Quads output is byte-for-byte identical to VC's in-repo RDFC10 at the point of
extraction.

### Added

- `Accredify\RdfCanonicalize\RDFC10` — the canonicalizer, operating on an RDF
  dataset supplied as N-Quads (`canonicalize(string $input, array $options = []): array`).
  Re-namespaced from `Accredify\VerifiableCredentials\Canonicalization\RDFC10`.
- `Accredify\RdfCanonicalize\Contracts\Canonicalizer` — the interface
  (re-namespaced from `…\Contracts\CanonicalizationInterface`).
- Supporting value objects / helpers: `RdfTerm`, `RdfQuad`, `Permuter`,
  `IdentifierIssuer`, `MessageDigest`.
- Parity / regression lock (`tests/ParityTest.php`) over N-Quads fixtures
  captured from VC's `toRdf()` output, asserting byte-for-byte identical
  canonical output. **This output is frozen**: VC's `eddsa-rdfc-2022` and
  `ecdsa-sd-2023` signatures depend on it, so any change requires owner sign-off
  and a coordinated VC fixture regeneration.
- W3C conformance harness wiring the official
  [w3c/rdf-canon](https://github.com/w3c/rdf-canon) suite as a git submodule
  (`tests/w3c/`) with a Pest harness (`tests/W3c/`) and `composer test:w3c` /
  `test:all` scripts.

### Notes

- **PHP requirement is `^8.2`.** The runtime code only needs PHP 8.1 features,
  but — exactly as `accredifysg/php-json-ld` v1.0.1 documented — PHP 8.1 is
  end-of-life (security support ended November 2025) and the dev toolchain
  (`pestphp/pest` → `brianium/paratest`) no longer installs on 8.1, so an `^8.1`
  claim could not be built or CI-verified. The sole consumer (VC) already
  requires `^8.2`. The CI matrix is 8.2 / 8.3 / 8.4.
- Runtime extensions: `ext-hash` (SHA-256) and `ext-mbstring` (the lifted
  `MessageDigest` normalises input with `mb_convert_encoding`).

### W3C conformance (informational)

Scored against the w3c/rdf-canon suite at release time:

```
Eval:          60 / 64 passed
Map:           20 / 21 passed
NegativeEval:   1 /  1 passed
Total:         81 / 86 passed
```

The 5 residual failures are **recorded, not fixed** — fixing them would change
canonical output and break VC signature parity. They are gated behind the same
owner-sign-off process as any output change. Root causes (none reachable through
the VC → php-json-ld `toRdf` pipeline):

- `#test060c` — full N-Quads `ECHAR`/`UCHAR` escaping is not implemented by the
  lifted parser/serializer.
- `#test075c` / `#test075m` — the optional SHA-384 hash profile is unsupported
  (the implementation uses SHA-256, the RDFC-1.0 default).
- `#test076c` / `#test077c` — duplicate input quads are not removed (RDFC-1.0
  treats the dataset as a set).
