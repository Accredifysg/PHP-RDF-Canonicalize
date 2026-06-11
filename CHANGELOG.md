# Changelog

All notable changes to `accredifysg/php-rdf-canonicalize` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-06-11

First stable release. The public API **and** the canonical output are now
covered by [Semantic Versioning](https://semver.org/spec/v2.0.0.html) —
breaking either bumps the major version.

### Full W3C conformance

Passes the entire w3c/rdf-canon suite — **86 / 86** (Eval 64/64, Map 21/21,
NegativeEval 1/1), up from 83/86 at v0.2.0. The two remaining gaps were closed:

- **Duplicate-quad removal** (`#test076c`, `#test077c`): the canonicalized
  dataset is now treated as a set.
- **Canonical N-Quads escaping** (`#test060c`): `NQuadsParser` decodes UCHAR /
  ECHAR escapes and `NQuadsSerializer` emits the RDF 1.1 canonical escaping.

These affect canonical output **only** for inputs with duplicate quads or
non-canonical escaping — neither of which the VC → php-json-ld `toRdf` pipeline
produces. Verified with `tools/corpus-replay.php`: replaying the full
`toRdf → canonicalize` pipeline over real signed credentials yields
**byte-identical** output to v0.2.0, so existing VC signatures are unaffected.

The W3C conformance suite now **gates CI** (no allowlist — every case passes).

### Public API (frozen)

`RDFC10`, `Contracts\Canonicalizer`, `NQuadsParser`, `NQuadsSerializer`,
`RdfTerm`, `RdfQuad`. The algorithm-internal helpers (`Permuter`,
`IdentifierIssuer`, `MessageDigest`) are marked `@internal` and are not part of
the frozen surface. Requires PHP `^8.2` with `ext-hash` and `ext-mbstring`.

## [0.2.0] - 2026-06-11

Internal structure + an opt-in hash profile. **The default (SHA-256) canonical
output is unchanged and byte-for-byte identical to 0.1.0** — the parity lock and
the W3C SHA-256 cases stay green.

### Added

- `hashAlgorithm` constructor option on `RDFC10` (default `'sha256'`). It
  selects the digest used throughout canonicalization; pass `'sha384'` for the
  RDFC-1.0 optional profile. Any algorithm reported by `hash_algos()` is
  accepted; anything else throws `InvalidArgumentException`. This closes the W3C
  `#test075c` / `#test075m` SHA-384 conformance gap.
- `NQuadsParser` and `NQuadsSerializer` — the N-Quads I/O, extracted from
  `RDFC10` into dedicated, reusable classes. `RDFC10` composes them and accepts
  custom instances via its constructor. The known N-Quads conformance gaps now
  live in `NQuadsParser`/`NQuadsSerializer`, isolated from the algorithm.

### Changed

- `RDFC10` constructor: the internal `MessageDigest` injection parameter is
  replaced by `hashAlgorithm` (string) plus optional `NQuadsParser` /
  `NQuadsSerializer` parameters. (Unpublished 0.1.0 → no external impact.)

### W3C conformance

```
            v0.1.0      v0.2.0
Eval:       60 / 64     61 / 64    (+1 SHA-384)
Map:        20 / 21     21 / 21    (+1 SHA-384 — now 100%)
Negative:    1 /  1      1 /  1
Total:      81 / 86     83 / 86
```

Remaining gaps (recorded, not fixed — changing output needs owner sign-off +
coordinated VC fixture regeneration): `#test060c` (full N-Quads `ECHAR`/`UCHAR`
escaping) and `#test076c` / `#test077c` (duplicate-quad removal). Neither is
reachable through VC's php-json-ld `toRdf` pipeline.

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
