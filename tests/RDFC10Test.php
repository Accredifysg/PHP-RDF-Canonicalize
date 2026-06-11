<?php

declare(strict_types=1);

use Accredify\RdfCanonicalize\RDFC10;

uses()->group('canonicalization');

/**
 * Loads a characterization fixture pair.
 *
 * @return array{input: string, expected: string}
 */
function rdfcFixture(string $name): array
{
    $dir = __DIR__.'/Fixtures/'.$name;

    return [
        'input' => (string) file_get_contents($dir.'/input.nq'),
        'expected' => (string) file_get_contents($dir.'/expected.nq'),
    ];
}

dataset('canonicalization-cases', function () {
    /** @var array<string, string> $manifest */
    $manifest = require __DIR__.'/Fixtures/cases.php';

    foreach ($manifest as $name => $description) {
        yield $description => [$name];
    }
});

// These re-frame verifiable-credentials-php's RDFC10Test at this package's
// real boundary (N-Quads in, canonical N-Quads out). The expected bytes are
// VC's exact output — see tests/Fixtures/cases.php and tests/ParityTest.php.
it('canonicalizes', function (string $name) {
    ['input' => $input, 'expected' => $expected] = rdfcFixture($name);

    $result = (new RDFC10)->canonicalize($input, ['inputFormat' => 'application/n-quads']);

    expect($result)->toBeArray()
        ->and(implode('', $result))->toBe($expected);
})->with('canonicalization-cases');

it('returns an empty result for empty input', function () {
    expect((new RDFC10)->canonicalize(''))->toBe([]);
});

it('skips comment and blank lines when parsing N-Quads', function () {
    $input = "# a comment line\n"
        ."\n"
        .'_:b0 <https://schema.org/name> "X" .'."\n"
        ."   \n"
        ."# trailing comment\n";

    $result = (new RDFC10)->canonicalize($input);

    expect(implode('', $result))->toBe('_:c14n0 <https://schema.org/name> "X" .'."\n");
});

it('does not leak state between calls on a reused instance', function () {
    $rdfc10 = new RDFC10;

    ['input' => $a, 'expected' => $expectedA] = rdfcFixture('hash-collisions');
    ['input' => $b, 'expected' => $expectedB] = rdfcFixture('lists');

    $firstA = $rdfc10->canonicalize($a);
    $rdfc10->canonicalize($b);          // a different dataset on the same instance
    $secondA = $rdfc10->canonicalize($a); // …then back to the first

    expect(implode('', $firstA))->toBe($expectedA)
        ->and($secondA)->toBe($firstA)                       // reuse is deterministic
        ->and(implode('', $rdfc10->canonicalize($b)))->toBe($expectedB);
});

it('exposes the original-to-canonical blank node identifier map', function () {
    ['input' => $input] = rdfcFixture('nested-blank-nodes');

    $rdfc10 = new RDFC10;
    $rdfc10->canonicalize($input);

    $map = $rdfc10->getCanonicalIdMap();

    expect($map)->toHaveCount(2)
        ->and($map['_:b0'])->toBe('_:c14n0')
        ->and($map['_:b1'])->toBe('_:c14n1');
});
