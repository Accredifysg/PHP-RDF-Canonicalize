<?php

declare(strict_types=1);

use Accredify\RdfCanonicalize\RDFC10;
use Accredify\RdfCanonicalize\Tests\W3c\Harness;
use Accredify\RdfCanonicalize\Tests\W3c\TestCase;

/*
|--------------------------------------------------------------------------
| W3C RDF Dataset Canonicalization (RDFC-1.0) conformance
|--------------------------------------------------------------------------
| Runs the official w3c/rdf-canon suite (tests/w3c submodule) against this
| package. This suite is INFORMATIONAL: it measures conformance and surfaces
| gaps — it does not gate. Per the package's parity guardrail, gaps are
| RECORDED (see tests/W3c/README.md and the CHANGELOG), never "fixed" by
| changing canonical output, because that output is frozen against
| verifiable-credentials-php signatures.
|
| Three test types:
|   - Eval:         canonicalize(action) must equal the canonical N-Quads result
|   - Map:          the issued original→canonical identifier map must match
|   - NegativeEval: poison datasets — canonicalization must fail. RDFC10 is
|                   unbounded by default (it would not terminate on a poison
|                   clique), so the harness bounds maxDeepIterations to force
|                   the documented "maximum deep iterations exceeded" failure.
*/

uses()->group('w3c');

dataset('rdfc10-eval', function () {
    foreach (Harness::fromDefaultLocation()->manifest() as $test) {
        if ($test->isEval()) {
            yield $test->describe() => [$test];
        }
    }
});

dataset('rdfc10-map', function () {
    foreach (Harness::fromDefaultLocation()->manifest() as $test) {
        if ($test->isMap()) {
            yield $test->describe() => [$test];
        }
    }
});

dataset('rdfc10-negative', function () {
    foreach (Harness::fromDefaultLocation()->manifest() as $test) {
        if ($test->isNegative()) {
            yield $test->describe() => [$test];
        }
    }
});

/**
 * Strip the leading "_:" blank-node prefix. The W3C identifier maps key on the
 * bare label (e.g. "e0" => "c14n0") while RDFC10::getCanonicalIdMap() keeps the
 * full "_:e0" => "_:c14n0" terms.
 */
function stripBlankPrefix(string $label): string
{
    return str_starts_with($label, '_:') ? substr($label, 2) : $label;
}

/**
 * Normalise CRLF -> LF. Canonical N-Quads is LF-only and RDFC10 emits LF; the
 * W3C fixtures are committed LF too, but a Windows checkout with
 * core.autocrlf=true rewrites the submodule's .nq files to CRLF. Stripping CR
 * keeps the comparison about canonical content, not the checkout's line endings.
 */
function nqText(string $text): string
{
    return str_replace("\r\n", "\n", $text);
}

it('canonicalizes per the W3C RDFC-1.0 eval manifest', function (TestCase $test) {
    $actual = implode('', (new RDFC10)->canonicalize($test->loadAction(), ['inputFormat' => 'application/n-quads']));

    expect(nqText($actual))->toBe(nqText($test->loadResult()));
})->with('rdfc10-eval');

it('issues canonical identifiers per the W3C RDFC-1.0 map manifest', function (TestCase $test) {
    $rdfc10 = new RDFC10;
    $rdfc10->canonicalize($test->loadAction(), ['inputFormat' => 'application/n-quads']);

    $map = [];
    foreach ($rdfc10->getCanonicalIdMap() as $from => $to) {
        $map[stripBlankPrefix($from)] = stripBlankPrefix($to);
    }

    $expected = json_decode($test->loadResult(), true);

    expect($map)->toEqual($expected);
})->with('rdfc10-map');

it('rejects poison datasets per the W3C RDFC-1.0 negative manifest', function (TestCase $test) {
    // Bound the work so a poison graph throws fast instead of running forever.
    $rdfc10 = new RDFC10(maxDeepIterations: 100);

    expect(fn () => $rdfc10->canonicalize($test->loadAction(), ['inputFormat' => 'application/n-quads']))
        ->toThrow(Exception::class);
})->with('rdfc10-negative');
