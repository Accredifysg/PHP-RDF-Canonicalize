<?php

declare(strict_types=1);

/**
 * Converts a JUnit XML log of the W3C conformance suite into a W3C EARL
 * implementation report (Turtle), suitable for submission to
 * w3c/rdf-canon's reports/ directory.
 *
 *   vendor/bin/pest --testsuite=W3C --log-junit w3c-junit.xml
 *   php scripts/generate-earl-report.php w3c-junit.xml <version> > php-rdf-canonicalize-earl.ttl
 *
 * Every manifest entry appears as exactly one earl:Assertion. The script
 * cross-checks the JUnit log against the manifest: a manifest test missing
 * from the log (or vice versa) is an error, so the report can never silently
 * under- or over-claim coverage.
 */
if ($argc < 3) {
    fwrite(STDERR, "usage: php scripts/generate-earl-report.php <junit.xml> <version> [<manifest.jsonld>]\n");
    exit(1);
}

[, $junitPath, $version] = $argv;
$manifestPath = $argv[3] ?? __DIR__.'/../tests/rdf-canon/tests/manifest.jsonld';

$manifestBase = 'https://w3c.github.io/rdf-canon/tests/manifest';
$project = 'https://github.com/Accredifysg/PHP-RDF-Canonicalize';
$assertor = 'https://github.com/Accredifysg';
$assertorName = 'Accredify';
$date = gmdate('Y-m-d');
$dateTime = gmdate('Y-m-d\TH:i:s\Z');

// Every id in the manifest ("#test001c", ...) must end up asserted.
$raw = file_get_contents($manifestPath);
if ($raw === false) {
    fwrite(STDERR, "cannot read manifest {$manifestPath}\n");
    exit(1);
}
/** @var array{entries: list<array{id: string, type: string}>} $manifest */
$manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
$expected = [];
foreach ($manifest['entries'] as $entry) {
    $expected[$entry['id']] = true;
}

$xml = simplexml_load_file($junitPath);
if ($xml === false) {
    fwrite(STDERR, "cannot parse {$junitPath}\n");
    exit(1);
}

$assertions = [];
foreach ($xml->xpath('//testcase') as $case) {
    if (preg_match('/#[a-z0-9]+/i', (string) $case['name'], $m) !== 1) {
        fwrite(STDERR, "cannot extract test id from: {$case['name']}\n");
        exit(1);
    }
    if (! isset($expected[$m[0]])) {
        fwrite(STDERR, "JUnit log contains {$m[0]}, which is not in the manifest\n");
        exit(1);
    }
    // The suite has no expected-failure list; skips would hide a gap → failed.
    $failed = isset($case->skipped) || isset($case->failure) || isset($case->error);
    $assertions[$m[0]] = ! $failed;
}

$missing = array_diff_key($expected, $assertions);
if ($missing !== []) {
    fwrite(STDERR, 'manifest tests missing from the JUnit log: '.implode(', ', array_keys($missing))."\n");
    exit(1);
}

ksort($assertions, SORT_STRING);

echo <<<TTL
@prefix dc: <http://purl.org/dc/terms/> .
@prefix doap: <http://usefulinc.com/ns/doap#> .
@prefix earl: <http://www.w3.org/ns/earl#> .
@prefix foaf: <http://xmlns.com/foaf/0.1/> .
@prefix xsd: <http://www.w3.org/2001/XMLSchema#> .

<{$project}> a earl:TestSubject, doap:Project, earl:Software;
  dc:title "PHP-RDF-Canonicalize";
  dc:creator <{$assertor}>;
  doap:description "A PHP implementation of the W3C RDF Dataset Canonicalization (RDFC-1.0) algorithm."@en;
  doap:developer <{$assertor}>;
  doap:homepage <{$project}>;
  doap:implements <https://www.w3.org/TR/rdf-canon/>;
  doap:license <{$project}/blob/main/LICENSE>;
  doap:name "PHP-RDF-Canonicalize";
  doap:programming-language "PHP";
  doap:download-page <https://packagist.org/packages/accredifysg/php-rdf-canonicalize>;
  doap:release [
    doap:created "{$date}"^^xsd:date;
    doap:name "PHP-RDF-Canonicalize {$version}";
    doap:revision "{$version}"
  ] .

<{$assertor}> a foaf:Organization, earl:Assertor;
  foaf:homepage <{$assertor}>;
  foaf:name "{$assertorName}" .

<> foaf:primaryTopic <{$project}>;
  dc:issued "{$dateTime}"^^xsd:dateTime;
  foaf:maker <{$assertor}> .


TTL;

foreach ($assertions as $test => $passed) {
    $outcome = $passed ? 'earl:passed' : 'earl:failed';
    echo <<<TTL
[
  a earl:Assertion;
  earl:assertedBy <{$assertor}>;
  earl:mode earl:automatic;
  earl:result [
    a earl:TestResult;
    dc:date "{$dateTime}"^^xsd:dateTime;
    earl:outcome {$outcome}
  ];
  earl:subject <{$project}>;
  earl:test <{$manifestBase}{$test}>
] .


TTL;
}

$total = count($assertions);
$passedCount = count(array_filter($assertions));
fwrite(STDERR, "{$total} assertions ({$passedCount} passed, ".($total - $passedCount)." failed)\n");
