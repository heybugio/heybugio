<?php

namespace HeyBug\Tests;

use Exception;
use HeyBug\HeyBug;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class HeyBugTest extends TestCase
{
    public function test_it_can_report_an_exception(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        $heybug = app(HeyBug::class);
        $result = $heybug->handle(new Exception('Test exception'));

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-HeyBug-DSN')
                && $request['exception']['exception'] === 'Test exception'
                && $request['exception']['class'] === Exception::class;
        });
    }

    public function test_it_will_not_crash_on_http_error(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Server error'], 500),
        ]);

        $heybug = app(HeyBug::class);
        $result = $heybug->handle(new Exception('Test exception'));

        $this->assertFalse($result);
    }

    public function test_it_can_skip_exceptions_based_on_class(): void
    {
        Http::fake();

        config(['heybug.except' => [Exception::class]]);

        $heybug = app(HeyBug::class);
        $result = $heybug->handle(new Exception('Test exception'));

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_it_can_skip_exceptions_based_on_environment(): void
    {
        Http::fake();

        config(['heybug.environments' => ['production']]);

        $heybug = app(HeyBug::class);
        $result = $heybug->handle(new Exception('Test exception'));

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_it_can_add_custom_context(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        HeyBug::context(['order_id' => 123, 'user_plan' => 'premium']);

        $heybug = app(HeyBug::class);
        $heybug->handle(new Exception('Test exception'));

        Http::assertSent(function ($request) {
            return $request['exception']['custom_data']['order_id'] === 123
                && $request['exception']['custom_data']['user_plan'] === 'premium';
        });
    }

    public function test_it_clears_context_after_report(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        HeyBug::context(['order_id' => 123]);

        $heybug = app(HeyBug::class);
        $heybug->handle(new Exception('First exception'));

        // Second exception should not have custom_data
        $heybug->handle(new Exception('Second exception'));

        Http::assertSentCount(2);

        $requests = Http::recorded();
        $this->assertArrayNotHasKey('custom_data', $requests[1][0]['exception']);
    }

    public function test_it_prevents_duplicate_exceptions(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.sleep' => 60]);

        $heybug = app(HeyBug::class);
        $exception = new Exception('Same exception');

        // First report should succeed
        $result1 = $heybug->handle($exception);
        $this->assertTrue($result1);

        // Second report should be skipped (sleeping)
        $result2 = $heybug->handle($exception);
        $this->assertFalse($result2);

        Http::assertSentCount(1);
    }

    public function test_it_allows_duplicates_when_sleep_disabled(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.sleep' => 0]);
        Cache::flush();

        $heybug = app(HeyBug::class);
        $exception = new Exception('Same exception');

        $heybug->handle($exception);
        $heybug->handle($exception);

        Http::assertSentCount(2);
    }

    public function test_it_includes_exception_data(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        $heybug = app(HeyBug::class);
        $heybug->handle(new Exception('Test message'));

        Http::assertSent(function ($request) {
            $exception = $request['exception'];

            return isset($exception['environment'])
                && isset($exception['host'])
                && isset($exception['method'])
                && isset($exception['fullUrl'])
                && isset($exception['exception'])
                && isset($exception['error'])
                && isset($exception['line'])
                && isset($exception['file'])
                && isset($exception['class'])
                && isset($exception['file_type'])
                && isset($exception['executor']);
        });
    }
}
