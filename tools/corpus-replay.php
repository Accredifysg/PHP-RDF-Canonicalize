<?php

declare(strict_types=1);
use Accredify\JsonLd\Contracts\DocumentLoader;
use Accredify\JsonLd\Documents\RemoteDocument;
use Accredify\JsonLd\Exceptions\DocumentLoaderException;
use Accredify\JsonLd\JsonLdProcessor;
use Accredify\RdfCanonicalize\RDFC10;

/*
 * Corpus replay — the landing gate for any change to canonical output.
 *
 * For each real VC credential it runs the production toRdf (php-json-ld) to
 * N-Quads, then canonicalizes with the RDFC10 in <rdfc10-src-dir>, and prints
 * a JSON manifest of {credential: {canon_sha1, nq_dup_lines, ...}}.
 *
 * To compare two package versions (e.g. before/after a conformance fix), run
 * it once per version and diff the manifests — see docs/CONFORMANCE_ROADMAP.md.
 * "byte-identical canon_sha1 across the whole corpus" is the green verdict;
 * any difference is a consensus change that needs owner sign-off + a
 * coordinated VC fixture regeneration.
 *
 * Usage:
 *   php tools/corpus-replay.php <jsonld-autoload> <contexts-dir> <rdfc10-src-dir> <credential.json> [more.json ...]
 *
 * Example (compare v0.2.0 vs the working tree):
 *   git worktree add /tmp/rdfc-old v0.2.0
 *   php tools/corpus-replay.php /path/php-json-ld/vendor/autoload.php /path/vc/resources/contexts /tmp/rdfc-old/src creds/*.json > old.json
 *   php tools/corpus-replay.php /path/php-json-ld/vendor/autoload.php /path/vc/resources/contexts ./src              creds/*.json > new.json
 *   diff <(jq -S . old.json) <(jq -S . new.json) && echo "GREEN: byte-identical"
 */

if ($argc < 5) {
    fwrite(STDERR, "usage: php tools/corpus-replay.php <jsonld-autoload> <contexts-dir> <rdfc10-src-dir> <credential.json>...\n");
    exit(2);
}

$jsonldAutoload = $argv[1];
$contextsDir = $argv[2];
$srcDir = $argv[3];
$creds = array_slice($argv, 4);

require $jsonldAutoload;
require "$srcDir/Contracts/Canonicalizer.php";
foreach (['RdfTerm', 'RdfQuad', 'IdentifierIssuer', 'MessageDigest', 'Permuter', 'NQuadsParser', 'NQuadsSerializer', 'RDFC10'] as $class) {
    if (is_file("$srcDir/$class.php")) {
        require "$srcDir/$class.php";
    }
}

// VC's bundled context URLs -> resources/contexts filenames
// (mirrors Accredify\VerifiableCredentials\Enums\JsonLdContextUrl).
$contextMap = [
    'https://www.w3.org/2018/credentials/v1' => 'vc_context_v1.json',
    'https://www.w3.org/ns/credentials/v2' => 'vc_context_v2.json',
    'https://www.w3.org/ns/credentials/status/v1' => 'vc_status_v1.json',
    'https://w3id.org/vc-revocation-list-2020/v1' => 'vc_revocation_list_2020_v1.json',
    'https://w3id.org/vc/status-list/2021/v1' => 'vc_status_list_2021_v1.json',
    'https://w3id.org/security/data-integrity/v2' => 'data_integrity_v2.json',
    'https://purl.imsglobal.org/spec/ob/v3p0/context.json' => 'ob_context_v3_0_0.json',
    'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.1.json' => 'ob_context_v3_0_1.json',
    'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.2.json' => 'ob_context_v3_0_2.json',
    'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json' => 'ob_context_v3_0_3.json',
    'https://purl.imsglobal.org/spec/ob/v3p0/extensions.json' => 'ob_context_v3_extensions.json',
    'https://schemas.accredify.io/idvc/individual-id/v1/context' => 'accredify_individual_id_credential_context_v1.json',
    'https://schemas.accredify.io/idvc/corporate-entity/v1/context' => 'accredify_corporate_entity_credential_context_v1.json',
    'https://schemas.accredify.io/idvc/corporate-representative/v1/context' => 'accredify_corporate_representative_credential_context_v1.json',
    'https://schemas.accredify.io/idvc/vocab/v1/vocab' => 'accredify_idvc_vocab_v1.json',
];

$loader = new class($contextsDir, $contextMap) implements DocumentLoader
{
    /** @param array<string, string> $map */
    public function __construct(private string $dir, private array $map) {}

    public function loadDocument(string $url): RemoteDocument
    {
        if (! isset($this->map[$url])) {
            throw new DocumentLoaderException("unmapped context: {$url}");
        }
        $doc = json_decode((string) file_get_contents($this->dir.'/'.$this->map[$url]), true);

        return new RemoteDocument($doc, $url);
    }
};

$processor = new JsonLdProcessor($loader);
$out = [];
foreach ($creds as $path) {
    $name = basename($path);
    try {
        $cred = json_decode((string) file_get_contents($path), true);
        $nq = $processor->toRdf($cred)->toNQuads();
        $canon = implode('', (new RDFC10)->canonicalize($nq, ['inputFormat' => 'application/n-quads']));
        $lines = array_values(array_filter(explode("\n", trim($nq)), static fn ($l) => $l !== ''));
        $out[$name] = [
            'ok' => true,
            'nq_lines' => count($lines),
            'nq_dup_lines' => count($lines) - count(array_unique($lines)),
            'canon_sha1' => sha1($canon),
            'canon_len' => strlen($canon),
        ];
    } catch (Throwable $t) {
        $out[$name] = ['ok' => false, 'error' => get_class($t).': '.$t->getMessage()];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT)."\n";
