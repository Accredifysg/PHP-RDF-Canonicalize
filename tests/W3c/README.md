# W3C RDFC-1.0 Conformance Harness

This directory hosts the harness that runs the official
[W3C rdf-canon test suite](https://github.com/w3c/rdf-canon) against this
package. The suite itself is pulled in as a git submodule at `tests/w3c/`
(lowercase `c`) — these files (uppercase `C`) are the harness that drives it.

## Layout

```
tests/
├── w3c/                            # submodule: w3c/rdf-canon
│   └── tests/
│       ├── manifest.jsonld         # the single RDFC-1.0 manifest
│       └── rdfc10/                 # *-in.nq, *-rdfc10.nq, *-rdfc10map.json
└── W3c/                            # our harness (this directory)
    ├── Harness.php                 # reads + parses the manifest
    ├── TestCase.php                # value object per manifest entry
    ├── README.md
    └── Algorithms/
        └── Rdfc10Test.php          # runs RDFC10 against each case
```

The manifest has three test types:

- **Eval** (`rdfc:RDFC10EvalTest`) — `action` is an N-Quads dataset; the
  canonical output must equal the `result` N-Quads.
- **Map** (`rdfc:RDFC10MapTest`) — the issued original→canonical identifier map
  must equal the `result` JSON.
- **NegativeEval** (`rdfc:RDFC10NegativeEvalTest`) — a poison dataset;
  canonicalization must fail. `RDFC10` is unbounded by default, so the harness
  bounds `maxDeepIterations` to force the documented failure rather than hang.

## Running

```bash
git submodule update --init --recursive   # once

composer test       # unit tests + parity lock (the default gate)
composer test:w3c   # this conformance suite
composer test:all   # both
```

> **Windows / CRLF note.** The fixtures are committed LF. A Windows checkout
> with `core.autocrlf=true` rewrites the submodule's `.nq` files to CRLF; the
> eval harness normalises CRLF→LF before comparing, so the score is the same on
> every platform. (Set `git -C tests/w3c config core.autocrlf false` to keep the
> working tree byte-faithful.)

## Score (v1.0.0) — full conformance

```
Eval:          64 / 64 passed
Map:           21 / 21 passed
NegativeEval:   1 /  1 passed
Total:         86 / 86 passed   (100%)
```

The harness reads each entry's `hashAlgorithm` and constructs
`RDFC10(hashAlgorithm: …)`, so the SHA-384 cases run under SHA-384. The suite
**gates CI** — every case passes, no allowlist.

The canonical output is frozen under SemVer (it backs verifiable-credentials-php
signatures). Any future change to it is a major version bump plus a coordinated
VC fixture regeneration. The 0.x → 1.0 conformance history (dedup, canonical
escaping, SHA-384) is in the [CHANGELOG](../../CHANGELOG.md).
