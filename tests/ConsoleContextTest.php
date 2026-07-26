<?php

namespace HeyBug\Tests;

use Exception;
use HeyBug\HeyBug;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;

class ConsoleContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);
    }

    /**
     * The suite itself runs in console, so the HTTP case has to be forced.
     */
    protected function pretendNotInConsole(): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))
            ->setValue($this->app, false);
    }

    protected function reportedPayload(): array
    {
        app(HeyBug::class)->handle(new Exception('Thrown'));

        $sent = null;

        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request['exception'];

            return true;
        });

        return $sent;
    }

    public function test_a_console_report_carries_no_fabricated_request(): void
    {
        $payload = $this->reportedPayload();

        $this->assertArrayNotHasKey('method', $payload);
        $this->assertArrayNotHasKey('fullUrl', $payload);
    }

    public function test_a_console_report_names_the_command(): void
    {
        $_SERVER['argv'] = ['artisan', 'queue:work', '--queue=payments'];

        $payload = $this->reportedPayload();

        $this->assertSame('queue:work', $payload['command']);
    }

    public function test_it_never_carries_command_arguments(): void
    {
        // A command line holds whatever was typed, credentials included.
        $_SERVER['argv'] = ['artisan', 'user:reset', '--password=hunter2'];

        $payload = $this->reportedPayload();

        $this->assertSame('user:reset', $payload['command']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($payload));
    }

    public function test_it_omits_the_command_when_argv_has_none(): void
    {
        $_SERVER['argv'] = ['artisan'];

        $payload = $this->reportedPayload();

        $this->assertArrayNotHasKey('command', $payload);
    }

    public function test_a_console_report_carries_no_synthesised_headers(): void
    {
        $payload = $this->reportedPayload();

        // Symfony invents a user-agent, accept and accept-language for a
        // console request; none of it came from a client.
        $this->assertArrayNotHasKey('HEADERS', $payload['storage']);
    }

    public function test_an_http_report_still_carries_the_request(): void
    {
        $this->pretendNotInConsole();

        $payload = $this->reportedPayload();

        $this->assertSame('GET', $payload['method']);
        $this->assertNotEmpty($payload['fullUrl']);
        $this->assertArrayNotHasKey('command', $payload);
    }

    public function test_an_http_report_still_carries_headers(): void
    {
        $this->pretendNotInConsole();

        $payload = $this->reportedPayload();

        $this->assertArrayHasKey('HEADERS', $payload['storage']);
    }
}
