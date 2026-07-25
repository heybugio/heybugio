<?php

namespace HeyBug\Reporting;

/**
 * Everything a single flush is responsible for.
 *
 * The drop count travels with the envelopes rather than being read from the
 * buffer separately, so a flusher cannot report envelopes from this boundary
 * alongside a drop count that has been accumulating across several.
 */
class Batch
{
    /**
     * @param  list<Envelope>  $envelopes
     */
    public function __construct(
        public readonly array $envelopes,
        public readonly int $dropped,
    ) {}

    public function isEmpty(): bool
    {
        return $this->envelopes === [] && $this->dropped === 0;
    }

    public function count(): int
    {
        return count($this->envelopes);
    }
}
