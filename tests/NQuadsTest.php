<?php

declare(strict_types=1);

use Accredify\RdfCanonicalize\NQuadsParser;
use Accredify\RdfCanonicalize\NQuadsSerializer;
use Accredify\RdfCanonicalize\RdfTerm;

uses()->group('nquads');

it('parses a triple into an RdfQuad', function () {
    $quads = (new NQuadsParser)->parse('<http://example.org/s> <http://example.org/p> "o" .');

    expect($quads)->toHaveCount(1)
        ->and($quads[0]->subject->termType)->toBe(RdfTerm::NAMED_NODE)
        ->and($quads[0]->subject->value)->toBe('http://example.org/s')
        ->and($quads[0]->object->termType)->toBe(RdfTerm::LITERAL)
        ->and($quads[0]->object->value)->toBe('o');
});

it('skips comments and blank lines', function () {
    $input = "# comment\n\n_:b0 <http://example.org/p> _:b1 .\n   \n";
    $quads = (new NQuadsParser)->parse($input);

    expect($quads)->toHaveCount(1)
        ->and($quads[0]->subject->termType)->toBe(RdfTerm::BLANK_NODE)
        ->and($quads[0]->object->value)->toBe('_:b1');
});

it('parses an optional graph component', function () {
    $quads = (new NQuadsParser)->parse('_:b0 <http://example.org/p> "v" <http://example.org/g> .');

    expect($quads)->toHaveCount(1)
        ->and($quads[0]->graph->termType)->toBe(RdfTerm::NAMED_NODE)
        ->and($quads[0]->graph->value)->toBe('http://example.org/g');
});

it('parses language tags and datatypes on literals', function () {
    $quads = (new NQuadsParser)->parse(
        '_:b0 <http://example.org/p> "hi"@en .'."\n".
        '_:b0 <http://example.org/q> "5"^^<http://www.w3.org/2001/XMLSchema#integer> .'
    );

    expect($quads[0]->object->language)->toBe('en')
        ->and($quads[1]->object->datatype?->value)->toBe('http://www.w3.org/2001/XMLSchema#integer');
});

it('serializes named, blank, and literal terms; default graph is omitted', function () {
    $line = (new NQuadsSerializer)->serialize(
        RdfTerm::namedNode('http://example.org/s'),
        RdfTerm::namedNode('http://example.org/p'),
        RdfTerm::literal('o'),
        RdfTerm::graph(),
    );

    expect($line)->toBe('<http://example.org/s> <http://example.org/p> "o" .'."\n");
});

it('serializes language and datatype on literals', function () {
    $serializer = new NQuadsSerializer;

    $lang = $serializer->serialize(
        RdfTerm::blankNode('_:c14n0'),
        RdfTerm::namedNode('http://example.org/p'),
        RdfTerm::literal('hi', 'en'),
        RdfTerm::graph(),
    );

    $typed = $serializer->serialize(
        RdfTerm::blankNode('_:c14n0'),
        RdfTerm::namedNode('http://example.org/p'),
        RdfTerm::literal('5', null, RdfTerm::namedNode('http://www.w3.org/2001/XMLSchema#integer')),
        RdfTerm::graph(),
    );

    expect($lang)->toBe('_:c14n0 <http://example.org/p> "hi"@en .'."\n")
        ->and($typed)->toBe('_:c14n0 <http://example.org/p> "5"^^<http://www.w3.org/2001/XMLSchema#integer> .'."\n");
});

it('round-trips parse -> serialize for a simple dataset', function () {
    $input = '<http://example.org/s> <http://example.org/p> _:b0 .'."\n";
    $quad = (new NQuadsParser)->parse($input)[0];

    $out = (new NQuadsSerializer)->serialize($quad->subject, $quad->predicate, $quad->object, $quad->graph);

    expect($out)->toBe($input);
});

it('decodes UCHAR and ECHAR escapes when parsing a literal', function () {
    // Single-quoted PHP string so the backslash escapes reach the parser as-is.
    $quads = (new NQuadsParser)->parse('<http://a> <http://b> "A\U0001F303\tend" .');

    expect($quads[0]->object->value)->toBe("A\u{1F303}\tend");
});

it('parses an escaped quote inside a literal (regression)', function () {
    $quads = (new NQuadsParser)->parse('<http://a> <http://b> "say \"hi\"" .');

    expect($quads[0]->object->value)->toBe('say "hi"');
});

it('emits canonical ECHAR escaping for tab, quote, and backslash', function () {
    // The \uXXXX path for control code points is exercised exhaustively by the
    // W3C #test060c conformance case; here we lock the ECHAR substitutions.
    $line = (new NQuadsSerializer)->serialize(
        RdfTerm::namedNode('http://a'),
        RdfTerm::namedNode('http://b'),
        RdfTerm::literal("a\tb\"c\\d"),
        RdfTerm::graph(),
    );

    expect($line)->toBe('<http://a> <http://b> "a\tb\"c\\\\d" .'."\n");
});

it('pins the canonical escaping of every escapable byte (frozen output)', function () {
    // Every byte the serializer rewrites — the C0 controls, '"', '\', DEL —
    // followed by raw multi-byte UTF-8 that must pass through untouched. The
    // expected string is spelled out literally: signatures are computed over
    // these exact bytes, so any drift here is a breaking change.
    $escapable = implode('', array_map('chr', [...range(0x00, 0x1F), 0x22, 0x5C, 0x7F]));

    $line = (new NQuadsSerializer)->serialize(
        RdfTerm::namedNode('http://a'),
        RdfTerm::namedNode('http://b'),
        RdfTerm::literal($escapable.'héllo✓'),
        RdfTerm::graph(),
    );

    expect($line)->toBe(
        '<http://a> <http://b> "'
        .'\u0000\u0001\u0002\u0003\u0004\u0005\u0006\u0007\b\t\n\u000B\f\r'
        .'\u000E\u000F\u0010\u0011\u0012\u0013\u0014\u0015\u0016\u0017'
        .'\u0018\u0019\u001A\u001B\u001C\u001D\u001E\u001F'
        .'\"\\\\\u007F'
        .'héllo✓" .'."\n"
    );
});

it('rejects N-Quads input that is not valid UTF-8', function () {
    (new NQuadsParser)->parse('<http://a> <http://b> "caf'."\xE9".'" .');
})->throws(InvalidArgumentException::class, 'N-Quads input is not valid UTF-8.');

it('round-trips an already-canonical literal through parse then serialize', function () {
    $input = '<http://a> <http://b> "q=\" bs=\\\\ nl=\n" .'."\n";
    $quad = (new NQuadsParser)->parse($input)[0];

    $out = (new NQuadsSerializer)->serialize($quad->subject, $quad->predicate, $quad->object, $quad->graph);

    expect($out)->toBe($input);
});
