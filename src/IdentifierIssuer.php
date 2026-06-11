<?php

/*
 * Copyright (c) 2016-2021 Digital Bazaar, Inc. All rights reserved.
 */

namespace Accredify\RdfCanonicalize;

/**
 * @internal Algorithm helper — not part of the package's public API.
 */
class IdentifierIssuer
{
    /**
     * Creates a new IdentifierIssuer. A IdentifierIssuer issues unique
     * identifiers, keeping track of any previously issued identifiers.
     *
     * @param  string  $prefix  The prefix to use ('<prefix><counter>')
     * @param  array<string, string>  $existing  An existing map of old => new identifiers
     * @param  int  $counter  The counter to use
     */
    public function __construct(
        public string $prefix,
        public array $existing = [],
        public int $counter = 0
    ) {}

    /**
     * Copies this IdentifierIssuer.
     *
     * @return IdentifierIssuer A copy of this IdentifierIssuer
     */
    public function clone(): IdentifierIssuer
    {
        return new IdentifierIssuer(
            $this->prefix,
            $this->existing,
            $this->counter
        );
    }

    /**
     * Gets the new identifier for the given old identifier, where if no old
     * identifier is given a new identifier will be generated.
     *
     * @param  string|null  $old  The old identifier to get the new identifier for
     * @return string The new identifier
     */
    public function getId(?string $old = null): string
    {
        // return existing old identifier
        if ($old !== null && isset($this->existing[$old])) {
            return $this->existing[$old];
        }

        // get next identifier
        $identifier = $this->prefix.$this->counter;
        $this->counter++;

        // save mapping
        if ($old !== null) {
            $this->existing[$old] = $identifier;
        }

        return $identifier;
    }

    /**
     * Returns true if the given old identifer has already been assigned a new
     * identifier.
     *
     * @param  string  $old  The old identifier to check
     * @return bool True if the old identifier has been assigned a new identifier,
     *              false if not
     */
    public function hasId(string $old): bool
    {
        return isset($this->existing[$old]);
    }

    /**
     * Returns all of the IDs that have been issued new IDs in the order in
     * which they were issued new IDs.
     *
     * @return list<string> The list of old IDs that has been issued new IDs in order
     */
    public function getOldIds(): array
    {
        return array_keys($this->existing);
    }
}
