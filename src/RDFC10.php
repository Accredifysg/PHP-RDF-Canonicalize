<?php

declare(strict_types=1);

namespace Accredify\RdfCanonicalize;

use Accredify\RdfCanonicalize\Contracts\Canonicalizer;
use Exception;

/**
 * Implementation of the RDF Dataset Canonicalization Algorithm (RDFC-1.0).
 * The implementation is based on the RDFC-1.0 algorithm which supersedes the older URDNA2015.
 *
 * @see https://www.w3.org/TR/rdf-canon/#canonicalization W3C RDF Dataset Canonicalization Specification
 * @see https://github.com/digitalbazaar/rdf-canonize Reference JavaScript Implementation
 */
class RDFC10 implements Canonicalizer
{
    /** @var array<string, array{quads: list<RdfQuad>, hash: ?string}> */
    private array $blankNodeInfo = [];

    /** @var list<RdfQuad> */
    private array $quads = [];

    /** @var array<string, int> */
    private array $deepIterations = [];

    private MessageDigest $messageDigest;

    private IdentifierIssuer $canonicalIssuer;

    /**
     * @param  array<string, string>  $canonicalIdMap  Optional seed map of original => canonical identifiers
     */
    public function __construct(
        private readonly array $canonicalIdMap = [],
        private readonly int $maxDeepIterations = PHP_INT_MAX,
        ?MessageDigest $messageDigest = null,
        ?IdentifierIssuer $canonicalIssuer = null
    ) {
        $this->messageDigest = $messageDigest ?? new MessageDigest('sha256');
        $this->canonicalIssuer = $canonicalIssuer ?? new IdentifierIssuer('_:c14n', $canonicalIdMap);
    }

    /**
     * Canonicalize an RDF dataset given as an N-Quads string (RDFC-1.0).
     *
     * RDFC-1.0 operates purely on an RDF dataset; converting JSON-LD to N-Quads
     * (via the php-json-ld toRdf algorithm) is the caller's responsibility.
     *
     * @param  string  $input  The RDF dataset serialised as N-Quads
     * @param  array<string, mixed>  $options  Accepted for interface compatibility (e.g.
     *                                         'inputFormat' => 'application/n-quads'); the input
     *                                         is always N-Quads
     * @return list<string> Canonicalized N-Quads, one quad per element
     *
     * @throws Exception
     */
    public function canonicalize(string $input, array $options = []): array
    {
        // Reset state
        $this->blankNodeInfo = [];
        $this->quads = [];
        $this->deepIterations = [];

        // CRITICAL FIX: Reset the canonical issuer to clear any state from previous canonicalizations
        // The canonicalIssuer maintains a map of blank node IDs which must be cleared between calls
        // to prevent blank nodes from previous canonicalizations from affecting current ones
        $this->canonicalIssuer = new IdentifierIssuer('_:c14n', $this->canonicalIdMap);

        // Parse the N-Quads dataset into RdfQuad objects for the algorithm below.
        $this->quads = $this->parseNQuadsString($input);

        // Process each quad and collect blank node information
        foreach ($this->quads as $quad) {
            $this->addBlankNodeQuadInfo($quad, $quad->subject);
            $this->addBlankNodeQuadInfo($quad, $quad->object);
            $this->addBlankNodeQuadInfo($quad, $quad->graph);
        }
        $quadString = array_map(function (RdfQuad $quad) {
            return $quad->toString();
        }, $this->quads);

        // Get non-normalized blank nodes
        $hashToBlankNodes = [];
        $nonNormalized = array_keys($this->blankNodeInfo);

        // Hash first degree quads
        foreach ($nonNormalized as $id) {
            $this->hashAndTrackBlankNode($id, $hashToBlankNodes);
        }

        // Process hashes in lexicographical order
        $hashes = array_keys($hashToBlankNodes);
        sort($hashes, SORT_STRING);  // Simple lexicographical sort

        // optimize away second sort, gather non-unique hashes in order as we go
        $nonUnique = [];
        foreach ($hashes as $hash) {
            // 5.4.1) If the length of identifier list is greater than 1,
            // continue to the next mapping.
            $idList = $hashToBlankNodes[$hash] ?? [];
            if (count($idList) > 1) {
                $nonUnique[] = $idList;

                continue;
            }

            // 5.4.2) Use the Issue Identifier algorithm, passing canonical
            // issuer and the single blank node identifier in identifier
            // list, identifier, to issue a canonical replacement identifier
            // for identifier.
            $id = $idList[0];
            $this->canonicalIssuer->getId($id);

            // Note: These steps are skipped, optimized away since the loop
            // only needs to be run once.
            // 5.4.3) Remove identifier from non-normalized identifiers.
            // 5.4.4) Remove hash from the hash to blank nodes map.
            // 5.4.5) Set simple to true.
        }

        // 6) For each hash to identifier list mapping in hash to blank nodes map,
        // lexicographically-sorted by hash:
        // Note: sort optimized away, use `nonUnique`.
        foreach ($nonUnique as $idList) {
            // 6.1) Create hash path list where each item will be a result of
            // running the Hash N-Degree Quads algorithm.
            $hashPathList = [];

            // 6.2) For each blank node identifier identifier in identifier list:
            foreach ($idList as $id) {
                // 6.2.1) If a canonical identifier has already been issued for
                // identifier, continue to the next identifier.
                if ($this->canonicalIssuer->hasId($id)) {
                    continue;
                }

                // 6.2.2) Create temporary issuer, an identifier issuer
                // initialized with the prefix _:b.
                $issuer = new IdentifierIssuer('_:b');

                // 6.2.3) Use the Issue Identifier algorithm, passing temporary
                // issuer and identifier, to issue a new temporary blank node
                // identifier for identifier.
                $issuer->getId($id);

                // 6.2.4) Run the Hash N-Degree Quads algorithm, passing
                // temporary issuer, and append the result to the hash path list.
                $result = $this->hashNDegreeQuads($id, $issuer);
                $hashPathList[] = $result;
            }

            // 6.3) For each result in the hash path list,
            // lexicographically-sorted by the hash in result:
            usort($hashPathList, [self::class, 'stringHashCompare']);

            foreach ($hashPathList as $result) {
                // 6.3.1) For each blank node identifier, existing identifier,
                // that was issued a temporary identifier by identifier issuer
                // in result, issue a canonical identifier, in the same order,
                // using the Issue Identifier algorithm, passing canonical
                // issuer and existing identifier.
                $oldIds = $result['issuer']->getOldIds();
                foreach ($oldIds as $id) {
                    $this->canonicalIssuer->getId($id);
                }
            }
        }

        /* Note: At this point all blank nodes in the set of RDF quads have been
        assigned canonical identifiers, which have been stored in the canonical
        issuer. Here each quad is updated by assigning each of its blank nodes
        its new identifier. */

        // 7) Process each quad in input dataset
        $normalized = [];
        foreach ($this->quads as $quad) {
            // 7.1) Create a copy, quad copy, of quad and replace any existing
            // blank node identifiers using the canonical identifiers
            // previously issued by canonical issuer.
            // Note: We optimize away the copy here.
            $nQuad = $this->serializeQuadComponents(
                $this->componentWithCanonicalId($quad->subject),
                $quad->predicate,
                $this->componentWithCanonicalId($quad->object),
                $this->componentWithCanonicalId($quad->graph)
            );

            // 7.2) Add to normalized dataset
            $normalized[] = $nQuad;
        }

        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Parse N-Quads string into RdfQuad objects
     *
     * @param  string  $nquadsString  N-Quads as a single string
     * @return list<RdfQuad> Array of RdfQuad objects
     */
    private function parseNQuadsString(string $nquadsString): array
    {
        $quads = [];
        $lines = explode("\n", trim($nquadsString));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue; // Skip empty lines and comments
            }

            $quad = $this->parseNQuadLine($line);
            if ($quad) {
                $quads[] = $quad;
            }
        }

        return $quads;
    }

    /**
     * Parse a single N-Quad line into an RdfQuad object
     *
     * @param  string  $line  N-Quad line
     * @return RdfQuad|null RdfQuad object or null if parsing fails
     */
    private function parseNQuadLine(string $line): ?RdfQuad
    {
        // Remove trailing period and whitespace
        $line = rtrim($line, " .\t\r\n");

        // Split into components (subject, predicate, object, optional graph)
        $components = $this->splitNQuadLine($line);

        if (count($components) < 3) {
            return null;
        }

        try {
            $subject = $this->parseRdfTerm($components[0]);
            $predicate = $this->parseRdfTerm($components[1]);
            $object = $this->parseRdfTerm($components[2]);
            $graph = isset($components[3]) ? $this->parseRdfTerm($components[3]) : null;

            return new RdfQuad($subject, $predicate, $object, $graph);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Split N-Quad line into components, handling quoted literals
     *
     * @param  string  $line  N-Quad line
     * @return list<string> Array of components
     */
    private function splitNQuadLine(string $line): array
    {
        $components = [];
        $current = '';
        $inQuotes = false;
        $escapeNext = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($escapeNext) {
                $current .= $char;
                $escapeNext = false;

                continue;
            }

            if ($char === '\\') {
                $current .= $char;
                $escapeNext = true;

                continue;
            }

            if ($char === '"') {
                $current .= $char;
                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && $char === ' ') {
                if ($current !== '') {
                    $components[] = $current;
                    $current = '';
                }

                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $components[] = $current;
        }

        return $components;
    }

    /**
     * Parse an RDF term from a string
     *
     * @param  string  $term  Term string
     * @return RdfTerm RdfTerm object
     */
    private function parseRdfTerm(string $term): RdfTerm
    {
        // Blank node
        if (str_starts_with($term, '_:')) {
            return RdfTerm::blankNode($term);
        }

        // Named node (IRI)
        if (str_starts_with($term, '<') && str_ends_with($term, '>')) {
            return RdfTerm::namedNode(substr($term, 1, -1));
        }

        // Literal
        if (str_starts_with($term, '"')) {
            return $this->parseLiteral($term);
        }

        // Default to named node
        return RdfTerm::namedNode($term);
    }

    /**
     * Parse a literal term
     *
     * @param  string  $term  Literal term string
     * @return RdfTerm RdfTerm object
     */
    private function parseLiteral(string $term): RdfTerm
    {
        // Pattern: "value"@lang or "value"^^<datatype> or just "value"
        if (preg_match('/^"([^"]*)"(?:@([a-z]+(?:-[a-z0-9]+)*))?(?:\^\^<([^>]+)>)?$/i', $term, $matches)) {
            $value = $matches[1];
            $language = $matches[2] ?? null;
            $datatype = $matches[3] ?? null;

            return RdfTerm::literal($value, $language, $datatype ? RdfTerm::namedNode($datatype) : null);
        }

        // Fallback: treat as plain literal
        return RdfTerm::literal(trim($term, '"'));
    }

    /**
     * Get the canonical ID map (mapping from original blank node IDs to canonical IDs)
     *
     * @return array<string, string> Mapping from original blank node IDs to canonical IDs
     */
    public function getCanonicalIdMap(): array
    {
        return $this->canonicalIssuer->existing;
    }

    private function addBlankNodeQuadInfo(RdfQuad $quad, RdfTerm $component): void
    {
        // Early return if component is null or not a blank node
        if ($component->termType !== RdfTerm::BLANK_NODE) {
            return;
        }

        $id = $component->value;

        // Initialize blank node info if it doesn't exist
        if (! isset($this->blankNodeInfo[$id])) {
            $this->blankNodeInfo[$id] = [
                'quads' => [],  // Using SplObjectStorage instead of Set
                'hash' => null,
            ];
        }

        if (! in_array($quad, $this->blankNodeInfo[$id]['quads'])) {
            $this->blankNodeInfo[$id]['quads'][] = $quad;
        }
    }

    /**
     * @param  array<string, list<string>>  $hashToBlankNodes
     */
    private function hashAndTrackBlankNode(string $id, array &$hashToBlankNodes): void
    {
        $hash = $this->hashFirstDegreeQuads($id);

        if (! isset($hashToBlankNodes[$hash])) {
            $hashToBlankNodes[$hash] = [$id];
        } else {
            $hashToBlankNodes[$hash][] = $id;
        }
    }

    private function hashFirstDegreeQuads(string $id): string
    {
        // 1) Initialize nquads to an empty list for storing quads in N-Quads format
        $nquads = [];

        // 2) Get the list of quads associated with the reference blank node id
        $info = $this->blankNodeInfo[$id];
        $quads = $info['quads'];

        // 3) For each quad in quads:
        foreach ($quads as $quad) {
            // 3.1) Serialize the quad in N-Quads format with special rules for blank nodes

            // 3.1.1) If any component in quad is a blank node, then serialize it
            // using a special identifier as follows:
            $copy = [
                'subject' => null,
                'predicate' => $quad->predicate,
                'object' => null,
                'graph' => null,
            ];

            // 3.1.2) If the blank node's existing identifier matches the reference
            // blank node identifier then use _:a, otherwise, use _:z
            $copy['subject'] = $this->modifyFirstDegreeComponent($id, $quad->subject);
            $copy['object'] = $this->modifyFirstDegreeComponent($id, $quad->object);
            $copy['graph'] = $this->modifyFirstDegreeComponent($id, $quad->graph);

            $nquads[] = $this->serializeQuad($copy);
        }

        // 4) Sort nquads in lexicographical order
        sort($nquads);

        // 5) Return the hash of the sorted, joined nquads
        $md = $this->messageDigest;
        foreach ($nquads as $nquad) {
            $md->update($nquad);
        }

        $info['hash'] = $md->digest();
        $this->blankNodeInfo[$id] = $info;

        return $info['hash'];
    }

    private function modifyFirstDegreeComponent(string $id, RdfTerm $component): RdfTerm
    {
        if ($component->termType !== RdfTerm::BLANK_NODE) {
            return $component;
        }

        /* Note: Following the same mistake in the URDNA2015(RDFC10) spec that's mentioned in JS:
       We don't use canonical ID even if available, to maintain interop */
        return RdfTerm::blankNode(
            $component->value === $id ? '_:a' : '_:z'
        );
    }

    /**
     * @param  array{subject: RdfTerm, predicate: RdfTerm, object: RdfTerm, graph: RdfTerm}  $quad
     */
    private static function serializeQuad(array $quad): string
    {
        return self::serializeQuadComponents(
            $quad['subject'],
            $quad['predicate'],
            $quad['object'],
            $quad['graph']
        );
    }

    private static function serializeQuadComponents(RdfTerm $s, RdfTerm $p, RdfTerm $o, RdfTerm $g): string
    {
        $nquad = '';

        // subject can only be NamedNode or BlankNode
        $sType = $s->termType;
        $sValue = $s->value;
        if ($sType === RdfTerm::NAMED_NODE) {
            $nquad .= "<{$sValue}>";
        } else {
            $nquad .= $sValue;
        }

        // predicate can only be NamedNode
        $pValue = $p->value;
        $nquad .= " <{$pValue}> ";

        // object is NamedNode, BlankNode, or Literal
        $oType = $o->termType;
        $oValue = $o->value;
        $oLanguage = $o->language;
        if ($oType === RdfTerm::NAMED_NODE) {
            $nquad .= "<{$oValue}>";
        } elseif ($oType === RdfTerm::BLANK_NODE) {
            $nquad .= $oValue;
        } else {
            // A Literal always carries a datatype (RdfTerm::literal() defaults
            // it to xsd:string), so this is never null in practice.
            assert($o->datatype !== null);
            $oDatatypeValue = $o->datatype->value;

            $nquad .= "\"{$oValue}\"";

            if ($oLanguage) {
                $nquad .= "@{$oLanguage}";
            }

            if ($oDatatypeValue !== RdfTerm::XSD_STRING) {
                $nquad .= "^^<{$oDatatypeValue}>";
            }
        }

        // graph can only be NamedNode or BlankNode (or DefaultGraph, but that
        // does not add to `nquad`)
        $gType = $g->termType;
        $gValue = $g->value;
        if ($gType === RdfTerm::NAMED_NODE) {
            $nquad .= " <{$gValue}>";
        } elseif ($gType === RdfTerm::BLANK_NODE) {
            $nquad .= " {$gValue}";
        }

        $nquad .= " .\n";

        return $nquad;
    }

    /**
     * @return array{hash: string, issuer: IdentifierIssuer}
     */
    private function hashNDegreeQuads(string $id, IdentifierIssuer $issuer): array
    {
        $deepIterations = $this->deepIterations[$id] ?? 0;
        if ($deepIterations > $this->maxDeepIterations) {
            throw new Exception(
                "Maximum deep iterations ({$this->maxDeepIterations}) exceeded."
            );
        }
        $this->deepIterations[$id] = $deepIterations + 1;

        // 1) Create a hash to related blank nodes map for storing hashes that
        // identify related blank nodes.
        // Note: 2) and 3) handled within `createHashToRelated`
        $md = $this->createMessageDigest();
        $hashToRelated = $this->createHashToRelated($id, $issuer);

        // 4) Create an empty string, data to hash.
        // Note: We created a hash object `md` above instead.

        // 5) For each related hash to blank node list mapping in hash to related
        // blank nodes map, sorted lexicographically by related hash:
        $hashes = array_keys($hashToRelated);
        sort($hashes, SORT_STRING);

        foreach ($hashes as $hash) {
            // 5.1) Append the related hash to the data to hash.
            $md->update($hash);

            // 5.2) Create a string chosen path.
            $chosenPath = '';

            // 5.3) Create an unset chosen issuer variable.
            $chosenIssuer = null;

            // 5.4) For each permutation of blank node list:
            $permuter = new Permuter($hashToRelated[$hash]);
            while ($permuter->hasNext()) {
                $permutation = $permuter->next();

                // 5.4.1) Create a copy of issuer, issuer copy.
                $issuerCopy = $issuer->clone();

                // 5.4.2) Create a string path.
                $path = '';

                // 5.4.3) Create a recursion list, to store blank node identifiers
                // that must be recursively processed by this algorithm.
                $recursionList = [];

                // 5.4.4) For each related in permutation:
                $nextPermutation = false;
                foreach ($permutation as $related) {
                    // 5.4.4.1) If a canonical identifier has been issued for
                    // related, append it to path.
                    if ($this->canonicalIssuer->hasId($related)) {
                        $path .= $this->canonicalIssuer->getId($related);
                    } else {
                        // 5.4.4.2) Otherwise:
                        // 5.4.4.2.1) If issuer copy has not issued an identifier for
                        // related, append related to recursion list.
                        if (! $issuerCopy->hasId($related)) {
                            $recursionList[] = $related;
                        }

                        // 5.4.4.2.2) Use the Issue Identifier algorithm, passing
                        // issuer copy and related and append the result to path.
                        $path .= $issuerCopy->getId($related);
                    }

                    // 5.4.4.3) If chosen path is not empty and the length of path
                    // is greater than or equal to the length of chosen path and
                    // path is lexicographically greater than chosen path, then
                    // skip to the next permutation.
                    // Note: Comparing path length to chosen path length can be optimized
                    // away; only compare lexicographically.
                    if (strlen($chosenPath) !== 0 && $path > $chosenPath) {
                        $nextPermutation = true;
                        break;
                    }
                }

                if ($nextPermutation) {
                    continue;
                }

                // 5.4.5) For each related in recursion list:
                foreach ($recursionList as $related) {
                    // 5.4.5.1) Set result to the result of recursively executing
                    // the Hash N-Degree Quads algorithm, passing related for
                    // identifier and issuer copy for path identifier issuer.
                    $result = $this->hashNDegreeQuads($related, $issuerCopy);

                    // 5.4.5.2) Use the Issue Identifier algorithm, passing issuer
                    // copy and related and append the result to path.
                    $path .= $issuerCopy->getId($related);

                    // 5.4.5.3) Append <, the hash in result, and > to path.
                    $path .= "<{$result['hash']}>";

                    // 5.4.5.4) Set issuer copy to the identifier issuer in
                    // result.
                    $issuerCopy = $result['issuer'];

                    // 5.4.5.5) If chosen path is not empty and the length of path
                    // is greater than or equal to the length of chosen path and
                    // path is lexicographically greater than chosen path, then
                    // skip to the next permutation.
                    // Note: Comparing path length to chosen path length can be optimized
                    // away; only compare lexicographically.
                    if (strlen($chosenPath) !== 0 && $path > $chosenPath) {
                        $nextPermutation = true;
                        break;
                    }
                }

                if ($nextPermutation) {
                    continue;
                }

                // 5.4.6) If chosen path is empty or path is lexicographically
                // less than chosen path, set chosen path to path and chosen
                // issuer to issuer copy.
                if (strlen($chosenPath) === 0 || $path < $chosenPath) {
                    $chosenPath = $path;
                    $chosenIssuer = $issuerCopy;
                }
            }

            // 5.5) Append chosen path to data to hash.
            $md->update($chosenPath);

            // 5.6) Replace issuer, by reference, with chosen issuer.
            // The first permutation always sets chosen path/issuer (chosen path
            // starts empty), so a chosen issuer is guaranteed here.
            assert($chosenIssuer !== null);
            $issuer = $chosenIssuer;
        }

        // 6) Return issuer and the hash that results from passing data to hash
        // through the hash algorithm.
        return [
            'hash' => $md->digest(),
            'issuer' => $issuer,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function createHashToRelated(string $id, IdentifierIssuer $issuer): array
    {
        // 1) Create a hash to related blank nodes map for storing hashes that
        // identify related blank nodes.
        $hashToRelated = [];

        // 2) Get a reference, quads, to the list of quads in the blank node to
        // quads map for the key identifier.
        $quads = $this->blankNodeInfo[$id]['quads'];

        // 3) For each quad in quads:
        foreach ($quads as $quad) {
            // 3.1) For each component in quad, if component is the subject, object,
            // and graph name and it is a blank node that is not identified by
            // identifier:
            // steps 3.1.1 and 3.1.2 occur in helpers:
            $this->addRelatedBlankNodeHash([
                'quad' => $quad,
                'component' => $quad->subject,
                'position' => 's',
                'id' => $id,
                'issuer' => $issuer,
                'hashToRelated' => &$hashToRelated,
            ]);

            $this->addRelatedBlankNodeHash([
                'quad' => $quad,
                'component' => $quad->object,
                'position' => 'o',
                'id' => $id,
                'issuer' => $issuer,
                'hashToRelated' => &$hashToRelated,
            ]);

            $this->addRelatedBlankNodeHash([
                'quad' => $quad,
                'component' => $quad->graph,
                'position' => 'g',
                'id' => $id,
                'issuer' => $issuer,
                'hashToRelated' => &$hashToRelated,
            ]);
        }

        return $hashToRelated;
    }

    /**
     * @param  array{quad: RdfQuad, component: RdfTerm, position: string, id: string, issuer: IdentifierIssuer, hashToRelated: array<string, list<string>>}  $params
     */
    private function addRelatedBlankNodeHash(array $params): void
    {
        $quad = $params['quad'];
        $component = $params['component'];
        $position = $params['position'];
        $id = $params['id'];
        $issuer = $params['issuer'];
        $hashToRelated = &$params['hashToRelated'];

        if (! ($component->termType === 'BlankNode' &&
            $component->value !== $id)) {
            return;
        }

        // 3.1.1) Set hash to the result of the Hash Related Blank Node
        // algorithm, passing the blank node identifier for component as
        // related, quad, path identifier issuer as issuer, and position as
        // either s, o, or g based on whether component is a subject, object,
        // graph name, respectively.
        $related = $component->value;
        $hash = $this->hashRelatedBlankNode(
            $related,
            $quad,
            $issuer,
            $position
        );

        // 3.1.2) Add a mapping of hash to the blank node identifier for
        // component to hash to related blank nodes map, adding an entry as
        // necessary.
        if (isset($hashToRelated[$hash])) {
            $hashToRelated[$hash][] = $related;
        } else {
            $hashToRelated[$hash] = [$related];
        }
    }

    private function hashRelatedBlankNode(
        string $related,
        RdfQuad $quad,
        IdentifierIssuer $issuer,
        string $position
    ): string {
        // 1) Set the identifier to use for related, preferring first the canonical
        // identifier for related if issued, second the identifier issued by issuer
        // if issued, and last, if necessary, the result of the Hash First Degree
        // Quads algorithm, passing related.
        if ($this->canonicalIssuer->hasId($related)) {
            $id = $this->canonicalIssuer->getId($related);
        } elseif ($issuer->hasId($related)) {
            $id = $issuer->getId($related);
        } else {
            // Generate hash if it doesn't exist yet
            if (! isset($this->blankNodeInfo[$related]['hash'])) {
                $this->hashFirstDegreeQuads($related);
            }
            // hashFirstDegreeQuads() populates the hash, so it is set by now.
            /** @var string $id */
            $id = $this->blankNodeInfo[$related]['hash'];
        }

        // 2) Initialize a string input to the value of position.
        // Note: We use a hash object instead.
        $md = $this->createMessageDigest();
        $md->update($position);

        // 3) If position is not g, append <, the value of the predicate in quad,
        // and > to input.
        if ($position !== 'g') {
            $md->update($this->getRelatedPredicate($quad));
        }

        // 4) Append identifier to input.
        $md->update($id);

        // 5) Return the hash that results from passing input through the hash
        // algorithm.
        return $md->digest();
    }

    private function getRelatedPredicate(RdfQuad $quad): string
    {
        return "<{$quad->predicate->value}>";
    }

    private function componentWithCanonicalId(RdfTerm $component): RdfTerm
    {
        if (
            $component->termType === RdfTerm::BLANK_NODE &&
            ! str_starts_with($component->value, $this->canonicalIssuer->prefix)
        ) {
            // Create new BlankNode with canonical ID
            return RdfTerm::blankNode(
                $this->canonicalIssuer->getId(
                    $component->value
                )
            );
        }

        return $component;
    }

    private function createMessageDigest(): MessageDigest
    {
        return new MessageDigest;
    }

    /**
     * @param  array{hash: string, issuer: IdentifierIssuer}  $a
     * @param  array{hash: string, issuer: IdentifierIssuer}  $b
     */
    private static function stringHashCompare(array $a, array $b): int
    {
        return strcmp($a['hash'], $b['hash']);
    }
}
