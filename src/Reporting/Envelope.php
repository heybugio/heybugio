<?php

namespace HeyBug\Reporting;

/**
 * A single report on its way to the server.
 *
 * Envelopes are what the buffer holds, rather than raw payload arrays, so
 * that everything the buffer touches already has somewhere to put an ID.
 * The slot is deliberately empty for now: once the server accepts a
 * client-supplied identifier, minting one becomes filling this field
 * instead of reworking every caller.
 */
class Envelope
{
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public ?string $id = null,
    ) {}

    /**
     * The body to send for this envelope.
     *
     * The ID is omitted while it is unset so that the wire format is
     * unchanged for servers that still assign their own.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->id === null
            ? $this->payload
            : array_merge(['id' => $this->id], $this->payload);
    }
}
