<?php

namespace Accredify\RdfCanonicalize;

/**
 * A Permuter iterates over all possible permutations of the given array
 * of elements using the Steinhaus-Johnson-Trotter algorithm.
 */
class Permuter
{
    /** @var array<int, string> Original array, sorted (a list; index-keyed by construction) */
    private array $current;

    /** @var bool Indicates whether there are more permutations */
    private bool $done = false;

    /** @var array<string, bool> Directional info for permutation algorithm */
    private array $dir = [];

    /**
     * @param  list<string>  $list  the array of elements to iterate over
     */
    public function __construct(array $list)
    {
        sort($list);
        $this->current = $list;

        // Initialize directional info
        foreach ($list as $element) {
            $this->dir[$element] = true;
        }
    }

    /**
     * Returns true if there is another permutation.
     */
    public function hasNext(): bool
    {
        return ! $this->done;
    }

    /**
     * Gets the next permutation. Call hasNext() to ensure there is another one first.
     *
     * @return array<int, string>
     */
    public function next(): array
    {
        // Copy current permutation to return it
        $rval = $this->current;

        /* Calculate the next permutation using the Steinhaus-Johnson-Trotter
         permutation algorithm. */

        // Get largest mobile element k
        // (mobile: element is greater than the one it is looking at)
        $k = null;
        $pos = 0;
        $length = count($this->current);

        for ($i = 0; $i < $length; $i++) {
            $element = $this->current[$i];
            $left = $this->dir[$element];

            if (($k === null || $element > $k) &&
                (($left && $i > 0 && $element > $this->current[$i - 1]) ||
                    (! $left && $i < ($length - 1) && $element > $this->current[$i + 1]))
            ) {
                $k = $element;
                $pos = $i;
            }
        }

        // No more permutations
        if ($k === null) {
            $this->done = true;
        } else {
            // Swap k and the element it is looking at
            $swap = $this->dir[$k] ? $pos - 1 : $pos + 1;
            $this->current[$pos] = $this->current[$swap];
            $this->current[$swap] = $k;

            // Reverse the direction of all elements larger than k
            foreach ($this->current as $element) {
                if ($element > $k) {
                    $this->dir[$element] = ! $this->dir[$element];
                }
            }
        }

        return $rval;
    }
}
