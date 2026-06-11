<?php

declare(strict_types=1);

namespace Accredify\RdfCanonicalize\Tests\W3c;

use RuntimeException;

/**
 * One entry in the W3C rdf-canon test manifest, normalised into a value object.
 *
 * Created by {@see Harness}. Consumed by the Pest harness in
 * tests/W3c/Algorithms/Rdfc10Test.php, which runs RDFC10 over the action file
 * and compares against the result file pointed to here.
 *
 * The manifest (tests/w3c/tests/manifest.jsonld) entry shape is:
 *
 *   {
 *     "id": "#test001c",
 *     "type": "rdfc:RDFC10EvalTest",
 *     "name": "simple id",
 *     "computationalComplexity": "low",
 *     "action": "rdfc10/test001-in.nq",
 *     "result": "rdfc10/test001-rdfc10.nq"
 *   }
 *
 * Three test types are recognised:
 *   - Eval         (rdfc:RDFC10EvalTest):         result is canonical N-Quads
 *   - Map          (rdfc:RDFC10MapTest):          result is a JSON identifier map
 *   - NegativeEval (rdfc:RDFC10NegativeEvalTest): canonicalization must fail
 *     (poison datasets); these have no result file.
 */
final class TestCase
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $rawType,
        public readonly string $complexity,
        public readonly string $actionPath,
        public readonly ?string $resultPath,
    ) {}

    public function isEval(): bool
    {
        return $this->type === 'Eval';
    }

    public function isMap(): bool
    {
        return $this->type === 'Map';
    }

    public function isNegative(): bool
    {
        return $this->type === 'NegativeEval';
    }

    public function loadAction(): string
    {
        return $this->readFile($this->actionPath, 'action');
    }

    public function loadResult(): string
    {
        if ($this->resultPath === null) {
            throw new RuntimeException("Test {$this->id} has no result file");
        }

        return $this->readFile($this->resultPath, 'result');
    }

    public function describe(): string
    {
        return sprintf('%s — %s', $this->id, $this->name);
    }

    private function readFile(string $path, string $label): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("{$label} file not found at {$path}");
        }

        return (string) file_get_contents($path);
    }
}
