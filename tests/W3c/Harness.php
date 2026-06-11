<?php

declare(strict_types=1);

namespace Accredify\RdfCanonicalize\Tests\W3c;

use Accredify\RdfCanonicalize\RDFC10;
use JsonException;
use RuntimeException;

/**
 * Reads the W3C rdf-canon test manifest and yields the individual test cases.
 *
 * The test suite (tests/w3c/) is a git submodule pointing at
 * https://github.com/w3c/rdf-canon. Its single manifest
 * (tests/w3c/tests/manifest.jsonld) is a JSON-LD document whose `entries`
 * list contains the RDFC-1.0 test cases.
 *
 * This class is intentionally narrow: it just parses the manifest. The actual
 * execution lives in the Pest harness (tests/W3c/Algorithms/Rdfc10Test.php),
 * which runs {@see RDFC10} and compares its output
 * against the fixture pointed to by each {@see TestCase}.
 */
final class Harness
{
    public function __construct(
        private readonly string $manifestsRoot,
    ) {
        if (! is_dir($manifestsRoot)) {
            throw new RuntimeException(sprintf(
                'W3C tests directory not found at %s. Run `git submodule update --init --recursive`.',
                $manifestsRoot,
            ));
        }
    }

    public static function fromDefaultLocation(): self
    {
        return new self(__DIR__.'/../w3c/tests');
    }

    /**
     * @return iterable<string, TestCase>
     */
    public function manifest(string $name = 'manifest.jsonld'): iterable
    {
        $manifestPath = $this->manifestsRoot.'/'.$name;
        if (! is_file($manifestPath)) {
            throw new RuntimeException("Manifest not found: {$manifestPath}");
        }

        $data = $this->decodeJsonFile($manifestPath);
        $entries = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = isset($entry['id']) && is_string($entry['id']) ? $entry['id'] : null;
            $rawType = isset($entry['type']) && is_string($entry['type']) ? $entry['type'] : null;
            $action = isset($entry['action']) && is_string($entry['action']) ? $entry['action'] : null;
            if ($id === null || $rawType === null || $action === null) {
                continue;
            }

            $type = $this->shortType($rawType);
            if ($type === null) {
                continue;
            }

            $result = isset($entry['result']) && is_string($entry['result']) ? $entry['result'] : null;

            yield $id => new TestCase(
                id: $id,
                name: isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '',
                type: $type,
                rawType: $rawType,
                complexity: isset($entry['computationalComplexity']) && is_string($entry['computationalComplexity'])
                    ? $entry['computationalComplexity']
                    : '',
                actionPath: $this->manifestsRoot.'/'.$action,
                resultPath: $result !== null ? $this->manifestsRoot.'/'.$result : null,
            );
        }
    }

    private function shortType(string $rawType): ?string
    {
        return match ($rawType) {
            'rdfc:RDFC10EvalTest' => 'Eval',
            'rdfc:RDFC10MapTest' => 'Map',
            'rdfc:RDFC10NegativeEvalTest' => 'NegativeEval',
            default => null,
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException("Failed to parse manifest {$path}: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Expected manifest at {$path} to decode to a JSON object/array, got ".gettype($decoded));
        }

        return $decoded;
    }
}
