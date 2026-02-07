<?php

namespace HeyBug\Tests;

use Illuminate\Support\Facades\Http;

class TestCommandTest extends TestCase
{
    public function test_it_sends_test_exception(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        $this->artisan('heybug:test')
            ->expectsOutput('HeyBug Configuration Test')
            ->expectsOutput('Sending test exception...')
            ->expectsOutput('✓ Test exception sent successfully!')
            ->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains($request['exception']['exception'], 'HeyBug test exception');
        });
    }

    public function test_it_sends_custom_exception_message(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        $this->artisan('heybug:test', ['exception' => 'Custom test message'])
            ->expectsOutput('✓ Test exception sent successfully!')
            ->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains($request['exception']['exception'], 'Custom test message');
        });
    }

    public function test_it_fails_when_request_fails(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Server error'], 500),
        ]);

        $this->artisan('heybug:test')
            ->expectsOutput('Sending test exception...')
            ->expectsOutput('✗ Failed to send test exception.')
            ->assertFailed();
    }

    public function test_it_fails_without_configuration(): void
    {
        // Use empty strings to simulate missing config (real-world scenario)
        config(['heybug.dsn' => '']);
        config(['heybug.api_key' => '']);
        config(['heybug.project_id' => '']);

        $this->artisan('heybug:test')
            ->expectsOutput('✗ No DSN or API credentials configured.')
            ->assertFailed();
    }

    public function test_it_shows_exception_id_on_success(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'exc-12345'], 200),
        ]);

        $this->artisan('heybug:test')
            ->expectsOutputToContain('Exception ID: exc-12345')
            ->assertSuccessful();
    }
}
