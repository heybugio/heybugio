<?php

namespace HeyBug\Tests;

use HeyBug\Support\Dsn;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DsnTest extends TestCase
{
    public function test_it_parses_valid_dsn(): void
    {
        $dsn = Dsn::make('https://api-key-123:project-456@api.heybug.io');

        $this->assertEquals('api-key-123', $dsn->getApiKey());
        $this->assertEquals('project-456', $dsn->getProjectId());
        $this->assertEquals('https://api.heybug.io', $dsn->getServer());
    }

    public function test_it_parses_dsn_with_path(): void
    {
        $dsn = Dsn::make('https://api-key:project-id@api.heybug.io/api/log');

        $this->assertEquals('api-key', $dsn->getApiKey());
        $this->assertEquals('project-id', $dsn->getProjectId());
        // Path is ignored - server is just the host
        $this->assertEquals('https://api.heybug.io', $dsn->getServer());
    }

    public function test_it_parses_dsn_with_http(): void
    {
        $dsn = Dsn::make('http://api-key:project-id@localhost');

        $this->assertEquals('api-key', $dsn->getApiKey());
        $this->assertEquals('project-id', $dsn->getProjectId());
        $this->assertEquals('http://localhost', $dsn->getServer());
    }

    public function test_it_throws_on_missing_api_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dsn::make('https://api.heybug.io');
    }

    public function test_it_throws_on_missing_project_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dsn::make('https://api-key@api.heybug.io');
    }

    public function test_it_throws_on_invalid_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dsn::make('invalid-dsn-string');
    }

    public function test_is_valid_returns_true_for_valid_dsn(): void
    {
        $this->assertTrue(Dsn::isValid('https://key:id@api.heybug.io'));
    }

    public function test_is_valid_returns_false_for_invalid_dsn(): void
    {
        $this->assertFalse(Dsn::isValid('invalid'));
        $this->assertFalse(Dsn::isValid('https://api.heybug.io'));
        $this->assertFalse(Dsn::isValid(''));
    }

    public function test_it_handles_complex_api_keys(): void
    {
        $dsn = Dsn::make('https://abc123XYZ:550e8400-e29b-41d4-a716-446655440000@api.heybug.io');

        $this->assertEquals('abc123XYZ', $dsn->getApiKey());
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $dsn->getProjectId());
    }

    public function test_it_handles_port_in_host(): void
    {
        $dsn = Dsn::make('https://key:id@localhost:8080');

        // Port is included in host
        $this->assertEquals('https://localhost:8080', $dsn->getServer());
    }
}
