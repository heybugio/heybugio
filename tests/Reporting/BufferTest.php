<?php

namespace HeyBug\Tests\Reporting;

use HeyBug\Reporting\Buffer;
use HeyBug\Reporting\Envelope;
use PHPUnit\Framework\TestCase;

class BufferTest extends TestCase
{
    public function test_it_holds_envelopes_until_taken(): void
    {
        $buffer = new Buffer;

        $this->assertTrue($buffer->isEmpty());

        $buffer->add($this->envelope('first'));
        $buffer->add($this->envelope('second'));

        $this->assertSame(2, $buffer->count());
        $this->assertFalse($buffer->isEmpty());

        $batch = $buffer->take();

        $this->assertCount(2, $batch->envelopes);
        $this->assertSame('first', $batch->envelopes[0]->payload['exception']);
    }

    public function test_taking_empties_the_buffer(): void
    {
        $buffer = new Buffer;

        $buffer->add($this->envelope('first'));
        $buffer->take();

        $this->assertTrue($buffer->isEmpty());
        $this->assertTrue($buffer->take()->isEmpty());
    }

    public function test_it_drops_incoming_envelopes_when_full(): void
    {
        $buffer = new Buffer(limit: 2);

        $this->assertTrue($buffer->add($this->envelope('first')));
        $this->assertTrue($buffer->add($this->envelope('second')));
        $this->assertFalse($buffer->add($this->envelope('third')));

        $this->assertSame(2, $buffer->count());
        $this->assertSame(1, $buffer->dropped());
    }

    public function test_it_keeps_the_earliest_envelopes_on_overflow(): void
    {
        $buffer = new Buffer(limit: 2);

        $buffer->add($this->envelope('first'));
        $buffer->add($this->envelope('second'));
        $buffer->add($this->envelope('third'));

        $batch = $buffer->take();

        $this->assertSame('first', $batch->envelopes[0]->payload['exception']);
        $this->assertSame('second', $batch->envelopes[1]->payload['exception']);
    }

    public function test_it_counts_every_drop(): void
    {
        $buffer = new Buffer(limit: 1);

        $buffer->add($this->envelope('kept'));

        foreach (range(1, 5) as $i) {
            $buffer->add($this->envelope("dropped-{$i}"));
        }

        $this->assertSame(5, $buffer->dropped());
    }

    public function test_taking_carries_the_drop_count_and_resets_it(): void
    {
        $buffer = new Buffer(limit: 1);

        $buffer->add($this->envelope('kept'));
        $buffer->add($this->envelope('dropped'));

        $batch = $buffer->take();

        // The drop travels with the batch it belongs to...
        $this->assertSame(1, $batch->dropped);

        // ...and does not compound into the next boundary.
        $this->assertSame(0, $buffer->dropped());
        $this->assertSame(0, $buffer->take()->dropped);
    }

    public function test_a_batch_of_only_drops_is_not_empty(): void
    {
        $buffer = new Buffer(limit: 0);

        $buffer->add($this->envelope('dropped'));

        $batch = $buffer->take();

        // Nothing to send, but something to report.
        $this->assertSame([], $batch->envelopes);
        $this->assertFalse($batch->isEmpty());
    }

    public function test_room_frees_up_after_taking(): void
    {
        $buffer = new Buffer(limit: 1);

        $buffer->add($this->envelope('first'));
        $buffer->take();

        $this->assertTrue($buffer->add($this->envelope('second')));
    }

    protected function envelope(string $message): Envelope
    {
        return new Envelope('default', ['exception' => $message]);
    }
}
