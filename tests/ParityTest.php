<?php

declare(strict_types=1);

use Accredify\RdfCanonicalize\RDFC10;

/*
|--------------------------------------------------------------------------
| Parity / regression lock
|--------------------------------------------------------------------------
|
| This package was lift-and-shifted out of accredifysg/verifiable-credentials-php.
| VC's eddsa-rdfc-2022 and ecdsa-sd-2023 cryptographic suites sign over the
| EXACT canonical N-Quads this implementation emits — so the output is a
| consensus-critical, frozen contract, not just a test expectation.
|
| Every fixture under tests/Fixtures/ pairs an input dataset (input.nq) with
| the canonical output VC produced for it (expected.nq). This test asserts the
| package reproduces those bytes EXACTLY, for every fixture, with no
| normalisation or tolerance.
|
| If this test fails, the canonical output has drifted. Do NOT update the
| fixtures to make it pass — a byte change here changes VC signatures and is
| only allowed with owner sign-off plus a coordinated VC fixture regeneration
| (the same gate that governed PR 3.4). W3C conformance gaps are RECORDED, not
| fixed, here — see tests/W3c and the CHANGELOG.
*/

uses()->group('parity');

dataset('parity-fixtures', function () {
    $root = __DIR__.'/Fixtures';

    foreach (scandir($root) ?: [] as $name) {
        $dir = $root.'/'.$name;
        if ($name === '.' || $name === '..' || ! is_dir($dir)) {
            continue;
        }
        if (! is_file($dir.'/input.nq') || ! is_file($dir.'/expected.nq')) {
            continue;
        }

        yield $name => [$name];
    }
});

it('reproduces VC canonical output byte-for-byte', function (string $name) {
    $dir = __DIR__.'/Fixtures/'.$name;
    $input = (string) file_get_contents($dir.'/input.nq');
    $expected = (string) file_get_contents($dir.'/expected.nq');

    $actual = implode('', (new RDFC10)->canonicalize($input, ['inputFormat' => 'application/n-quads']));

    expect($actual)->toBe($expected);
})->with('parity-fixtures');
