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

## Score (v0.2.0)

```
Eval:          61 / 64 passed
Map:           21 / 21 passed   (100%)
NegativeEval:   1 /  1 passed
Total:         83 / 86 passed
```

The harness reads each entry's `hashAlgorithm` and constructs
`RDFC10(hashAlgorithm: …)`, so the SHA-384 cases (`#test075c` / `#test075m`)
now pass — that gap was closed in v0.2.0.

The 3 residual failures are **recorded, not fixed**. This package's default
canonical output backs verifiable-credentials-php signatures; changing it to
chase conformance needs owner sign-off and a coordinated VC fixture regeneration
(see the repo README and CHANGELOG). The gaps trace to two root causes, neither
reachable via VC's php-json-ld `toRdf` pipeline:

| Test(s)                 | Gap                                                        |
| ----------------------- | ---------------------------------------------------------- |
| `#test060c`             | full N-Quads `ECHAR`/`UCHAR` escaping not implemented       |
| `#test076c` `#test077c` | duplicate input quads not removed (RDFC-1.0 dataset is a set)|

Each future conformance PR (once unfrozen) should re-run `composer test:w3c`,
record the before/after passing count, and never regress it.
