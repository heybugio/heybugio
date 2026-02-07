<?php

namespace HeyBug\Tests;

use Exception;
use HeyBug\Logger\HeyBugHandler;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;

class HeyBugHandlerTest extends TestCase
{
    public function test_it_reports_exception_from_log_record(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        $handler = new HeyBugHandler($this->app['heybug']);
        $exception = new Exception('Test exception from log');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'heybug',
            level: Level::Error,
            message: 'Test error',
            context: ['exception' => $exception],
        );

        $handler->handle($record);

        Http::assertSent(function ($request) {
            return str_contains($request['exception']['exception'], 'Test exception from log');
        });
    }

    public function test_it_ignores_logs_without_exception(): void
    {
        Http::fake();

        $handler = new HeyBugHandler($this->app['heybug']);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'heybug',
            level: Level::Error,
            message: 'Test error without exception',
            context: [],
        );

        $handler->handle($record);

        Http::assertNothingSent();
    }

    public function test_it_respects_log_level(): void
    {
        Http::fake();

        $handler = new HeyBugHandler($this->app['heybug'], Level::Critical);
        $exception = new Exception('Test exception');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'heybug',
            level: Level::Error,
            message: 'Test error',
            context: ['exception' => $exception],
        );

        $this->assertFalse($handler->isHandling($record));
    }

    public function test_it_handles_critical_level(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true], 200),
        ]);

        $handler = new HeyBugHandler($this->app['heybug'], Level::Critical);
        $exception = new Exception('Critical exception');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'heybug',
            level: Level::Critical,
            message: 'Critical error',
            context: ['exception' => $exception],
        );

        $this->assertTrue($handler->isHandling($record));
        $handler->handle($record);

        Http::assertSent(function ($request) {
            return str_contains($request['exception']['exception'], 'Critical exception');
        });
    }
}
