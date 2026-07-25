<?php

namespace HeyBug\Tests\Reporting;

use HeyBug\Reporting\Envelope;
use PHPUnit\Framework\TestCase;

class EnvelopeTest extends TestCase
{
    public function test_it_carries_an_empty_id_slot(): void
    {
        $envelope = new Envelope('default', ['exception' => 'Boom']);

        $this->assertNull($envelope->id);
    }

    public function test_it_omits_the_id_from_the_body_while_unset(): void
    {
        $envelope = new Envelope('default', ['exception' => 'Boom']);

        // Servers that still assign their own IDs must see an unchanged body.
        $this->assertSame(['exception' => 'Boom'], $envelope->toArray());
    }

    public function test_filling_the_id_slot_adds_it_to_the_body(): void
    {
        $envelope = new Envelope('default', ['exception' => 'Boom']);

        $envelope->id = '0193c8f2-1b2e-7000-8000-000000000000';

        $this->assertSame([
            'id' => '0193c8f2-1b2e-7000-8000-000000000000',
            'exception' => 'Boom',
        ], $envelope->toArray());
    }
}
