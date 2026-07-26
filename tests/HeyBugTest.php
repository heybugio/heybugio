<?php

namespace HeyBug\Tests;

use Exception;
use HeyBug\HeyBug;
use HeyBug\Tests\Fixtures\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

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

    public function test_it_scrubs_custom_context(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        HeyBug::context(['stripe_token' => 'tok_live_1', 'order_id' => 42]);

        app(HeyBug::class)->handle(new Exception('Charge declined'));

        Http::assertSent(function ($request) {
            $custom = $request['exception']['custom_data'];

            return $custom['stripe_token'] === '[FILTERED]' && $custom['order_id'] === 42;
        });
    }

    public function test_it_sends_the_configured_release(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.release' => 'a1b2c3d']);

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(fn ($request) => $request['exception']['release'] === 'a1b2c3d');
    }

    public function test_it_omits_the_release_when_unset(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(fn ($request) => ! array_key_exists('release', $request['exception']));
    }

    public function test_it_keeps_a_reported_payload_within_the_ceiling(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.max_payload_size' => 4096]);

        HeyBug::context(['cart' => str_repeat('x', 200_000)]);

        app(HeyBug::class)->handle(new Exception('Oversized report'));

        Http::assertSent(function ($request) {
            $exception = $request['exception'];

            $this->assertTrue($exception['custom_data']['_truncated']);
            $this->assertSame('Oversized report', $exception['exception']);
            $this->assertSame(Exception::class, $exception['class']);

            return strlen((string) json_encode($exception)) <= 4096;
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

    public function test_it_skips_subclasses_of_excepted_exceptions(): void
    {
        Http::fake();

        config(['heybug.except' => [LogicException::class]]);

        $heybug = app(HeyBug::class);
        $result = $heybug->handle(new InvalidArgumentException('Child of LogicException'));

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_it_clears_context_when_the_exception_is_skipped(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.except' => [LogicException::class]]);

        HeyBug::context(['order_id' => 123]);

        $heybug = app(HeyBug::class);
        $heybug->handle(new LogicException('Skipped'));

        config(['heybug.except' => []]);
        $heybug->handle(new Exception('Reported'));

        Http::assertSent(function ($request) {
            return ! array_key_exists('custom_data', $request['exception']);
        });
    }

    public function test_it_clears_context_when_the_exception_is_deduplicated(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        $heybug = app(HeyBug::class);
        $exception = new Exception('Same exception');

        $heybug->handle($exception);

        // Suppressed by the sleep window, but the context must not survive it.
        HeyBug::context(['order_id' => 123]);
        $heybug->handle($exception);

        $heybug->handle(new Exception('A different exception'));

        Http::assertSentCount(2);

        $requests = Http::recorded();
        $this->assertArrayNotHasKey('custom_data', $requests[1][0]['exception']);
    }

    public function test_it_clears_context_when_a_queued_job_starts(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        HeyBug::context(['order_id' => 123]);

        event(new JobProcessing('sync', $this->createStub(Job::class)));

        app(HeyBug::class)->handle(new Exception('Thrown by an unrelated job'));

        Http::assertSent(function ($request) {
            return ! array_key_exists('custom_data', $request['exception']);
        });
    }

    public function test_it_does_not_throw_when_reporting_itself_fails(): void
    {
        Http::fake();

        Cache::shouldReceive('has')->andThrow(new RuntimeException('Cache store unavailable'));

        $heybug = app(HeyBug::class);

        $this->assertFalse($heybug->handle(new Exception('Test exception')));
    }

    public function test_it_sends_the_authenticated_user(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        Auth::setUser(new User(['id' => 1, 'name' => 'John', 'email' => 'john@example.com']));

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(function ($request) {
            return $request['user']['id'] === 1
                && $request['user']['email'] === 'john@example.com';
        });
    }

    public function test_it_can_disable_sending_the_user(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.send_user' => false]);

        Auth::setUser(new User(['id' => 1, 'name' => 'John', 'email' => 'john@example.com']));

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(function ($request) {
            return $request['user'] === null;
        });
    }

    public function test_it_can_limit_which_user_attributes_are_sent(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.user_attributes' => ['id']]);

        Auth::setUser(new User(['id' => 1, 'name' => 'John', 'email' => 'john@example.com']));

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(function ($request) {
            return $request['user'] === ['id' => 1];
        });
    }

    public function test_it_never_sends_hidden_user_attributes(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.user_attributes' => ['id', 'email', 'password']]);

        Auth::setUser(new User([
            'id' => 1,
            'email' => 'john@example.com',
            'password' => 'hashed-secret',
        ]));

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(function ($request) {
            return ! array_key_exists('password', $request['user']);
        });
    }

    public function test_it_applies_the_baseline_blacklist_when_the_app_overrides_it(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        // A config file published against an older release, missing patterns
        // the package has added since.
        config(['heybug.blacklist' => ['*legacy*']]);

        $this->app->forgetInstance('heybug');

        $this->post('/', ['password' => 'hunter2', 'legacy' => 'x', 'name' => 'John']);

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(function ($request) {
            $parameters = $request['exception']['storage']['PARAMETERS'];

            return $parameters['password'] === '[FILTERED]'
                && $parameters['legacy'] === '[FILTERED]'
                && $parameters['name'] === 'John';
        });
    }

    public function test_it_can_opt_out_of_the_baseline_blacklist(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        config([
            'heybug.blacklist_defaults' => false,
            'heybug.blacklist' => ['*legacy*'],
        ]);

        $this->app->forgetInstance('heybug');

        $this->post('/', ['password' => 'hunter2', 'legacy' => 'x']);

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(function ($request) {
            $parameters = $request['exception']['storage']['PARAMETERS'];

            return $parameters['password'] === 'hunter2'
                && $parameters['legacy'] === '[FILTERED]';
        });
    }

    public function test_it_sends_a_half_window_of_source_lines(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.lines_count' => 3]);

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(function ($request) {
            // 3 before, the failing line, 3 after.
            return count($request['exception']['executor']) === 7;
        });
    }

    public function test_it_caps_the_source_lines_it_sends(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.lines_count' => 500]);

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(function ($request) {
            return count($request['exception']['executor']) <= 50;
        });
    }

    public function test_it_tolerates_a_negative_lines_count(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.lines_count' => -5]);

        app(HeyBug::class)->handle(new Exception('Test exception'));

        Http::assertSent(function ($request) {
            return count($request['exception']['executor']) === 1;
        });
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

            // method and fullUrl are request-only; in console the origin is
            // the command name instead. See ConsoleContextTest.
            return isset($exception['environment'])
                && isset($exception['host'])
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
