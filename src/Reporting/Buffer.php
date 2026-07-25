<?php

namespace HeyBug\Reporting;

/**
 * A bounded, in-memory queue of reports awaiting delivery.
 *
 * A web request or a single job is naturally bounded, but an Octane tick, a
 * long-running console command, or a job throwing inside a loop is not. The
 * cap exists so that a buffer in a long-lived worker cannot grow without
 * limit — the failure mode that would make deferred delivery worse than the
 * synchronous POST it replaces.
 *
 * Overflow drops the *incoming* envelope rather than evicting the oldest.
 * When a process is generating more reports than the boundary can flush, the
 * earliest ones describe the onset and the later ones are usually the same
 * fault repeating, so the head of the buffer is the more informative half.
 *
 * Drops are counted rather than silently discarded, so callers can surface
 * them. Silent loss in an error tracker has no external symptom.
 */
class Buffer
{
    /**
     * @var list<Envelope>
     */
    protected array $envelopes = [];

    protected int $dropped = 0;

    public function __construct(protected int $limit = 100) {}

    /**
     * Add an envelope, returning false if the buffer was full.
     */
    public function add(Envelope $envelope): bool
    {
        if (count($this->envelopes) >= $this->limit) {
            $this->dropped++;

            return false;
        }

        $this->envelopes[] = $envelope;

        return true;
    }

    /**
     * Take everything this boundary is responsible for, emptying the buffer.
     *
     * The buffer is cleared before the caller does anything with the batch,
     * so a failing flush cannot leave the same reports queued for the next
     * boundary and deliver them twice. That costs the batch on failure
     * rather than risking duplicates; once envelopes carry a client-minted
     * ID the server can collapse retries, and a re-queueing variant of this
     * method becomes safe to add alongside it.
     *
     * The drop count is returned and reset together with the envelopes so
     * the two cannot drift apart across boundaries.
     */
    public function take(): Batch
    {
        $batch = new Batch($this->envelopes, $this->dropped);

        $this->envelopes = [];
        $this->dropped = 0;

        return $batch;
    }

    public function isEmpty(): bool
    {
        return $this->envelopes === [];
    }

    public function count(): int
    {
        return count($this->envelopes);
    }

    /**
     * How many envelopes have been dropped for overflow since the last take.
     *
     * Read-only; taking a batch is what clears it. There is deliberately no
     * separate reset, so no caller can clear the count without also taking
     * responsibility for the envelopes it belongs to.
     */
    public function dropped(): int
    {
        return $this->dropped;
    }

    public function limit(): int
    {
        return $this->limit;
    }
}
