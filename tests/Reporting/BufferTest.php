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

        $taken = $buffer->take();

        $this->assertCount(2, $taken);
        $this->assertSame('first', $taken[0]->payload['exception']);
    }

    public function test_taking_empties_the_buffer(): void
    {
        $buffer = new Buffer;

        $buffer->add($this->envelope('first'));
        $buffer->take();

        $this->assertTrue($buffer->isEmpty());
        $this->assertSame([], $buffer->take());
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

        $taken = $buffer->take();

        $this->assertSame('first', $taken[0]->payload['exception']);
        $this->assertSame('second', $taken[1]->payload['exception']);
    }

    public function test_it_counts_every_drop(): void
    {
        $buffer = new Buffer(limit: 1);

        $buffer->add($this->envelope('kept'));

        foreach (range(1, 5) as $i) {
            $buffer->add($this->envelope("dropped-{$i}"));
        }

        $this->assertSame(5, $buffer->dropped());

        $buffer->resetDropped();

        $this->assertSame(0, $buffer->dropped());
    }

    public function test_taking_does_not_clear_the_drop_count(): void
    {
        $buffer = new Buffer(limit: 1);

        $buffer->add($this->envelope('kept'));
        $buffer->add($this->envelope('dropped'));
        $buffer->take();

        // The drop has to outlive the flush, or nothing can report it.
        $this->assertSame(1, $buffer->dropped());
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
