<?php

namespace Accredify\RdfCanonicalize;

class MessageDigest
{
    private string $data = '';

    /**
     * Creates a new MessageDigest.
     *
     * @param  string  $algorithm  the hash algorithm to use.
     */
    public function __construct(
        private string $algorithm = 'sha256'
    ) {}

    /**
     * Updates the hash with new data.
     *
     * @param  string  $msg  The message to hash
     */
    public function update(string $msg): void
    {
        // Ensure UTF-8 encoding like Node.js crypto
        $this->data .= mb_convert_encoding($msg, 'UTF-8');
    }

    /**
     * Returns the hash value in hex format
     *
     * @return string The hash value
     */
    public function digest(): string
    {
        $hash = hash($this->algorithm, $this->data);
        $this->data = ''; // Reset the data after digest

        return $hash;
    }
}
