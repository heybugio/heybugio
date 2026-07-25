<?php

namespace HeyBug\Tests;

use Exception;
use HeyBug\HeyBug;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DeferredReportingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('heybug.async', true);
    }

    public function test_it_does_not_send_inline_when_deferred(): void
    {
        Http::fake();

        $this->assertTrue(app(HeyBug::class)->handle(new Exception('Deferred')));

        Http::assertNothingSent();
    }

    public function test_it_sends_on_flush(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        $heybug = app(HeyBug::class);
        $heybug->handle(new Exception('Deferred'));
        $heybug->flush();

        Http::assertSent(function ($request) {
            return $request['exception']['exception'] === 'Deferred';
        });
    }

    public function test_flushing_twice_does_not_send_twice(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        $heybug = app(HeyBug::class);
        $heybug->handle(new Exception('Deferred'));

        $heybug->flush();
        $heybug->flush();

        Http::assertSentCount(1);
    }

    public function test_it_marks_the_dedup_window_when_buffered_not_when_flushed(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        $heybug = app(HeyBug::class);
        $exception = new Exception('Same exception');

        $this->assertTrue($heybug->handle($exception));

        // Suppressed by the marker written at buffer time, before any flush.
        $this->assertFalse($heybug->handle($exception));

        $heybug->flush();

        Http::assertSentCount(1);
    }

    public function test_it_drops_and_reports_overflow(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.buffer_limit' => 2, 'heybug.sleep' => 0]);

        $this->app->forgetInstance('heybug');

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'dropped 2 report(s)'));

        $heybug = app(HeyBug::class);

        foreach (range(1, 4) as $i) {
            $heybug->handle(new Exception("Exception {$i}"));
        }

        $heybug->flush();

        Http::assertSentCount(2);
    }

    public function test_overflow_is_reported_even_when_nothing_was_buffered(): void
    {
        Http::fake();

        config(['heybug.buffer_limit' => 0, 'heybug.sleep' => 0]);

        $this->app->forgetInstance('heybug');

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $heybug = app(HeyBug::class);
        $heybug->handle(new Exception('Dropped'));
        $heybug->flush();

        Http::assertNothingSent();
    }

    public function test_a_dropped_report_is_not_reported_as_accepted(): void
    {
        Http::fake();

        config(['heybug.buffer_limit' => 0, 'heybug.sleep' => 0]);

        $this->app->forgetInstance('heybug');

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->assertFalse(app(HeyBug::class)->handle(new Exception('Dropped')));
    }

    public function test_flushing_survives_a_transport_failure(): void
    {
        Http::fake(function () {
            throw new Exception('Connection refused');
        });

        $heybug = app(HeyBug::class);
        $heybug->handle(new Exception('Deferred'));

        $heybug->flush();

        // The batch is gone rather than requeued, so it cannot double-send.
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        $heybug->flush();

        Http::assertNothingSent();
    }

    public function test_it_abandons_the_rest_of_a_batch_when_the_flush_budget_runs_out(): void
    {
        Http::fake(function () {
            usleep(20_000);

            return Http::response(['ok' => true, 'id' => 'test-id'], 200);
        });

        config(['heybug.flush_timeout' => 0.01, 'heybug.sleep' => 0]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'abandoned 3 report(s)'));

        $heybug = app(HeyBug::class);

        foreach (range(1, 4) as $i) {
            $heybug->handle(new Exception("Exception {$i}"));
        }

        $heybug->flush();

        // The first is always attempted, so a short budget degrades to one
        // report per flush rather than none.
        Http::assertSentCount(1);
    }

    public function test_a_zero_flush_budget_means_no_ceiling(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.flush_timeout' => 0, 'heybug.sleep' => 0]);

        $heybug = app(HeyBug::class);

        foreach (range(1, 3) as $i) {
            $heybug->handle(new Exception("Exception {$i}"));
        }

        $heybug->flush();

        Http::assertSentCount(3);
    }

    public function test_a_batch_inside_the_budget_is_delivered_whole(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        config(['heybug.sleep' => 0]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->never();

        $heybug = app(HeyBug::class);

        foreach (range(1, 3) as $i) {
            $heybug->handle(new Exception("Exception {$i}"));
        }

        $heybug->flush();

        Http::assertSentCount(3);
    }

    public function test_the_job_attempted_boundary_flushes(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        app(HeyBug::class)->handle(new Exception('Thrown inside a job'));

        Http::assertNothingSent();

        event(new JobAttempted('sync', $this->createStub(Job::class)));

        Http::assertSentCount(1);
    }

    public function test_the_looping_boundary_flushes_without_halting_the_worker(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        app(HeyBug::class)->handle(new Exception('Raised between jobs'));

        // The worker dispatches Looping with until(); a non-null return from
        // any listener stops it picking up work.
        $result = $this->app['events']->until(new Looping('sync', 'default', new WorkerOptions));

        $this->assertNull($result);
        Http::assertSentCount(1);
    }

    public function test_the_command_finished_boundary_flushes(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'id' => 'test-id'], 200),
        ]);

        app(HeyBug::class)->handle(new Exception('Thrown in a console command'));

        event(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0));

        Http::assertSentCount(1);
    }
}
