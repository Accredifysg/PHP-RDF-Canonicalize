<?php

declare(strict_types=1);

namespace Accredify\RdfCanonicalize;

use Accredify\RdfCanonicalize\Contracts\Canonicalizer;
use Exception;
use InvalidArgumentException;

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

    private readonly NQuadsParser $parser;

    private readonly NQuadsSerializer $serializer;

    private IdentifierIssuer $canonicalIssuer;

    /**
     * @param  array<string, string>  $canonicalIdMap  Optional seed map of original => canonical identifiers
     * @param  string  $hashAlgorithm  Hash algorithm for the digests. RDFC-1.0 uses 'sha256'
     *                                 (the default); 'sha384' is the spec's optional profile. Any
     *                                 algorithm reported by hash_algos() is accepted.
     *
     * @throws InvalidArgumentException If $hashAlgorithm is not supported by ext-hash.
     */
    public function __construct(
        private readonly array $canonicalIdMap = [],
        private readonly int $maxDeepIterations = PHP_INT_MAX,
        private readonly string $hashAlgorithm = 'sha256',
        ?NQuadsParser $parser = null,
        ?NQuadsSerializer $serializer = null,
        ?IdentifierIssuer $canonicalIssuer = null,
    ) {
        if (! in_array($hashAlgorithm, hash_algos(), true)) {
            throw new InvalidArgumentException("Unsupported hash algorithm: {$hashAlgorithm}");
        }

        $this->messageDigest = new MessageDigest($hashAlgorithm);
        $this->parser = $parser ?? new NQuadsParser;
        $this->serializer = $serializer ?? new NQuadsSerializer;
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
        $this->quads = $this->parser->parse($input);

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
            $nQuad = $this->serializer->serialize(
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
            // 3.1.2) If the blank node's existing identifier matches the reference
            // blank node identifier then use _:a, otherwise, use _:z
            $subject = $this->modifyFirstDegreeComponent($id, $quad->subject);
            $object = $this->modifyFirstDegreeComponent($id, $quad->object);
            $graph = $this->modifyFirstDegreeComponent($id, $quad->graph);

            $nquads[] = $this->serializer->serialize($subject, $quad->predicate, $object, $graph);
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
        return new MessageDigest($this->hashAlgorithm);
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
